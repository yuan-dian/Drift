<?php
declare(strict_types=1);

namespace Drift\Logging;

/**
 * ③ 层：日志的去向（`12` §4.4 / §4.7）。
 *
 * ★ `write()` 收的是 `LogEntry`（结构）**不是字符串**：格式化归 `LogFormatter`。
 *   自定义 sink 因此可以任意序列化 —— JSON、二进制、直接转发对象给 Sentry。
 * ★ **不抛异常是硬契约**：`Log` 在每处 sink 调用上都有 catch（§4.5），
 *   但 sink 也不该把"写失败"当控制流 —— 日志失败不得让业务挂掉。
 */
interface LogSink
{
    public function write(LogEntry $entry): void;
}
