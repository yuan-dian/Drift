<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Tools;

use PHPUnit\Framework\TestCase;

/**
 * 环境块预算判据（`tools/env-budget-check.php`）。
 *
 * ★ 为什么是 feature 而不是 unit：它要真起一个 PHP 子进程，看 curl 扩展能不能
 *   加载 —— 那是跨进程的外部事实。单测里 mock 掉 proc_open 就等于什么都没验。
 * ★ 这里只做入口接线（脚本在、能跑、全绿）；逐条判据的正反例住脚本里。
 */
final class EnvBudgetCheckTest extends TestCase
{
    public function testEnvironmentBlockStaysUnderWindowsLimit(): void
    {
        $root = dirname(__DIR__, 3);
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, "{$root}/tools/env-budget-check.php"],
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

        if (str_contains($out, 'SKIP')) {
            self::assertStringContainsString('SKIP 无构建环境', $out);

            return;
        }

        // 锚点：根因判据与被裁项的反例，缺了它可能退化成只断言返回值。
        self::assertStringContainsString('20KB 宿主前缀变量被剔除', $out);
        self::assertStringContainsString('非宿主前缀的超大变量走兜底被剔除', $out);
        self::assertStringContainsString('裁剪后的环境能正常加载 curl', $out);
        self::assertStringContainsString('全部通过', $out);
    }
}
