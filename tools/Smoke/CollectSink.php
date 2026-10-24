<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke;

use Drift\Logging\LogEntry;
use Drift\Logging\LogSink;

/**
 * @internal 冒烟夹具：把日志收集成结构而不是字符串。
 *
 * ★ 为什么不用 `RingSink` 断言"没写"：环是**有界覆盖写**的，"写了几条"
 *   与"环里还剩几条"是两个量。要断言门槛 / `disable()` 这类"零写入"，
 *   必须有个不过滤、不丢弃的收口。
 */
final class CollectSink implements LogSink
{
    /** @var list<LogEntry> */
    public array $got = [];

    public function write(LogEntry $entry): void
    {
        $this->got[] = $entry;
    }
}
