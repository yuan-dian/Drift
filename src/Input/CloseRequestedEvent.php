<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 有人要求关闭这个窗口（用户点标题栏 X、Alt+F4、或应用调了 `WindowHost::close()`）。
 *
 * ★ **它只在窗口声明了关闭守卫时才会出现**（`WindowHost::setCloseGuard($h, true)`）：
 *   没声明的窗口，`WM_CLOSE` 由壳直接销毁 —— 那条路径上没有任何应用代码有机会
 *   看见"要关了"，所以也不会发这条事件。这不是省略，是语义："没声明要问" =
 *   "关就是了"。
 *
 * ★ **它是"请求"不是"通知"**：收到之后窗口**还在**，关不关由
 *   `Application::onCloseRequested()` 回答。所以它**不能**走 `dispatchInput()`
 *   的命中链 —— 关闭不是"点在某个控件上"，它没有命中目标，也不该被浮层拦下。
 *   消费点是 `EventLoop`（窗口级生命周期，与 `WakeReason::Quit` 同族）。
 *
 * ★ 载荷为空：今天没有消费者需要知道"是谁要关"（用户点 X 与应用调 `close()`
 *   已经合成同一条路，这个区别在壳侧就消失了）。
 *
 * ★ **合并在壳里**：连点三下 X 只会产生一条事件（队列里已有一条就不再加，
 *   见 `enqueueCloseRequest`）—— 否则确认框会弹三次，而三次问的是同一件事。
 *
 * @api
 */
final readonly class CloseRequestedEvent implements InputEvent
{
}
