<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderDock;

/**
 * 多页外壳（§12.1 第 3 条 / §12.2）：五个槽，外壳持久、只有 center 换内容。
 *
 * ```php
 * Dock::make()
 *     ->top(AppBar::make()->title('用户管理')->children([...]))
 *     ->bottom(StatusBar::make()->source($store)->select(...))
 *     ->center(PageHost::make()->source($store)->select(...)->table($table))
 *     ->overflow(Overflow::Clip);
 * ```
 *
 * ★ **Root 只组合**（§12.2）：`Dock` + 槽控件 + `PageHost` 就是 Root 的全部
 *   词汇 —— 具体布局一页一文件，壳里不写页面布局（A10 的结构性对策）。
 *
 * ★ **滚动缺席的诚实边界**：§12.1 第 3 条要求"内容区必须可滚动"，但滚动容器
 *   归 M5（§19）—— 今天的 center 槽拿 tight 约束，页面自己决定怎么用；
 *   长内容会触发页自己的溢出策略，不是 Dock 的静默失败。
 *
 * @api
 */
final class Dock extends RenderBoxWidget
{
    private ?Widget $top = null;
    private ?Widget $left = null;
    private ?Widget $center = null;
    private ?Widget $right = null;
    private ?Widget $bottom = null;
    private Overflow $policy = Overflow::Report;

    public static function make(): self
    {
        return new self();
    }

    public function top(Widget $kid): static
    {
        $this->mutate();
        $this->top = $kid;

        return $this;
    }

    public function left(Widget $kid): static
    {
        $this->mutate();
        $this->left = $kid;

        return $this;
    }

    public function center(Widget $kid): static
    {
        $this->mutate();
        $this->center = $kid;

        return $this;
    }

    public function right(Widget $kid): static
    {
        $this->mutate();
        $this->right = $kid;

        return $this;
    }

    public function bottom(Widget $kid): static
    {
        $this->mutate();
        $this->bottom = $kid;

        return $this;
    }

    /** C5/W4：外壳是会超的容器 ⇒ 必须显式选（默认 Report = 响亮失败） */
    public function overflow(Overflow $value): static
    {
        $this->mutate();
        $this->policy = $value;

        return $this;
    }

    /** @internal `DockElement` 按固定顺序取槽（顺序即 diff 的位置序） */
    public function slotKid(string $slot): ?Widget
    {
        return match ($slot) {
            'top' => $this->top,
            'left' => $this->left,
            'center' => $this->center,
            'right' => $this->right,
            'bottom' => $this->bottom,
            default => null,
        };
    }

    /** @internal */
    public function overflowValue(): Overflow
    {
        return $this->policy;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderDock($this->policy);
    }

    #[\Override]
    public function createElement(): Element
    {
        return new DockElement($this);
    }
}
