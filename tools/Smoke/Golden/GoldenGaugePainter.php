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
 * @internal golden 夹具画家：仪表盘（与 showcase「自绘」页同构）。
 *
 * 表盘 / 刻度 / 指针只用 Core 层图元（`ELLIPSE` + `LINE`）—— golden 场景
 * `custompaint` 与双后端示例都靠它。几何：宽扁椭圆（cy = h/2、rx = w*0.45、
 * ry = h*0.45）整块收进自绘盒内（超出即被 C5 裁掉，基线只测一半）。
 */
final class GoldenGaugePainter extends CustomPainter
{
    public function __construct(private readonly float $value)
    {
    }

    public function paint(Canvas $canvas, Size $size): void
    {
        $cx = $size->width / 2.0;
        $cy = $size->height * 0.5;
        $rx = $size->width * 0.45;
        $ry = $size->height * 0.45;
        if ($rx <= 0.0 || $ry <= 0.0) {
            return;
        }

        $face = new Rect($cx - $rx, $cy - $ry, $rx * 2.0, $ry * 2.0);
        $canvas->drawEllipse($face, Paint::fill(new Color(244, 246, 250, 255)));
        $canvas->drawEllipse($face, Paint::stroke(new Color(70, 82, 104, 255), 2.0));

        $dirs = self::directions();
        $ticks = count($dirs);
        foreach ($dirs as $i => $d) {
            $major = $i % 5 === 0;
            $canvas->drawLine(
                $cx + $d[0] * $rx * ($major ? 0.70 : 0.82),
                $cy + $d[1] * $ry * ($major ? 0.70 : 0.82),
                $cx + $d[0] * $rx * 0.95,
                $cy + $d[1] * $ry * 0.95,
                Paint::stroke(
                    $major ? new Color(40, 52, 76, 255) : new Color(130, 140, 158, 255),
                    $major ? 2.0 : 1.0
                )
            );
        }

        $needle = self::directionAt($this->value);
        $canvas->drawLine(
            $cx,
            $cy,
            $cx + $needle[0] * $rx * 0.72,
            $cy + $needle[1] * $ry * 0.72,
            Paint::stroke(new Color(200, 48, 48, 255), 3.0)
        );
        $canvas->drawEllipse(
            new Rect($cx - 5.0, $cy - 5.0, 10.0, 10.0),
            Paint::fill(new Color(40, 52, 76, 255))
        );
    }

    /**
     * 11 个方向（左水平 → 底 → 右水平，屏幕坐标 y 向下）。
     *
     * @return list<array{float, float}>
     */
    private static function directions(): array
    {
        $s1 = 0.30901699437494745;
        $s2 = 0.5877852522924731;
        $s3 = 0.8090169943749475;
        $s4 = 0.9510565162951535;

        return [
            [-1.0, 0.0], [-$s4, $s1], [-$s3, $s2], [-$s2, $s3], [-$s1, $s4],
            [0.0, 1.0],
            [$s1, $s4], [$s2, $s3], [$s3, $s2], [$s4, $s1], [1.0, 0.0],
        ];
    }

    /** t∈[0,1] 在方向表上线性插值（字面量混合，无三角函数） */
    private static function directionAt(float $t): array
    {
        $dirs = self::directions();
        $n = count($dirs) - 1;
        if ($t <= 0.0) {
            return $dirs[0];
        }
        if ($t >= 1.0) {
            return $dirs[$n];
        }
        $pos = $t * $n;
        $lo = (int) $pos;
        $f = $pos - $lo;
        if ($lo >= $n) {
            return $dirs[$n];
        }

        return [
            $dirs[$lo][0] * (1.0 - $f) + $dirs[$lo + 1][0] * $f,
            $dirs[$lo][1] * (1.0 - $f) + $dirs[$lo + 1][1] * $f,
        ];
    }
}
