<?php
declare(strict_types=1);

namespace Drift\Input;

use function abs;

/**
 * 点击链（"这一下是第几次点击"）—— **只有状态，没有时钟**。
 *
 * 时间由调用方给（壳的 `Platform::now()` 经 `Application::dispatchInput()` 传进来），
 * 本类只做一件事：把连续的按下串成链，告诉消费方"这是第几次"。
 *
 * ## 为什么不在壳里数（Win32 的 `CS_DBLCLKS` 那条路）
 *
 * 系统确实会自己判双击（`WM_LBUTTONDBLCLK`），但那条路把**判据搬到 C++**：
 * 间隔、距离、按键、修饰键四条规则就再也测不到了（smoke 驱不动壳）。而它们
 * 恰恰是最容易"改一行就静默坏掉"的那类规则 —— 双击进编辑、双击选中一个词、
 * 三击选中一段，全都挂在上面。
 * ⇒ 判据留在 PHP，时间由壳注入。这样"间隔 / 距离 / 按键 / 修饰键"四条规则
 *   在 smoke 里可以用**显式时间戳**确定性驱动，一秒不睡。
 *
 * ## 四条规则（与 Windows 的语义对齐）
 *
 * | 规则 | 判据 | 断了会怎样 |
 * |---|---|---|
 * | 间隔 | `now - 上次 <= intervalMs` | 慢速双击被当成两次单击（编辑器反复开关）|
 * | 距离 | `|dx|,|dy| <= slopPx` | 手抖几像素就断链（真机上极常见）|
 * | 按键 | 同一个 `button` | 右键链与左键链互相污染 |
 * | 修饰键 | `ctrl/alt/shift` 全同 | **Ctrl+点两次**被当成双击 ⇒ 多选时突然进编辑 |
 *
 * ★ 只数**按下**（`Down`）：点击的语义在按下那一刻就定了（同"按下即选"的既有
 *   手感）。`Move` / `Up` 原样返回（不标注、不改链）；`Leave` **断链**（指针离开
 *   客户区后回来不算连击 —— Windows 同款）。
 * ★ 时间**倒退**（校时 / 注入的假时钟）也断链：`now < 上次` 时不参与连击判定，
 *   否则"负间隔恒小于阈值"会把任意两次点击串成连击。
 *
 * @internal 实现细节；消费方是 `Application::dispatchInput()`（标注 `PointerEvent`）
 */
final class TapTracker
{
    /** 连击窗口（毫秒）。500 与 Windows 的 `GetDoubleClickTime()` 默认值一致 */
    public const float DEFAULT_INTERVAL_MS = 500.0;

    /**
     * 连击允许的位移（**逻辑**像素，单轴）。
     *
     * 4 与 Windows 的 `SM_CXDOUBLECLK` 默认值一致（那是**物理**像素；逻辑像素在
     * 高 DPI 下更宽松一点点 —— 对"手抖"这个真实问题来说，宽松是对的）。
     */
    public const float DEFAULT_SLOP_PX = 4.0;

    private ?float $lastMs = null;

    private float $lastX = 0.0;

    private float $lastY = 0.0;

    private int $lastButton = -1;

    private bool $lastCtrl = false;

    private bool $lastAlt = false;

    private bool $lastShift = false;

    private int $count = 0;

    public function __construct(
        private readonly float $intervalMs = self::DEFAULT_INTERVAL_MS,
        private readonly float $slopPx = self::DEFAULT_SLOP_PX,
    ) {
    }

    /**
     * 标注一条指针事件（返回**新实例**：事件是值，标注也是值）。
     *
     * `Down` ⇒ 推进链并带回 `clickCount`；其它相位与 `Leave`（顺带断链）原样返回。
     */
    public function annotate(PointerEvent $event, float $nowMs): PointerEvent
    {
        if ($event->isLeave()) {
            $this->reset();

            return $event;
        }

        if (!$event->isDown()) {
            return $event;
        }

        $this->count = $this->chains($event, $nowMs) ? $this->count + 1 : 1;

        $this->lastMs = $nowMs;
        $this->lastX = $event->x;
        $this->lastY = $event->y;
        $this->lastButton = $event->button;
        $this->lastCtrl = $event->ctrl;
        $this->lastAlt = $event->alt;
        $this->lastShift = $event->shift;

        return $event->withClickCount($this->count);
    }

    /** 当前链上的点击数（诊断 / 断言用；0 = 还没按过） */
    public function count(): int
    {
        return $this->count;
    }

    /** 断链（指针离开 / 窗口失活 / 手势取消） */
    public function reset(): void
    {
        $this->lastMs = null;
        $this->count = 0;
        $this->lastButton = -1;
    }

    /** 这一次按下能不能接在上一次后面 */
    private function chains(PointerEvent $event, float $nowMs): bool
    {
        if ($this->lastMs === null) {
            return false;
        }

        $elapsed = $nowMs - $this->lastMs;

        return $elapsed >= 0.0
            && $elapsed <= $this->intervalMs
            && $event->button === $this->lastButton
            && $event->ctrl === $this->lastCtrl
            && $event->alt === $this->lastAlt
            && $event->shift === $this->lastShift
            && abs($event->x - $this->lastX) <= $this->slopPx
            && abs($event->y - $this->lastY) <= $this->slopPx;
    }
}
