<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * **异步泵片的宿主要口**（S1 第 9 步）：`PumpHost` 经这 3 个口子摸应用。
 *
 * ```text
 * assertUiThread()     —— 三个泵 + 定时器都断言 UI 线程（响亮失败，非静默）
 * lastTimestampMs()    —— after() 的计时基准之一（上一帧时间戳）
 * lastInputMs()        —— after() 的计时基准之二（最近一次输入时刻）
 * ```
 *
 * ★ **为什么时间基准走 host 口子**（不把 `$lastTimestampMs` 搬进本片）：
 *   `$lastTimestampMs` / `$lastInputMs` 是**帧与输入的时序状态** —— `frame()`
 *   写前者、`dispatchInput()` 写后者，两者都住 Application（帧编排 = 组合根
 *   职责，见 §4i）。`after()` 只是**消费**它们做计时基准 ⇒ 片不持副本、每次
 *   现读（同 `InputHost` / `OverlayHost` 的 getter 口径：构造期传副本会陈旧）。
 * ★ **第四口子 `taskHost()` 已移除**（M2，2026-10-30）：端口是**应用级资源**，
 *   归 `AppScope`，由 `attach()` 直接递进来。留在 host 口子上就等于允许
 *   "每个窗口问出自己的那个" —— 那正是用户应用踩过的"两套队列"。
 *
 * @internal S1 的拆分片接口，同 `TextEditingHost` / `InputHost` / `OverlayHost` —— 不是应用契约
 */
interface PumpHostHost
{
    public function assertUiThread(string $where): void;

    public function lastTimestampMs(): float;

    public function lastInputMs(): float;
}
