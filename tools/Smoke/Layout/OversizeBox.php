<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;

/**
 * @internal 冒烟夹具：故意报一个**超出约束**的尺寸。
 *
 * C6 的"违反 ⇒ 响亮失败"必须有夹具让它犯一次，否则守卫只活在注释里。
 * 它专挑"只超上界 1"的形态 —— 静默夹紧在这儿最难发现。
 */
final class OversizeBox extends RenderBox
{
    protected function performLayout(BoxConstraints $constraints): Size
    {
        return new Size($constraints->maxWidth + 1.0, 5.0);
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return 1.0;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return 1.0;
    }

    public function overflow(): Overflow
    {
        return Overflow::Report; // 声明了要报，正好和 C6 对上
    }

    public function visitChildren(callable $visitor): void
    {
    }

    protected function paintContent(Canvas $canvas): void
    {
    }
}
