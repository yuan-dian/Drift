<?php
declare(strict_types=1);

namespace Fixture\Apps;

use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\CanvasCapabilities;
use Drift\Rendering\Color;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\Paint;
use Drift\Widget\CustomPainter;

/**
 * `optional-op-guard` 的**正例**夹具：同类内查过能力位 ⇒ 一响都不该响。
 *
 * ★ 这是"同类内"放宽的正确示范：`init()` 里查一次、`paint()` 里用，本规则
 *   判不出来（宁漏不误响）—— 这里演示的是同一方法内查完就用。
 */
final class OptionalOpGood extends CustomPainter
{
    public function paint(Canvas $canvas, Size $size): void
    {
        $caps = $canvas->capabilities();
        if (($caps & CanvasCapabilities::CAP_IMAGE) !== 0) {
            $canvas->drawImage(
                ImageHandle::fromRgba(str_repeat("\x00", 16), 2, 2),
                null,
                new Rect(0.0, 0.0, 4.0, 4.0)
            );
        }
        $canvas->drawRect(new Rect(0.0, 0.0, 4.0, 4.0), Paint::fill(Color::black()));
    }
}
