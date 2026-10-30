<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 定时器（§5.2 裁决 1）。
 *
 * ★ 它是**帧时钟的物质载体**：`FrameClock`（M4）不是"自己起一个循环"，而是
 *   `timers()->start(ID, 16)` 让平台按 VSync / `WM_TIMER` 唤醒消息泵 ——
 *   框架因此**不自建循环、不空转**（§5.2 裁决 1 的"框架绝不自建循环"）。
 *
 * ★ 也是**轮询的载体**：GUI 仓的滚动同步没有可依赖的通知（编辑框只在用户滚动时
 *   发 `EN_VSCROLL`，预览框什么都不发）⇒ 只能靠快定时器轮询。这类"没有通知只能问"
 *   的场景一律走本端口，不另开通道。
 *
 * @api platform
 */
interface TimerHost
{
    /**
     * 启动（或重启）一个周期定时器。
     *
     * @param int $id 由调用方指定 —— 到点时 `waitEvents()` 会醒来，事件里带的就是这个 id。
     *                 平台不分配 id：**分配权在框架**，否则"哪个定时器响了"要查表。
     * @return bool 平台拒绝（id 冲突 / 系统资源不足）⇒ `false`，调用方必须处理
     */
    public function start(int $id, int $milliseconds): bool;

    /** 停止；**未启动的 id 不是错误**（幂等，避免"忘了判断有没有起过"） */
    public function stop(int $id): void;
}
