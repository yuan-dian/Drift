<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Core\Pipeline;

/**
 * 动画控制器：0..1 的单值驱动器。把缓动后的值喂给已有控件的数值入口
 * （Progress::value()、SizedBox::width()…）就能驱动动画，不需要新控件。
 *
 * 不持时钟、不持平台：推进发生在 tick($timestampMs)，时间戳由
 * State::tickFrame() 注入 ⇒ 解释器与 AOT 真机推进完全一致。
 *
 * ★ 终点帧仍返回 true、再下一帧才收口返回 false：否则终点值没人标脏，
 *   画面永远停在 99% 且不报错（最难查的那类）。
 *
 * @api
 */
final class AnimationController
{
    /** 单程时长（毫秒，`0` = 立即完成） */
    private float $durationMs = 200.0;

    /** 线性进度（不是缓动后的值；曲线是纯输出映射，不参与推进） */
    private float $t = 0.0;

    private Curve $curve;

    private bool $animating = false;

    /** 已请求起点但还没拿到第一帧的时间戳 */
    private bool $startPending = false;

    /** 已到终点，等最后一帧渲染完再收口 */
    private bool $pendingFinish = false;

    private float $startedAt = 0.0;

    private float $from = 0.0;

    private float $to = 1.0;

    private ?\Closure $completed = null;

    public function __construct(float $durationMs = 200.0, ?Curve $curve = null)
    {
        $this->durationMs = $durationMs > 0.0 ? $durationMs : 0.0;
        $this->curve = $curve ?? Curve::linear();
    }

    /** @param float $durationMs 单程时长（毫秒，`<=0` 归零 = 立即完成） */
    public static function make(float $durationMs = 200.0): self
    {
        return new self($durationMs);
    }

    /** 单程时长（毫秒）。只影响之后开始的段，不重算正在跑的段。 */
    public function duration(float $durationMs): static
    {
        $this->durationMs = $durationMs > 0.0 ? $durationMs : 0.0;

        return $this;
    }

    /** 缓动曲线（默认 `Curve::linear()`） */
    public function curve(Curve $curve): static
    {
        $this->curve = $curve;

        return $this;
    }

    /** 跑到 `1.0`（已在 `1.0` ⇒ 无事发生，也不回调） */
    public function forward(): void
    {
        $this->start(1.0);
    }

    /** 跑回 `0.0`（已在 `0.0` ⇒ 无事发生，也不回调） */
    public function reverse(): void
    {
        $this->start(0.0);
    }

    /**
     * 跳到某个值并停住（打断正在跑的段）。
     *
     * 不触发 onCompleted()：跳转是"被挪到某处"，不是"跑到了终点"，
     * 把它当完成会把打断顺手触发回弹 / 上报等副作用。
     */
    public function jumpTo(float $value): void
    {
        if ($value <= 0.0) {
            $value = 0.0;
        }

        if ($value > 1.0) {
            $value = 1.0;
        }

        $this->t = $value;
        $this->animating = false;
        $this->startPending = false;
        $this->pendingFinish = false;
    }

    /** 就地停住（保留当前值，不回调） */
    public function stop(): void
    {
        $this->animating = false;
        $this->startPending = false;
        $this->pendingFinish = false;
    }

    /** 还在推进吗（不含"等最后一帧渲染"的收口帧） */
    public function isAnimating(): bool
    {
        return $this->animating && !$this->pendingFinish;
    }

    /** 线性进度 `0..1`（推进 / 打断都按它算） */
    public function progress(): float
    {
        return $this->t;
    }

    /** 缓动后的值 `0..1`（画东西用它） */
    public function value(): float
    {
        return $this->curve->map($this->t);
    }

    /** 一次性完成回调（触发即清空；后设的覆盖先设的） */
    public function onCompleted(callable $listener): static
    {
        $this->completed = \Closure::fromCallable($listener);

        return $this;
    }

    /**
     * @internal 由 State::tickFrame() 调。
     *
     * @return bool 还要下一帧吗（直接喂 wantsFrame()）
     */
    public function tick(float $timestampMs): bool
    {
        if (!$this->animating) {
            return false;
        }

        // 起手帧：钉起点，值仍是 from（本帧就把区间定下来，不吃掉一个间隔）
        if ($this->startPending) {
            $this->startedAt = $timestampMs;
            $this->startPending = false;

            return true;
        }

        // 收口帧：终点值上一帧已经渲染过了 ⇒ 停表 + 放一次性回调
        if ($this->pendingFinish) {
            $this->finish();

            return false;
        }

        $span = $this->to - $this->from;
        if ($span < 0.0) {
            $span = -$span;
        }

        // 时长按剩余距离缩（reverse() 从 0.6 只跑 0.6×duration）
        $total = $this->durationMs * $span;

        if ($total <= 0.0) {
            $this->t = $this->to;
            $this->pendingFinish = true;

            return true;
        }

        $elapsed = $timestampMs - $this->startedAt;
        if ($elapsed < 0.0) {
            // 时间戳回退（首帧钉起点前混入旧戳 / 容器重置）⇒ 当成"还没开始"
            $elapsed = 0.0;
        }

        $ratio = $elapsed / $total;
        if ($ratio >= 1.0) {
            $this->t = $this->to;

            // 不在这里 finish()：这一帧还得把终点值画出去
            $this->pendingFinish = true;

            return true;
        }

        $this->t = $this->from + ($this->to - $this->from) * $ratio;

        return true;
    }

    /** @internal 起一段（同向且已在终点 ⇒ 无事发生） */
    private function start(float $target): void
    {
        $span = $target - $this->t;
        if ($span < 0.0) {
            $span = -$span;
        }

        if ($span <= 0.0) {
            $this->animating = false;
            $this->startPending = false;
            $this->pendingFinish = false;

            return;
        }

        $this->from = $this->t;
        $this->to = $target;
        $this->animating = true;
        $this->startPending = true;
        $this->pendingFinish = false;

        // ★ 起飞必须请求一次全树发现：本类不持 State（纯驱动器，无 element()），
        //   只能靠帧时钟那一帧把持有它的 State 收进活跃表，否则动画不会被推进。
        //   放在 $span <= 0 的早退之后 ⇒ 只有真的起飞才请求。
        Pipeline::current()->requestAnimationScan();
    }

    /** @internal 收口 + 放一次性回调 */
    private function finish(): void
    {
        $this->animating = false;
        $this->startPending = false;
        $this->pendingFinish = false;

        $listener = $this->completed;
        if ($listener === null) {
            return;
        }

        $this->completed = null;   // ★ 一次性：触发即清空，不重放
        $listener();
    }
}
