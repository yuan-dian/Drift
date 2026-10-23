<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 窗口失活（`WM_ACTIVATE` 且 `LOWORD(wParam) == WA_INACTIVE`）—— 用户切走了。
 *
 * 触发场景：Alt+Tab、点别的应用、任务管理器/开始菜单抢了前台、系统弹窗抢了前台。
 *
 * ★ **为什么需要它**：指针手势的全部收口信号都来自指针自己（`up` / `leave`），
 *   而"拖到一半 Alt+Tab 出去"这两个都不会来 —— 系统既不再发 `WM_MOUSEMOVE`
 *   （指针没动），也不会发 `WM_LBUTTONUP`（用户在别处松手）。没有这条事件，
 *   `$captureNode` / `$gestureArena` / `$drag` / `$textSelect` / `$thumbDrag`
 *   会**全部卡在拖动中**，回到窗口后现象是"某个控件永久只收它的事件"或
 *   "拖到一半不动了"。
 *
 * ★ **载荷为空**：没有消费者需要区分"被谁抢走的前台"。按 A15（没有消费者
 *   不预先发明线上语义），壳只在 `WA_INACTIVE` 时发这一条，**不**发对称的
 *   "重新激活" —— 今天没有任何东西需要在激活时做。
 *
 * ★ **不关浮层**：失焦 ≠ 放弃（W3 裁决，`06` §10「关闭四来源」里明确**没有**
 *   窗口失焦）。用户可能只是去别处查一眼再回来，菜单/模态该留着。
 *   ⇒ 本事件只收口**进行中的手势**，不动 `OverlayStack`。
 *
 * @api
 */
final readonly class WindowDeactivatedEvent implements InputEvent
{
}
