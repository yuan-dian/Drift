<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\CanvasCapabilities;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Rendering\Rect;
use Drift\Widget\CustomPainter;

/**
 * `backend-branch` 的**正例**夹具：不判类型，只查能力位 ⇒ 一响都不该响。
 *
 * ★ docblock 里提到"软光栅 / RasterCanvas"**不算** —— 注释是文档不是分叉。
 */
final class BackendBranchGood extends CustomPainter
{
    public function paint(Canvas $canvas, Size $size): void
    {
        // 正确形态：查能力位，按位取舍，永不判 `instanceof RasterCanvas`
        $caps = $canvas->capabilities();
        $color = ($caps & CanvasCapabilities::CAP_PATH) !== 0
            ? Color::black()
            : Color::fromRgb(60, 60, 60);
        $canvas->drawRect(new Rect(0.0, 0.0, 10.0, 10.0), Paint::fill($color));
    }
}
