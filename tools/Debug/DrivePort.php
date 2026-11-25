<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\App\DebugSession;
use Drift\Debug\Session\Script;

/**
 * 宿主的"谁在推帧"端口：脚本自走与真窗口 live 各一份实现。
 *
 * ## 为什么是接口而不是让 Host 判两种宿主
 *
 * `Host` 只问四件事：开局脚本、空转时推一步、暂停、放行。它在每一处都 `if ($live)`
 * 就会长出两套状态机，而 `pause` / `resume` 的回执又要与 `state` 查询同形 ——
 * 那种分叉最终表现为"按钮回了 paused，帧照涨"。接口把差异收在两个实现里，
 * `Host` 的消息分派保持一条路径（A57）。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
interface DrivePort
{
    /** 开局骨架；自走宿主用它推帧，live 宿主只用它报 target / viewport。 */
    public function script(): Script;

    /** 真窗口驱动:`t` 来自墙钟 ⇒ 可观察但不可重放，回执必须如实说。 */
    public function live(): bool;

    /** 没有消息时推进一步。live 宿主是空实现：帧由 `EventLoop` 推。 */
    public function advance(DebugSession $session): void;

    public function pause(DebugSession $session): void;

    /** `$frames` 给了就只放行这么多帧（`1` = 单步）。 */
    public function resume(DebugSession $session, ?int $frames = null): void;
}
