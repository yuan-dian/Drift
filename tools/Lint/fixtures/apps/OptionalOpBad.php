<?php
declare(strict_types=1);

namespace App;

use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\Paint;
use Drift\Widget\CustomPainter;

/**
 * `optional-op-guard` 的**反例**夹具：直接发 Optional 层的 op 而同类内没查
 * 能力位 ⇒ 必须报 error（软光栅后端上会当场 Fatal）。
 */
final class OptionalOpBad extends CustomPainter
{
    public function paint(Canvas $canvas, Size $size): void
    {
        $canvas->drawImage(
            ImageHandle::fromRgba(str_repeat("\x00", 16), 2, 2),
            null,
            new Rect(0.0, 0.0, 4.0, 4.0)
        );
        $canvas->drawRect(new Rect(0.0, 0.0, 4.0, 4.0), Paint::fill(Color::black()));
    }
}
