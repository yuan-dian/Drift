<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 无障碍客户端要求**激活**一个语义节点（UIA `IInvokeProvider::Invoke()`）。
 *
 * 触发场景：读屏软件（NVDA / 讲述人）在按钮上按空格、自动化脚本调 `InvokePattern`、
 * Windows 的语音控制说"点击 新建用户"。
 *
 * ★★ **为什么它是一条输入事件，而不是"壳直接回调应用"**：
 *   ① `Invoke()` 跑在 COM 的 RPC 线程上（跨进程编组），**不能**在那儿重入 PHP
 *      —— 实测会让进程静默消失（无异常、无日志，客户端只看到 `ElementNotAvailable`）。
 *   ② 就算改到窗口线程的消息派发里进 PHP，也还有第二个坑：消息泵
 *      （`php_drift_win_pump()`）的契约是"一次调用交一条事件"，在派发里跑完 PHP
 *      就没人保证**下一帧会跑** —— 真机现象是"命令确实执行了、状态也变了、
 *      画面一动不动"（要等下一条用户输入才刷出来）。
 *   ③ 走输入队列则两件事同时成立：**壳里零 PHP 调用**，且事件交到组合根的
 *      `while ($event = $input->poll())` 之后，紧接着就是 `FrameClock::tick()`
 *      —— 激活引起的那次重建**同一轮循环**就落盘。
 *
 * ★ 与 `ThemeChangedEvent` / `WindowDeactivatedEvent` 同构（同为"平台信号 ⇒
 *   框架语义"），区别只在于它**带载荷**（节点索引）。
 *
 * ★ **激活语义不在本事件里**：`Application::dispatchInput()` 收到它之后只做一件事
 *   —— 在节点中心派发一次合成 tap，把"点哪个控件"交回既有的输入路由。于是
 *   "读屏点"与"鼠标点"是同一条路，不会各写一份然后漂移。
 *
 * ★ 索引口径见 `Application::invokeSemanticsNode()`：是推给平台的那份语义快照的
 *   前序下标。越界 / 零面积 ⇒ 消费方返回 `false`，壳据此回
 *   `UIA_E_ELEMENTNOTAVAILABLE`（不能假装成功）。
 *
 * @api
 */
final readonly class SemanticsInvokeEvent implements InputEvent
{
    /**
     * @param int $index 语义节点索引（`SemanticsTree::toArrays()['nodes']` 的下标）
     */
    public function __construct(
        public int $index,
    ) {
    }
}
