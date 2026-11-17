<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Build;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 「非白占比」只区分画了与全白，不是内容多少。
 * hello 白底一行字实测 0.2%，曾被 probe-window 的 1% 硬阈值误判成空白窗。
 */
final class ProbeContentTest extends SmokeTestCase
{
    public function testWindowProbeUsesSharedContentRule(): void
    {
        $root = dirname(__DIR__, 3);
        $src = (string) file_get_contents($root . '/tools/probe-window.py');
        check(
            'probe-window 从 probe_pixels 取内容判据，不再自带比例阈值',
            str_contains($src, 'from probe_pixels import content_stats')
                && str_contains($src, 'stats["ok"]')
                && !str_contains($src, 'total * 0.01')
        );
        $two = (string) file_get_contents($root . '/tools/probe-two-windows.py');
        check(
            '双窗探针也走同一判据（不各自留一份比例阈值）',
            str_contains($two, 'content_stats') && !str_contains($two, 'ratio > 0.0005')
        );
        $this->flushChecks();
    }

    public function testSharedRuleKnowsBlankSolidAndMinimalApp(): void
    {
        $root = dirname(__DIR__, 3);
        $py = getenv('PYTHON_BIN') ?: 'python';
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg($root . '/tools/probe_pixels.py') . ' --selftest 2>&1';
        exec($cmd, $out, $rc);
        check(
            'probe_pixels 自检认得白纸 / 纯色块 / hello 的 0.2% 文字',
            $rc === 0,
            implode("\n", array_slice($out, -5))
        );
        $this->flushChecks();
    }
}
