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
        self::assertSame(
            1,
            preg_match('/\$elegant\s*=\s*\$graceful\s*\?\s*gracefulStop\(/', $this->source),
            'stop() 必须走 gracefulStop，不能直接杀',
        );
        self::assertSame(
            1,
            preg_match('/if\s*\(!\$elegant\)\s*\{\s*\$signal\(true\);/s', $this->source),
            '强杀只能在优雅失败后的兜底分支',
        );
        // 优雅 = 不带 /F，强杀 = 带 /F；两条都从同一个 $signal 出发，只靠参数区分。
        self::assertStringContainsString(
            "\$force ? ' /F' : ''",
            $this->source,
            '优雅 / 强杀必须是同一个信号动作的两种强度，不能各写一套',
        );
    }

    /**
     * 停进程必须按**本次启动的 PID**，不能按映像名。
     *
     * ★ `/IM dev.exe` 会连带杀掉别的终端里那个实例，它的 `finally` 不跑、业务状态直接丢
     *   （2026-11-28 评审 P1-1）。`/IM` 只能作为"认不出 PID"的降级路径，且必须警告。
     */
    public function testStopTargetsThisInstancePidNotImageName(): void
    {
        self::assertStringContainsString(
            "exec('taskkill /PID ' . \$pid",
            $this->source,
            '停止要按 PID 定位本次起的进程',
        );
        self::assertSame(
            1,
            preg_match('/if\s*\(\$pid\s*!==\s*null\)\s*\{/', $this->source),
            'PID 优先，按映像名只能是 else 分支',
        );
        self::assertStringContainsString(
            '退回按映像名停进程',
            $this->source,
            '降级到 /IM 时必须留下警告，否则误杀别的实例是静默的',
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
            'stop($process, $pipes, !$hardRestart, $devPid)',
            $this->source,
            '重启与退出两条路径都要尊重硬重启开关，并带上本次 PID',
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
