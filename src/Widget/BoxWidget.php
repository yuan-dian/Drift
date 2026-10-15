<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;

/**
 * 需要盒样式的控件的中间基类（§15.7）。
 *
 * ★ **为什么是基类而不是 trait**：§2.4 纪律 7 只验过"trait 不单独成文件"，
 *   "trait 混入多个类"在 AOT 下展开成什么**没验过**；继承已验。不为了省打字
 *   引入未验机制。
 *
 * ★ **结构节点（Column / Row / Each / Spacer）继承裸 `Widget`** ⇒ 拿不到
 *   `->background()` —— 那是刻意的：布局节点不该有绘制样式。
 *
 * ★ **继承 `RenderBoxWidget` 而不是 `Widget`**：盒样式控件都要产渲染盒，挂在
 *   `RenderBoxWidget` 上让 `Element::syncBox()` 的 `instanceof` 判真。只写方法
 *   不挂类型 —— "有方法"不等于"是那个类型"，盒会静默变成 null。
 *
 * @api
 */
abstract class BoxWidget extends RenderBoxWidget
{
    /** @internal null = 无盒样式 ⇒ 不用样式就不付对象分配（§15.7 纪律 1） */
    private ?BoxStyle $box = null;

    /** @internal 渲染层读它 */
    public function box(): ?BoxStyle
    {
        return $this->box;
    }

    public function background(Color $color): static
    {
        $this->mutate();
        $this->box = ($this->box ?? BoxStyle::none())->withBackground($color);

        return $this;
    }

    public function border(Color $color, float $width = 1.0): static
    {
        $this->mutate();
        $this->box = ($this->box ?? BoxStyle::none())->withBorder($color, $width);

        return $this;
    }

    public function radius(float $radius): static
    {
        $this->mutate();
        $this->box = ($this->box ?? BoxStyle::none())->withRadius($radius);

        return $this;
    }
}
