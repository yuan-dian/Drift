<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\Rect;
use Drift\Widget\CustomPainter;

/**
 * `backend-branch` 的**反例**夹具：控件里判了具体后端类型 ⇒ 必须报 error。
 *
 * 这条正是 `05` §2.5 判据推论 1 要禁的形态：preview / golden / smoke 走
 * 软光栅、真机走 Skia ⇒ 同一种 Widget 两条路，某天其中一条坏了而测试全绿。
 */
final class BackendBranchBad extends CustomPainter
{
    public function paint(Canvas $canvas, \Drift\Geometry\Size $size): void
    {
        if ($canvas instanceof RasterCanvas) {
            // 测试后端：走近似
            $canvas->drawRect(new Rect(0.0, 0.0, 10.0, 10.0), Paint::fill(Color::black()));
            return;
        }
        $canvas->drawRect(new Rect(0.0, 0.0, 10.0, 10.0), Paint::fill(Color::black()));
    }
}
