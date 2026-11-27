<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\Host\Discovery;
use PHPUnit\Framework\TestCase;

/**
 * A59 跨窗口视图的消费者判据：两个真 socket 会话 + CLI 出口子进程真跑。
 *
 * 纯函数判据证明投影不 join、不吞不可达；这一组证明出口真的连上了两个进程，
 * 而不是只在内存里拼了两行——上一轮 A58 的教训是"漏传一根线，单测全绿而真
 * 运行回 not_applicable"。
 */
final class TargetsTest extends TestCase
{
    private string $dir = '';

    /** @var list<resource> */
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \sys_get_temp_dir() . '/drift-targets-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        @\file_put_contents($this->dir . '/stop', '1');
        // 给子进程一轮轮询的机会自己注销：直接 terminate 会让条目留在发现文件里。
        \usleep(300_000);
        foreach ($this->processes as $process) {
            if (\is_resource($process)) {
                @\proc_terminate($process);
                @\proc_close($process);
            }
        }
        $this->processes = [];
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir . '/.drift');
        @\rmdir($this->dir);
        parent::tearDown();
    }

    private function startHost(): void
    {
        $root = \dirname(__DIR__, 3);
        $devNull = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = \proc_open(
            [\PHP_BINARY, $root . '/tests/Support/live-attach-host.php', $this->dir],
            [
                0 => ['file', $devNull, 'r'],
                1 => ['file', $devNull, 'w'],
                2 => ['file', $devNull, 'w'],
            ],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        self::assertTrue(\is_resource($process), 'live 宿主起不来');
        $this->processes[] = $process;
    }

    /**
     * 等 $n 个宿主就绪。逐个启动、逐个等：两个进程同时首次 register 时，
     * 后者的僵尸清理用 tasklist 判活，可能把刚起、还没出现在列表里的前者
     * 当成死的删掉——那会表现为"随机少一个会话"，而与被测能力无关。
     *
     * @return list<array{id: string, pid: int, port: int, target: string}>
     */
    private function waitFor(int $n): array
    {
        $deadline = \microtime(true) + 20.0;
        while (\microtime(true) < $deadline) {
            $live = Discovery::inDirectory($this->dir)->live();
            if (\count($live) >= $n) {
                return $live;
            }
            \usleep(50_000);
        }
        $discovery = Discovery::inDirectory($this->dir);
        self::fail("{$n} 个宿主没有就绪：" . \json_encode([
            'sessions' => $discovery->sessions(),
            'live' => \count($discovery->live()),
            'stop' => \is_file($this->dir . '/stop'),
        ], \JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function runTargets(): array
    {
        $root = \dirname(__DIR__, 3);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = \proc_open(
            [\PHP_BINARY, $root . '/devtools/bin/debug-targets.php', '--json'],
            $descriptors,
            $pipes,
            $this->dir,
        );
        self::assertTrue(\is_resource($process));
        \fclose($pipes[0]);
        $out = \stream_get_contents($pipes[1]);
        $err = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($process);
        self::assertSame(0, $code, (string) $err);

        $answer = \json_decode((string) $out, true);
        self::assertIsArray($answer, (string) $out);

        return $answer;
    }

    public function testTwoLiveSessionsAreListedSideBySide(): void
    {
        $this->startHost();
        $this->waitFor(1);
        $this->startHost();
        $live = $this->waitFor(2);

        $answer = $this->runTargets();

        self::assertSame(2, $answer['summary']['sessions']);
        self::assertSame(2, $answer['summary']['connected']);
        self::assertSame(0, $answer['summary']['unreachable']);
        self::assertCount(2, $answer['targets']);
        $ids = \array_column($answer['targets'], 'id');
        self::assertContains($live[0]['id'], $ids);
        self::assertContains($live[1]['id'], $ids);
        foreach ($answer['targets'] as $row) {
            self::assertSame('ok', $row['state']);
            self::assertGreaterThan(0, $row['frames']);
            self::assertNotSame('', $row['digest']);
        }
    }

    public function testUnreachableSessionDoesNotEraseTheHealthyOne(): void
    {
        $this->startHost();
        $discovery = Discovery::inDirectory($this->dir);

        // `live()` 按 pid 判活：被强杀的条目会直接消失，不进视图。真正要在
        // 视图里说的是另一种——进程还活着，但端口上没人应答（刚起来还没监听、
        // 端口被占、token 换了）。用当前测试进程作 pid 造出这一形状。
        $ghostId = $discovery->register('ghost', 'stale-token', 1, (int) \getmypid());
        $deadline = \microtime(true) + 10.0;
        while (\microtime(true) < $deadline && \count($discovery->live()) < 2) {
            \usleep(50_000);
        }
        self::assertCount(2, $discovery->live());

        $answer = $this->runTargets();

        self::assertSame(2, $answer['summary']['sessions']);
        self::assertSame(1, $answer['summary']['connected']);
        self::assertSame(1, $answer['summary']['unreachable']);
        $byId = [];
        foreach ($answer['targets'] as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertSame('unreachable', $byId[$ghostId]['state']);
        self::assertStringContainsString('连不上', (string) $byId[$ghostId]['error']);

        $healthy = null;
        foreach ($answer['targets'] as $row) {
            if ($row['id'] !== $ghostId) {
                $healthy = $row;
            }
        }
        self::assertNotNull($healthy);
        self::assertSame('ok', $healthy['state']);
        self::assertGreaterThan(0, $healthy['frames']);
    }
}
