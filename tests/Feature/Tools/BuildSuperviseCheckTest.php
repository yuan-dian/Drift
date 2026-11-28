<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Tools;

use PHPUnit\Framework\TestCase;

/**
 * 构建看门狗的行为判据（`tools/build-supervise-check.php`）。
 *
 * ★ 它是**跨进程**的：判的是"进程真从机器上消失"，而不是 `run()` 的返回值。
 *   所以只能真跑一次脚本，不能 mock —— 拿返回值断言等于自己证明自己。
 * ★ 这里只做入口接线（脚本在、能跑、全绿），逐条判据的文案与正反例住脚本里：
 *   改判据要连正反例一起改，那件事只有脚本自己看得见。
 */
final class BuildSuperviseCheckTest extends TestCase
{
    public function testWatchdogKillsStallsAndSparesRealProgress(): void
    {
        $root = dirname(__DIR__, 3);
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, "{$root}/tools/build-supervise-check.php"],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($proc, '判据脚本必须能启动');

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        self::assertSame(0, $code, $out . $err);

        // 三条"外部事实"锚点：没有它们，脚本可能退化成只断言返回值。
        self::assertStringContainsString('静默 ⇒ 进程真的从机器上消失', $out);
        self::assertStringContainsString('停滞 ⇒ 孙进程一起消失（/T 生效）', $out);
        self::assertStringContainsString('反例：不把目录算进展 ⇒ 同一进程被判停滞', $out);

        self::assertMatchesRegularExpression('/--- 0 FAIL \/ \d+ 条判据 ---/', $out);
    }
}
