<?php
declare(strict_types=1);

namespace Drift\Logging;

/**
 * 开发期控制台 sink。
 *
 * ★ 走 `error_log()` 而不是 `fwrite(STDERR)`：桌面应用没有控制台，
 *   `STDERR` 常量在 GUI 子系统下可能不存在（与 `Errors` 的中止序列同一判断）。
 * ★ 默认 pattern 不带时间戳：开发期的噪音越少越好，`%l %c: %m` 足够。
  * @api
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

    /**
     * `error_log()` 每条即写，无缓冲可落 —— 但**显式实现**而不是靠"默认空实现"：
     * 将来若改成 `fwrite` 到某个流，这里就是收口点。
     */
    public function flush(): void
    {
    }

    /** 不持有句柄（`error_log()` 自己开合） */
    public function close(): void
    {
    }
}
