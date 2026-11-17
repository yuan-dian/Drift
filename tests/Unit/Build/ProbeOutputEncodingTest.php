<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Build;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * CI runner 的 Python 可能以 cp1252 开 stdout，而判据名本身是中文。
 * 探针已经量到窗口却在打印时抛 UnicodeEncodeError，等于把日志编码误报成产品失败。
 */
final class ProbeOutputEncodingTest extends SmokeTestCase
{
    public function testRunPinsUtf8Output(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/probe_check.py');
        check(
            'probe_check · run() 入口把 stdout/stderr 钉成 UTF-8',
            str_contains($src, 'def _utf8_output()')
                && preg_match('/skipped = None\s*\n\s*_utf8_output\(\)/', $src) === 1
        );
        $this->flushChecks();
    }
}
