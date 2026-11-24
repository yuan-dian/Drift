<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Dev\Debug\Discovery;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 发现文件：写者（宿主）与读者（观察者）之间的唯一约定。
 *
 * 判据盯三件事，都不涉及实现细节：
 * - 形状是**数组**，且**没有运行状态**（状态只有一个出口，见 `Discovery` 类注释）；
 * - 只有写者清僵尸，读者读到什么就是什么；
 * - 坏文件与版本不符要**报出来**，不能静默当空 —— 静默会让「宿主没起」与
 *   「文件被写坏」变成同一句话。
 */
final class DiscoveryTest extends SmokeTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \sys_get_temp_dir() . '/drift-discovery-' . \bin2hex(\random_bytes(5));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $file = $this->dir . '/.drift/session.json';
        if (\is_file($file)) {
            @\unlink($file);
        }
        if (\is_dir($this->dir . '/.drift')) {
            @\rmdir($this->dir . '/.drift');
        }
        @\rmdir($this->dir);
        parent::tearDown();
    }

    private function discovery(): Discovery
    {
        return Discovery::inDirectory($this->dir);
    }

    private function file(): string
    {
        return $this->dir . '/.drift/session.json';
    }

    /** @param list<array<string, mixed>> $sessions */
    private function seed(array $sessions, int $protocol = Discovery::PROTOCOL): void
    {
        if (!\is_dir($this->dir . '/.drift')) {
            \mkdir($this->dir . '/.drift', 0700, true);
        }
        \file_put_contents($this->file(), \json_encode(['protocol' => $protocol, 'sessions' => $sessions]));
    }

    /** @return array<string, mixed> */
    private function entry(string $id, int $pid, int $port): array
    {
        return ['id' => $id, 'pid' => $pid, 'port' => $port, 'token' => 't', 'target' => 'table', 'startedAt' => ''];
    }

    public function testRegisterWritesAnArrayWithoutRuntimeState(): void
    {
        $this->discovery()->register('table', \str_repeat('a', 32), 51234);

        $data = \json_decode((string) \file_get_contents($this->file()), true, 512, \JSON_THROW_ON_ERROR);

        check('发现文件带自己的协议号', $data['protocol'] === Discovery::PROTOCOL, 'protocol=' . \json_encode($data['protocol'] ?? null));
        check('sessions 是数组，一个也照旧', \is_array($data['sessions']) && \count($data['sessions']) === 1, \gettype($data['sessions'] ?? null));
        $entry = \is_array($data['sessions'][0] ?? null) ? $data['sessions'][0] : [];
        check(
            '字段清单就是「怎么连上」',
            \array_keys($entry) === ['id', 'pid', 'port', 'token', 'target', 'startedAt'],
            \implode(',', \array_keys($entry)),
        );
        check('运行状态不在文件里', !\array_key_exists('state', $entry));
        check('目标页面记下来了', ($entry['target'] ?? null) === 'table', (string) ($entry['target'] ?? ''));
        check('端口记下来了', ($entry['port'] ?? null) === 51234, (string) ($entry['port'] ?? ''));
        check('pid 是本进程', ($entry['pid'] ?? null) === \getmypid(), (string) ($entry['pid'] ?? ''));
        check(
            '时间戳是 ISO 8601',
            \preg_match('/^\d{4}-\d\d-\d\dT/', (string) ($entry['startedAt'] ?? '')) === 1,
            (string) ($entry['startedAt'] ?? ''),
        );
    }

    public function testSecondRegisterAppendsInsteadOfReplacing(): void
    {
        $first = $this->discovery()->register('table', 'tok-a', 1111);
        $second = $this->discovery()->register('form', 'tok-b', 2222);

        $sessions = $this->discovery()->sessions();
        check('两条都在（不是后一条覆盖前一条）', \count($sessions) === 2, 'count=' . \count($sessions));
        check('顺序即登记顺序', \array_column($sessions, 'id') === [$first, $second], \implode(',', \array_column($sessions, 'id')));
        check('端口各自对得上', \array_column($sessions, 'port') === [1111, 2222], \implode(',', \array_column($sessions, 'port')));
    }

    public function testUnregisterRemovesTheEntryThenTheFile(): void
    {
        $a = $this->discovery()->register('table', 'tok-a', 1111);
        $b = $this->discovery()->register('form', 'tok-b', 2222);

        $this->discovery()->unregister($a);
        check('只删掉自己那条', \array_column($this->discovery()->sessions(), 'id') === [$b], \implode(',', \array_column($this->discovery()->sessions(), 'id')));

        $this->discovery()->unregister($b);
        check('空数组没有意义 —— 留着空文件只会让下一个观察者多问一次', !\is_file($this->file()));
        check('文件没了读成空，而不是报错', $this->discovery()->sessions() === []);
    }

    public function testRegisterSweepsEntriesWhoseProcessIsGone(): void
    {
        // pid=0 一定判「不在了」，且不用真去起一个进程再杀掉它。
        $this->seed([$this->entry('dead', 0, 9999)]);

        $live = $this->discovery()->register('form', 'tok', 2222);

        check('写者顺手清掉僵尸条目', \array_column($this->discovery()->sessions(), 'id') === [$live], \implode(',', \array_column($this->discovery()->sessions(), 'id')));
    }

    public function testAliveKnowsItselfAndRejectsZero(): void
    {
        check('本进程一定活着 —— 这条能证伪探测本身', Discovery::alive((int) \getmypid()) === true);
        check('0 不是进程号，别拿它当「查不到」', Discovery::alive(0) === false);
        check('负数同理', Discovery::alive(-1) === false);
    }

    public function testLiveFiltersGoneEntriesButSessionsKeepsThem(): void
    {
        $this->seed([$this->entry('mine', (int) \getmypid(), 1), $this->entry('gone', 0, 2)]);

        check('读者不删东西，只读', \count($this->discovery()->sessions()) === 2);
        check('live 只留还活着的', \array_column($this->discovery()->live(), 'id') === ['mine'], \implode(',', \array_column($this->discovery()->live(), 'id')));
    }

    public function testProtocolMismatchIsReported(): void
    {
        $this->seed([], 99);

        try {
            $this->discovery()->sessions();
            check('版本不符要报错', false, '居然读成功了');
        } catch (\RuntimeException $e) {
            check('版本不符要报错', \str_contains($e->getMessage(), '协议 99'), $e->getMessage());
        }
    }

    public function testCorruptFileIsReported(): void
    {
        if (!\is_dir($this->dir . '/.drift')) {
            \mkdir($this->dir . '/.drift', 0700, true);
        }
        \file_put_contents($this->file(), 'not json at all');

        try {
            $this->discovery()->sessions();
            check('读不懂要报出来', false, '居然读成功了');
        } catch (\RuntimeException $e) {
            check('读不懂要报出来', \str_contains($e->getMessage(), '读不懂'), $e->getMessage());
        }
    }

    public function testEmptyFileReadsAsEmptyRatherThanCorrupt(): void
    {
        if (!\is_dir($this->dir . '/.drift')) {
            \mkdir($this->dir . '/.drift', 0700, true);
        }
        \file_put_contents($this->file(), '');

        // 写到一半被杀死会留下 0 字节文件。那和「没有会话」是同一件事，
        // 报成「文件坏了」会让用户去查一个不存在的问题。
        check('0 字节文件等同于没有会话', $this->discovery()->sessions() === []);
    }

    public function testDiagnoseSeparatesGoneFromStillAlive(): void
    {
        $d = $this->discovery();

        $gone = $d->diagnose($this->entry('x', 0, 51234));
        check('已退出就直说已退出', \str_contains($gone, '宿主已退出'), $gone);
        check('并带上 pid（找它要重启谁）', \str_contains($gone, 'pid 0'), $gone);

        $alive = $d->diagnose($this->entry('y', (int) \getmypid(), 51234));
        check('还活着时不说「已退出」', !\str_contains($alive, '宿主已退出'), $alive);
        check('还活着时要报端口（下一步动作不同）', \str_contains($alive, '51234'), $alive);
    }
}
