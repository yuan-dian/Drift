<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 有人要求关闭窗口（点 X / Alt+F4 / `WindowHost::close()`）。
 *
 * 只在窗口声明了关闭守卫时发出；没守卫的窗口 `WM_CLOSE` 由壳直接销毁。
 * 这是请求不是通知：窗口还在，关不关由 `Application::onCloseRequested()` 决定，
 * 所以不走 `dispatchInput()` 命中链，消费点是 EventLoop。壳内合并重复请求，避免确认框弹多次。
 *
 * @api
 */
final readonly class CloseRequestedEvent implements InputEvent
{
}
