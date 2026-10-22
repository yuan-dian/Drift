<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\ErrorBoundary;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;

/**
 * @internal 冒烟夹具：`performLayout` 抛（渲染期异常的 layout 半边）。
 */
final class LayoutBoomBox extends RenderBox
{
    protected function performLayout(BoxConstraints $constraints): Size
    {
        throw new \RuntimeException('layout boom');
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return 80.0;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return 40.0;
    }

    protected function paintContent(Canvas $canvas): void
    {
    }

    public function visitChildren(callable $visitor): void
    {
    }
}
