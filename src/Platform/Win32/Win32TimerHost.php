<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Platform\TimerHost;

use function drift_win_kill_timer;
use function drift_win_set_timer;

/**
 * Win32 定时器端口（§5.2 裁决 1）。
 *
 * ★ 定时器挂在**窗口**上（`SetTimer(hwnd, ...)`）⇒ 它需要窗口句柄，而句柄由
 *   `Win32WindowHost` 持有 —— 所以本类收的是 host，不是裸 int。这样"还没建窗就起
 *   定时器"会得到 `false`（可处理的失败），而不是把 0 当句柄传给系统。
 *
 * ★ **用 `liveHandle()` 而不是 `handle()`**（2026-10-30，M1-b）：等消息的闹钟是
 *   进程级资源（一次 `GetMessageW` 收所有窗口的消息），而"最后建的窗"可能已经
 *   关掉了 —— 那时 `SetTimer` 失败 ⇒ 等待退化成无限阻塞 ⇒ 剩下的窗口动画停摆。
 *
 * ★ 到点时泵发一条 `timer` 事件，`id` 是**调用方给的那个**（`SetTimer` 的
 *   `nIDEvent`）⇒ 框架不需要 id 映射表。
 *
 * @internal 平台实现；`Platform` 接口上**不再暴露** `timers()`（2026-10-30，M1-b：
 *   外部消费者在 N2 之后归零，应用要定时器走 `Application::after()`）
 */
final class Win32TimerHost implements TimerHost
{
    public function __construct(
        private readonly Win32WindowHost $windows,
    ) {
    }

    public function start(int $id, int $milliseconds): bool
    {
        $handle = $this->windows->liveHandle();
        if ($handle === null) {
            return false;
        }

        return drift_win_set_timer($handle, $id, $milliseconds);
    }

    public function stop(int $id): void
    {
        $handle = $this->windows->liveHandle();
        if ($handle === null) {
            return;
        }

        drift_win_kill_timer($handle, $id);
    }
}
