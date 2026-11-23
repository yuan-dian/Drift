<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 窗口失活（`WA_INACTIVE`）—— 用户切走了。
 *
 * 拖到一半 Alt+Tab 时 `up` / `leave` 都不会来，没有这条事件，指针手势、拖拽、
 * 文本选择等会卡住。载荷为空；不关浮层，只收口进行中的手势。
 * 不发对称的"重新激活"事件：今天没有消费者（A15）。
 *
 * @api
 */
final readonly class WindowDeactivatedEvent implements InputEvent
{
}
