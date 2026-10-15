<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;

/**
 * @internal 冒烟夹具：定位单个子盒的容器。
 *
 * 用来验 **C1 单向数据流**：由这里给子约束、由这里定位子；子不自定位。
 * `seenChildConstraints` 把"父给了子什么"暴露成可观测量 —— 否则只能断言表面尺寸，
 * 测不到"约束真的往下传了"。
 */
final class OffsetBox extends RenderBox
{
    private ?RenderBox $child = null;
    private float $atX = 0.0;
    private float $atY = 0.0;

    public ?BoxConstraints $seenChildConstraints = null;

    public function __construct(private readonly Size $box)
    {
    }

    public function at(float $x, float $y): self
    {
        $this->atX = $x;
        $this->atY = $y;

        return $this;
    }

    public function child(RenderBox $child): self
    {
        $this->child = $child;

        return $this;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        if ($this->child !== null) {
            $this->seenChildConstraints = BoxConstraints::unbounded();
            $this->child->layout($this->seenChildConstraints);
            $this->adoptChild($this->child);
            $this->child->setOffset($this->atX, $this->atY);
        }

        return $this->box;
    }

    public function intrinsicWidth(float $height): float
    {
        return $this->box->width;
    }

    public function intrinsicHeight(float $width): float
    {
        return $this->box->height;
    }

    public function overflow(): Overflow
    {
        return Overflow::Clip;
    }

    public function visitChildren(callable $visitor): void
    {
        if ($this->child !== null) {
            $visitor($this->child);
            $this->child->visitChildren($visitor);
        }
    }

    public function paint(Canvas $canvas): void
    {
        if ($this->child === null) {
            return;
        }
        $canvas->save();
        $canvas->translate($this->atX, $this->atY);
        $this->child->paint($canvas);
        $canvas->restore();
    }
}
