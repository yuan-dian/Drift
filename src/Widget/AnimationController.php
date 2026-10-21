<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 动画控制器 —— `0..1` 的**单值驱动器**（§17.4.11）。
 *
 * ```php
 * final class BannerState extends State
 * {
 *     private AnimationController $fade;
 *
 *     public function initState(): void
 *     {
 *         $this->fade = AnimationController::make(240.0)->curve(Curve::easeOut());
 *     }
 *
 *     public function tickFrame(float $timestampMs): bool
 *     {
 *         // ★ tick() 返回"我还要下一帧吗"，直接喂 `wantsFrame()`：
 *         //   跑完没人返回 true ⇒ 帧时钟停摆 ⇒ 空闲 CPU 归零（§17.4.9）。
 *         $busy = $this->fade->tick($timestampMs);
 *         if ($busy) {
 *             $this->markDirty();
 *         }
 *
 *         return $busy;
 *     }
 *
 *     public function build(BuildContext $ctx): Widget
 *     {
 *         // 缓动后的值直接喂任何"按数值画"的控件：进度条、宽度、偏移量…
 *         return Progress::make($this->fade->value());
 *     }
 * }
 * ```
 *
 * ★ **`Opacity` / `Transform` 这类"整棵子树一起动"的包装暂不做**（EVAL §7 第 4 项
 *   的前提：要先有层合成语义）。本控制器给的是**值** —— 把它喂给已有控件的
 *   数值入口（`Progress::value()`、`SizedBox::width()`…）就能动，不需要新控件。
 *
 * ★ **它不持时钟、不持平台**：推进发生在 `tick($timestampMs)`，时间戳由
 *   `State::tickFrame()` 注入（§14.1 G3：不读环境时钟）⇒ 同一棵动画树在
 *   "解释器 smoke"与"AOT 真机"里推进完全一致。
 *
 * ★ **时间基准是"注入的帧时间戳"，不是墙钟**：`forward()` 不记起点（此刻没有
 *   时间戳可用），而是置 `startPending` —— 第一次 `tick()` 才把起点钉在那一帧
 *   的时间戳上。⇒ "起手那一帧"不吃掉一个间隔（用墙钟记起点会让第一帧就跳掉
 *   一个 15ms，短动画整体偏快一档）。
 *
 * ★ **反向按剩余距离缩时长**（`reverse()` 从 `0.6` 回 `0` 只跑 `0.6 × duration`）：
 *   速度恒定，从中间被打断再回退时不会突然变慢一截。代价是"反向总比正向短"
 *   —— 这正是想要的手感（回退是取消，不是重播）。
 *
 * ★ **终点帧仍然返回 `true`，再下一帧才收口返回 `false`** —— 这是"最后一帧也要
 *   画得到"的唯一实现：调用方只写 `if ($busy) { markDirty(); }`，若终点帧返回
 *   false，终点值就没人标脏 ⇒ 画面永远停在 99%（**且不报错**，最难查的那类）。
 *   代价是每轮动画多一帧（15ms），换来的是"调用方不可能写错"。
 *   `isAnimating()` 在终点帧已经是 false —— "还在推进吗"的语义不含收口帧。
 *
 * ★ `onCompleted()` 是**一次性**的（触发即清空）。它与 §17.4.10 拒掉的"帧回调
 *   注册表"不是一回事：那个要**配对注销**（漏一次 ⇒ 永久空转 + 泄漏）；这个是
 *   控制器自己持有的一个槽，控制器随 State 一起死，不存在注销问题。
 *
 * @api
 */
final class AnimationController
{
    /** 单程时长（毫秒，`0` = 立即完成） */
    private float $durationMs = 200.0;

    /** 线性进度（**不是**缓动后的值；曲线是纯输出映射，不参与推进） */
    private float $t = 0.0;

    private Curve $curve;

    private bool $animating = false;

    /** 已请求起点但还没拿到第一帧的时间戳（见类注释"时间基准"） */
    private bool $startPending = false;

    /** 已到终点，**等最后一帧渲染完**再收口（见类注释"终点帧"） */
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

    /** 单程时长（毫秒）。★ 只影响**之后**开始的段，不重算正在跑的段 */
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
     * 跳到某个值并**停住**（打断正在跑的段）。
     *
     * ★ **不触发** `onCompleted()`：那是"跑到了终点"，这是"被挪到某处"。把跳转
     *   当完成会让"打断"顺手把副作用放一遍（回弹 / 上报 / 换页），而打断的语义
     *   恰恰是"取消"。
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

    /** 还在**推进**吗（不含"等最后一帧渲染"的收口帧） */
    public function isAnimating(): bool
    {
        return $this->animating && !$this->pendingFinish;
    }

    /** 线性进度 `0..1`（推进 / 打断都按它算） */
    public function progress(): float
    {
        return $this->t;
    }

    /** 缓动后的值 `0..1`（**画东西用它**） */
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
     * 推进一帧（`@internal` 由 `State::tickFrame()` 调）。
     *
     * @return bool **还要下一帧吗**（直接喂 `wantsFrame()`）
     */
    public function tick(float $timestampMs): bool
    {
        if (!$this->animating) {
            return false;
        }

        // 起手帧：钉起点，值仍是 `from`（本帧就把区间定下来，不吃掉一个间隔）
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

        // 时长按剩余距离缩（`reverse()` 从 0.6 只跑 0.6×duration）
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

            // ★ 不在这里 finish()：这一帧还得把终点值画出去（见类注释）
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
