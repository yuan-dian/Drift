<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Tools;

use PHPUnit\Framework\TestCase;

/**
 * dev 优雅重启的**生产接线**对账。
 *
 * 行为判据（跨进程 finally / 强杀对照 / 超时有界）住
 * `tools/drift-dev.php --selftest`；这里只钉「接线没被改回强杀」这件
 * 行为判据看不见的事，并把那份自检接进 phpunit 套件。
 */
final class HotReloadStopTest extends TestCase
{
    private string $source = '';

    protected function setUp(): void
    {
        $text = file_get_contents(dirname(__DIR__, 3) . '/tools/drift-dev.php');
        self::assertIsString($text, 'dev 脚本必须可读');
        $this->source = $text;
    }

    public function testStopRequestsGracefulCloseBeforeForcing(): void
    {
        $graceful = strpos($this->source, 'taskkill /IM dev.exe 2>&1');
        $forced = strpos($this->source, 'taskkill /IM dev.exe /F 2>&1');

        self::assertNotFalse($graceful, '必须有不带 /F 的 WM_CLOSE 请求');
        self::assertNotFalse($forced, '必须有强杀兜底');
        self::assertLessThan($forced, $graceful, '优雅请求必须先于强杀');
        self::assertSame(
            1,
            preg_match('/\$elegant\s*=\s*\$graceful\s*\?\s*gracefulStop\(/', $this->source),
            'stop() 必须走 gracefulStop，不能直接杀',
        );
        self::assertSame(
            1,
            preg_match('/if\s*\(!\$elegant\)\s*\{\s*\$forceKill\(\);/s', $this->source),
            '强杀只能在优雅失败后的兜底分支',
        );
    }

    public function testHardRestartControlsTheGracefulBranch(): void
    {
        self::assertStringContainsString('--hard-restart', $this->source);
        self::assertSame(
            1,
            preg_match('/\$hardRestart\s*=\s*in_array\(\'--hard-restart\'/', $this->source),
            '--hard-restart 必须被解析，不能只是文档里的一行字',
        );
        self::assertStringContainsString(
            'stop($process, $pipes, !$hardRestart)',
            $this->source,
            '重启与退出两条路径都要尊重硬重启开关',
        );
    }

    public function testStaleNoStoreClaimIsGone(): void
    {
        self::assertStringNotContainsString(
            '无 Store/Cell',
            $this->source,
            'P0-3 之后这句已失效，留着会误导下一轮',
        );
        self::assertStringContainsString('RestorationStore 构造即恢复', $this->source);
    }

    /** 行为判据的入口：真跑一次 CLI 自检，不与实现共享内存。 */
    public function testDevSelftestCoversGracefulContract(): void
    {
        $root = dirname(__DIR__, 3);
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, "{$root}/tools/drift-dev.php", '--selftest'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($proc, '自检必须能启动');

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('--- 0 FAIL /', $out);
        self::assertStringContainsString('优雅关闭后 finally 真的写盘', $out);
        self::assertStringContainsString('强杀不跑 finally（对照组）', $out);
        self::assertStringContainsString('超时有界', $out);
    }
}
