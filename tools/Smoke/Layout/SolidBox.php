<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;

/**
 * @internal 冒烟夹具：固定尺寸的实心盒。
 *
 * 内禀值是**构造时给的**，不与 `$box` 相等 —— 用来证明 `intrinsicWidth()` 真的
 * 在用"另一轴"读数，而不是把自身尺寸回抄一遍（后者也能过一遍测试，但测不出 C3）。
 */
final class SolidBox extends RenderBox
{
    public function __construct(
        private readonly Size $box,
        private readonly float $intrinsicW,
        private readonly float $intrinsicH,
    ) {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        return $this->box;
    }

    public function intrinsicWidth(float $height): float
    {
        return $this->intrinsicW + $height * 0.0; // 显式读另一轴，防"顺手删参数"
    }

    public function intrinsicHeight(float $width): float
    {
        return $this->intrinsicH;
    }

    public function overflow(): Overflow
    {
        return Overflow::Clip;
    }

    public function visitChildren(callable $visitor): void
    {
    }

    public function paint(Canvas $canvas): void
    {
        $canvas->drawRect(new Rect(0.0, 0.0, $this->box->width, $this->box->height), Paint::fill(Color::black()));
    }
}
