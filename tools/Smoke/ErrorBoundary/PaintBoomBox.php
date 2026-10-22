<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\ErrorBoundary;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;

/**
 * @internal 冒烟夹具：`paint` 抛（渲染期异常的 paint 半边）。
 *
 * layout 故意正常 —— 让 paint 阶段才炸，验证 `RenderErrorBoundary::paint`
 * 的兜底（而不是 layout 兜底顺带盖住）。
 */
final class PaintBoomBox extends RenderBox
{
    protected function performLayout(BoxConstraints $constraints): Size
    {
        return $constraints->constrain(new Size(80.0, 40.0));
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
        throw new \RuntimeException('paint boom');
    }

    public function visitChildren(callable $visitor): void
    {
    }
}
