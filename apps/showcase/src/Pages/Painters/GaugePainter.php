<?php
declare(strict_types=1);

namespace App\Showcase\Pages\Painters;

use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Gradient;
use Drift\Rendering\Paint;
use Drift\Widget\CustomPainter;

/**
 * 仪表盘画家：值 0..1 决定指针角度；`version()` = 值变了几次（内容戳）。
 *
 * ★ **方向表是字面量不是 `cos()/sin()`**：§14.1 纯函数 —— libm 的跨平台末位
 *   差异会让画面在多平台间不可比（`RenderSpinner` 同族先例），11 个方向写死。
 */
final class GaugePainter extends CustomPainter
{
    private float $value = 0.0;

    private int $ver = 0;

    public function value(float $v): void
    {
        $this->value = $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
        $this->ver++;
    }

    public function version(): int
    {
        return $this->ver;
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

        // 表盘（填充 + 描边）
        $face = new Rect($cx - $rx, $cy - $ry, $rx * 2.0, $ry * 2.0);
        // P3 的渐变消费者：表盘用径向渐变填（中心亮、边缘沉），不是纯色。
        $canvas->drawEllipse($face, Paint::fill(new Color(244, 246, 250, 255))->withGradient(
            Gradient::radial($cx, $cy - $ry * 0.35, max($rx, $ry), [
                new Color(255, 255, 255, 255),
                new Color(222, 229, 240, 255),
            ])
        ));
        $canvas->drawEllipse($face, Paint::stroke(new Color(70, 82, 104, 255), 2.0));

        // 刻度：11 根沿下半圆的线段（无 arc op ⇒ 线段拼弧）
        $dirs = self::directions();
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

        // 指针 + 中心帽
        $needle = self::directionAt($this->value);
        $canvas->drawLine(
            $cx,
            $cy,
            $cx + $needle[0] * $rx * 0.72,
            $cy + $needle[1] * $ry * 0.72,
            Paint::stroke(new Color(200, 48, 48, 255), 3.0)
        );
        $canvas->drawEllipse(new Rect($cx - 5.0, $cy - 5.0, 10.0, 10.0), Paint::fill(new Color(40, 52, 76, 255)));
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

/** 走势画家：网格 + 12 点折线（线段逐段拼）+ 末点标记。只画 Core 层。 */
