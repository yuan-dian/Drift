<?php
declare(strict_types=1);

namespace Drift\Logging;

/**
 * 开发期控制台 sink（`12` §4.4）。
 *
 * ★ 走 `error_log()` 而不是 `fwrite(STDERR)`：桌面应用没有控制台，
 *   `STDERR` 常量在 GUI 子系统下可能不存在（与 `Errors` 的中止序列同一判断）。
 * ★ 默认 pattern 不带时间戳：开发期的噪音越少越好，`%l %c: %m` 足够。
 */
final class StderrSink implements LogSink
{
    private LogFormatter $formatter;

    public function __construct(?LogFormatter $formatter = null)
    {
        $this->formatter = $formatter ?? PatternFormatter::compile('%l %c: %m');
    }

    public function setFormatter(LogFormatter $formatter): void
    {
        $this->formatter = $formatter;
    }

    public function write(LogEntry $entry): void
    {
        error_log($this->formatter->format($entry));
    }
}
