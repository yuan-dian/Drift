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
 *
 * ★ **`flush()` / `close()` 是接口的一部分**（2026-10-24，P1）：
 *   早落地比晚落地便宜 —— 现在只有 3 个实现（`RingSink` / `StderrSink` / 测试的
 *   `CollectSink`），全在仓内；等应用真去写 `SentrySink` 了再加就是破坏它的代码。
 *   不用"可选接口"绕：一个 sink 若真有缓冲，"能不能收口"就不该是可选的。
  * @api
*/
interface LogSink
{
    public function write(LogEntry $entry): void;

    /**
     * 把仍在缓冲里的部分落盘 / 送出。无缓冲的 sink 空实现即可。
     *
     * ★ **绝不抛**（同 `write()`）：收口失败不能让业务挂掉。
     */
    public function flush(): void;

    /**
     * 释放资源（关文件句柄等）。调用后 sink 不再被使用；无资源的空实现即可。
     *
     * ★ 幂等：重复调用应当无害（退出路径可能走多次）。
     */
    public function close(): void;
}
