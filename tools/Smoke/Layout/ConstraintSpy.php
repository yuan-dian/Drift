<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;

/**
 * @internal 冒烟夹具：把**自己收到的约束**记下来，并报"顺从约束的自然尺寸"。
 *
 * 顺从是必须的：flex 第二遍给的是**紧**约束，一个只会报固定尺寸的孩子会撞 C6，
 * 于是测 C4 变成测 C6。真实控件的行为就是这样（Text 要高 30 却被夹成 20）。
 */
final class ConstraintSpy extends RenderBox
{
    /** 第一遍（量自然尺寸）收到的约束 —— C4 断的是这一遍 */
    public ?BoxConstraints $firstSeen = null;

    /** 最后一次收到的约束 —— flex 第二遍给的是紧约束 */
    public ?BoxConstraints $lastSeen = null;

    public function __construct(private readonly Size $box)
    {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $this->firstSeen ??= $constraints;
        $this->lastSeen = $constraints;

        return new Size(
            min(max($this->box->width, $constraints->minWidth), $constraints->maxWidth),
            min(max($this->box->height, $constraints->minHeight), $constraints->maxHeight),
        );
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
    }

    public function paint(Canvas $canvas): void
    {
    }
}
