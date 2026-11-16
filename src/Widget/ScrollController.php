<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 滚动的**外部句柄**：读位置、命令滚动、收位置回调。
 *
 * ```php
 * $ctl = ScrollController::make()->onChanged(fn (float $y, float $max) => ...);
 * ScrollView::make($list)->controller($ctl);
 * $ctl->jumpToBottom();
 * ```
 *
 * ★ 必须是句柄而不是 `GlobalKey`：`BuildContext` 无向上查找、控件也反查不到后代
 *   ⇒ 显式注入是唯一不违反结构的形态。一个 controller 只服务一个容器（后者覆盖前者）。
 *
 * @api
 */
final class ScrollController
{
    private ?ScrollMetrics $target = null;

    /** @var null|\Closure(float, float): void */
    private ?\Closure $onChanged = null;

    private float $lastOffset = 0.0;

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * 偏移变化回调：`fn(float $offset, float $maxOffset) => void`。
     *
     * 判"到底了"就是比 `$offset >= $maxOffset - 8`：只给其中一个，应用还得反查盒。
     */
    public function onChanged(callable $handler): static
    {
        $this->onChanged = \Closure::fromCallable($handler);

        return $this;
    }

    /** 当前偏移（未绑定 ⇒ 0） */
    public function offset(): float
    {
        return $this->target?->offset() ?? 0.0;
    }

    /** 最大偏移（未绑定 ⇒ 0） */
    public function maxOffset(): float
    {
        return $this->target?->maxOffset() ?? 0.0;
    }

    /** 滚到绝对偏移；越界由盒夹，不报错 */
    public function jumpTo(float $offset): void
    {
        $this->target?->scrollTo($offset);
        $this->sync();
    }

    /**
     * 动画滚到绝对偏移（`durationMs <= 0` 退化成 `jumpTo`）。
     *
     * ★ 中断语义同 Flutter：用户一动（滚轮 / thumb / ensureVisible）立即让位，
     *   不会在用户操作后还继续把画面拽走；后一次调用顶掉前一次。
     */
    public function animateTo(float $offset, float $durationMs = 240.0, ?Curve $curve = null): void
    {
        if ($durationMs <= 0.0) {
            $this->jumpTo($offset);

            return;
        }

        $this->target?->animateTo($offset, $durationMs, $curve ?? Curve::easeOut());
    }

    /** 滚到底（内容比视口短 ⇒ 不动） */
    public function jumpToBottom(): void
    {
        $this->jumpTo($this->maxOffset());
    }

    /** 滚回顶部 */
    public function jumpToTop(): void
    {
        $this->jumpTo(0.0);
    }

    /** @internal 容器挂载时登记；`$force` = 立刻把初始位置报一次 */
    public function attach(ScrollMetrics $target): void
    {
        $this->target = $target;
        $this->sync(true);
    }

    /** @internal 容器卸载时解绑；只清自己绑的那个，不误伤后来者 */
    public function detach(ScrollMetrics $target): void
    {
        if ($this->target !== $target) {
            return;
        }

        $this->target = null;
        $this->lastOffset = 0.0;
    }

    /**
     * @internal 偏移可能变了 —— 由容器在写入口之后调。
     *
     * ★ 按值去重而不是让调用方判"有没有真动"：滚动入口有五个且以后还会加，
     *   逐个判漏一个就是静默的"界面滚了但回调没来"。去重幂等，宁可多调。
     */
    public function sync(bool $force = false): void
    {
        $offset = $this->offset();
        if (!$force && $offset === $this->lastOffset) {
            return;
        }

        $this->lastOffset = $offset;
        if ($this->onChanged !== null) {
            ($this->onChanged)($offset, $this->maxOffset());
        }
    }
}
