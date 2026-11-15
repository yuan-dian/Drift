<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Core\Pipeline;

/**
 * **跟手态**的连续值驱动器：目标变了就往那儿走，走到就停。
 *
 * ## 与 `AnimationController` 的分工 —— 这不是两套动画，是两类时间语义
 *
 * | | 跟手态（本类） | 演出（`AnimationController`） |
 * |---|---|---|
 * | 典型场景 | hover 亮度、滚动条显隐、指针跟随 | 对话框进场、页面转场 |
 * | 目标中途变化 | **接着走**，速度连续 | 重启/反向，节奏重排 |
 * | 时长 | 没有"总时长"，只有半衰期 | 明确 `durationMs` |
 * | 收口判据 | 离目标 < epsilon | 走完 `0..1` |
 *
 * ★ 把两者合并会两边都别扭：给 hover 定 200ms 会让"快速划过一排按钮"变成
 *   一串各自计时的残影；给对话框用指数逼近则永远差最后一口气。
 *
 * ## 为什么是指数逼近，不是"定时长 + 曲线"
 *
 * 指数逼近（每个半衰期把剩余距离减半）对**目标中途变化**天然连续 —— 不需要
 * 知道起点、不需要重排节奏。滚轮连滚、指针连划这一整类输入正是"目标每帧都在
 * 变"，定时长方案每次都要重启，节奏累积成抖动。
 *
 * ## 三条硬约束（都是踩过的形态）
 *
 * ★ **不持时钟、不读墙钟**：推进只发生在 `tick()`，时间戳由调用方注入
 *   （与 `AnimationController` 同一条纪律）。
 * ★ **首帧必须返回 `true`**：`toward()` 之后如果第一帧就报"没动画"，帧时钟
 *   立刻停摆、值永远停在起点（且不报错）。所以"还没拿到 dt"这一帧也算在跑。
 * ★ **收口帧返回 `false` 但值已落到目标**：`setCompose()` 推的 `paintEpoch` 让本帧
 *   paint 门控必画 ⇒ 收口帧画得到，不必为"最后一帧"多要一帧。
 *
 * @api
 */
final class AnimatedValue
{
    /**
     * 离目标小于这个量就算到了。
     *
     * ★ `0.01` 不是随手取的：它是"**1% 不可见**"的阈值 —— 进度喂给 alpha 时
     *   差 1% 相当于 2.5/255，任何屏幕上都看不出。取 `0.001` 会让整条动画多跑
     *   一倍时长（指数逼近尾段极慢），换来的是**帧时钟多转几百毫秒**。
     */
    private const float EPSILON = 0.01;

    private float $value;

    private float $target;

    /** 上一帧的时间戳；`null` = 还没 tick 过（首帧只记起点，不推进） */
    private ?float $lastTs = null;

    private float $lastDelta = 0.0;

    /**
     * @param float $value 初值（作为当前值与目标值）
     * @param float $halfLifeMs 半衰期（毫秒）：每过这么久，剩余距离减半。
     *        `55` ≈ 150ms 走到 85%、370ms 收口（一帧 16ms 时约 23 帧）——
     *        比 Flutter 的 200ms hover 略快，因为悬停反馈"慢半拍"比"太急"更明显。
     */
    public function __construct(float $value = 0.0, private readonly float $halfLifeMs = 55.0)
    {
        $this->value = $value;
        $this->target = $value;
    }

    public function value(): float
    {
        return $this->value;
    }

    public function target(): float
    {
        return $this->target;
    }

    /** 上一帧推进用的 dt（毫秒；首帧为 0）—— 需要"空闲多久了"的消费者读它 */
    public function lastDelta(): float
    {
        return $this->lastDelta;
    }

    public function isSettled(): bool
    {
        return \abs($this->target - $this->value) <= self::EPSILON;
    }

    /**
     * 置新目标。
     *
     * ★ 同值不动：否则每帧重设同一个目标会把"已收口"打回"在跑"，帧时钟永不
     *   停摆（空闲空转）。
     */
    public function toward(float $target): void
    {
        if ($target === $this->target) {
            return;
        }

        $this->target = $target;

        // 起飞时若已"名义上收口"，`lastTs` 留着没用；不清也行 —— 但清掉能让
        // 首帧重新走"只记起点"那条路，避免用一个很久以前的时间戳算出巨大 dt。
        $this->lastTs = null;

        // ★★ **值的起飞点就是"帧时钟该发现我"的现场**（2026-11-03）。帧时钟不再
        //   每帧问全树，只在"可能有新动画起步"的帧做一次发现 —— 而**所有**跟手态
        //   动画（悬停 / 滚动滑行 / 滚动条淡出 / `AnimatedScalar` 一族）都从这里起飞，
        //   所以这一行覆盖了全部，不需要每个宿主各补一句（那会漏，且漏了是静默的：
        //   滚轮滚了、偏移不动、没有任何报错）。
        // ★ 只在**目标真的变了**时到这里（上面那条早退）⇒ 不是每帧都请求全扫。
        // ★ 不 `scheduleNextFrame()`：本类此前不叫醒帧时钟，加它会改变既有行为
        //   （`toward()` 之后谁来出帧是宿主的责任，见 `State::requestFrame()`）。
        Pipeline::current()->requestAnimationScan();
    }

    /** 立即到某个值（不产生过渡；用于状态重置 / 首次布局） */
    public function snapTo(float $value): void
    {
        $this->value = $value;
        $this->target = $value;
        $this->lastTs = null;
        $this->lastDelta = 0.0;
    }

    /**
     * 推进一帧，回答"**还要下一帧吗**"。
     */
    public function tick(float $timestampMs): bool
    {
        $last = $this->lastTs;
        $this->lastTs = $timestampMs;

        // 首帧 / 刚起飞：没有 dt 可用，但**必须**报"在跑"，否则帧时钟当场停摆。
        if ($last === null) {
            $this->lastDelta = 0.0;

            return !$this->isSettled();
        }

        $dt = $timestampMs - $last;
        if (!\is_finite($dt) || $dt <= 0.0) {
            $this->lastDelta = 0.0;

            return !$this->isSettled();
        }

        // ★ 卡顿后的大间隔（拖窗口 / 断点）夹到 3 个半衰期：不夹的话一顿之后
        //   一步到位，"有动画"和"瞬移"没有区别。
        $dt = \min($dt, $this->halfLifeMs * 3.0);
        $this->lastDelta = $dt;

        if ($this->isSettled()) {
            return false;
        }

        // 剩余距离每个半衰期减半
        $kept = \pow(2.0, -$dt / $this->halfLifeMs);
        $this->value = $this->target + ($this->value - $this->target) * $kept;

        if (\abs($this->target - $this->value) <= self::EPSILON) {
            $this->value = $this->target;

            return false;
        }

        return true;
    }
}
