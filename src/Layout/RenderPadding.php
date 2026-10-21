<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 内边距盒（§13.1：padding 属几何面，不属盒样式）。
 *
 * ★ 只减约束、不产出尺寸：盒子尺寸 = 内边距 + 孩子尺寸，再被外层约束夹住（C6）。
 *   "内边距比可用空间大" ⇒ 约束的 min 留住底线，不会造出负尺寸。
 *
 * @api
 */
final class RenderPadding extends RenderProxyBox
{
    public function __construct(private readonly EdgeInsets $padding)
    {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        if ($this->child === null) {
            return $constraints->constrain(new Size($this->padding->horizontal(), $this->padding->vertical()));
        }

        $this->child->layout($constraints->deflate($this->padding));

        // ★ 同 RenderFlex：padding 在**布局期**施加（不是 paint 期 translate）
        //   ⇒ hitTest / absoluteOrigin 与画出来的位置一致。
        $this->child->setOffset($this->padding->left, $this->padding->top);

        return $constraints->constrain($this->padding->inflate($this->child->size()));
    }

    #[\Override]
    public function intrinsicWidth(float $height): float
    {
        return $this->padding->horizontal() + parent::intrinsicWidth($height);
    }

    #[\Override]
    public function intrinsicHeight(float $width): float
    {
        return $this->padding->vertical() + parent::intrinsicHeight($width);
    }

    #[\Override]
    public function paint(Canvas $canvas): void
    {
        if ($this->child === null) {
            return;
        }

        $canvas->save();
        $canvas->translate($this->child->offsetX(), $this->child->offsetY());
        $this->child->paint($canvas);
        $canvas->restore();
    }
}
