<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\App\DebugSession;
use Drift\Debug\Session\Script;

/**
 * 真窗口的"驱动"：**不推帧**，只回答暂停与单步预算。
 *
 * ## 为什么不是 `Drive`
 *
 * `Drive` 按脚本的 `t` 推帧，那是可复算的；live 窗口的 `t` 来自 `FrameClock` +
 * `Platform::now()`，一旦让脚本给时间，界面按墙钟跑而记录按脚本跑，两份时间线
 * 就漂了。所以帧仍由 `EventLoop` 推，这里只改**推进节奏**（`pause` / `resume`），
 * 这正是 A33 把干预动词单列的原因。
 *
 * ## 预算在帧末扣
 *
 * `resume(frames: 1)`  rebudget 后，第一帧真的跑完才回 `paused` + `step`。
 * 在请求里扣会让"我按了单步，界面还没动，状态已经说停了"。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class LiveDrive implements DrivePort
{
    private ?int $budget = null;

    public function __construct(private readonly Script $script)
    {
    }

    public function script(): Script
    {
        return $this->script;
    }

    public function live(): bool
    {
        return true;
    }

    public function advance(DebugSession $session): void
    {
        // 帧由 `EventLoop` 推；这里空转是**故意**的，不是漏实现。
    }

    public function pause(DebugSession $session): void
    {
        if ($session->canAdvance()) {
            $session->markState('paused', 'requested');
        }
    }

    public function resume(DebugSession $session, ?int $frames = null): void
    {
        if ($frames !== null) {
            $this->budget = \max(1, $frames);
        }
        $session->markState('running');
    }

    /** 一帧跑完后调用；命中预算立刻停，理由与脚本驱动同词（`step`）。 */
    public function afterFrame(DebugSession $session): void
    {
        if ($this->budget === null) {
            return;
        }

        --$this->budget;
        if ($this->budget === 0) {
            $this->budget = null;
            $session->markState('paused', 'step');
        }
    }
}
