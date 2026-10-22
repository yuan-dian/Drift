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
 * 顺从是必须的：flex 给弹性子的是**紧**约束（份额定下后只量一次），一个只会报
 * 固定尺寸的孩子会撞 C6，于是测 C4 变成测 C6。真实控件的行为就是这样
 * （Text 要高 30 却被夹成 20）。
 */
final class ConstraintSpy extends RenderBox
{
    /** 首次（弹性子 = 唯一一次）收到的约束 —— C4 断的是这一遍 */
    public ?BoxConstraints $firstSeen = null;

    /** 末次收到的约束 —— 非弹性子整棵树可能被量多次 */
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

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->box->width;
    }

    protected function contentIntrinsicHeight(float $width): float
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

    protected function paintContent(Canvas $canvas): void
    {
    }
}
