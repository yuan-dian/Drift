<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Golden;

use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Widget\CustomPainter;

/**
 * @internal golden 夹具画家：走势折线（与 showcase「自绘」页同构）。
 *
 * 网格 + 12 点折线（相邻两点一线段拼）+ 末点标记 —— 只用 Core 层图元。
 */
final class GoldenSparkPainter extends CustomPainter
{
    /** @var list<float> 12 点的归一化序列（字面量，确定性） */
    private const SERIES = [0.20, 0.35, 0.28, 0.52, 0.44, 0.66, 0.58, 0.78, 0.70, 0.90, 0.82, 0.96];

    public function paint(Canvas $canvas, Size $size): void
    {
        $left = 8.0;
        $top = 8.0;
        $w = $size->width - $left * 2.0;
        $h = $size->height - $top * 2.0;
        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }

        // 网格：3 横 + 3 竖
        for ($g = 1; $g < 3; $g++) {
            $y = $top + $h * ($g / 3.0);
            $canvas->drawLine($left, $y, $left + $w, $y, Paint::stroke(new Color(226, 230, 238, 255), 1.0));
            $x = $left + $w * ($g / 3.0);
            $canvas->drawLine($x, $top, $x, $top + $h, Paint::stroke(new Color(226, 230, 238, 255), 1.0));
        }

        // 折线：无 polyline op ⇒ 相邻两点一线段
        $n = count(self::SERIES);
        $stepX = $w / ($n - 1);
        for ($i = 1; $i < $n; $i++) {
            $canvas->drawLine(
                $left + $stepX * ($i - 1),
                $top + $h * (1.0 - self::SERIES[$i - 1]),
                $left + $stepX * $i,
                $top + $h * (1.0 - self::SERIES[$i]),
                Paint::stroke(new Color(30, 110, 220, 255), 2.0)
            );
        }

        // 末点标记
        $canvas->drawEllipse(
            new Rect($left + $w - 4.0, $top + $h * (1.0 - self::SERIES[$n - 1]) - 4.0, 8.0, 8.0),
            Paint::fill(new Color(30, 110, 220, 255))
        );
    }
}
