<?php
declare(strict_types=1);

namespace App\Showcase\Pages\Painters;

use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\DashPattern;
use Drift\Rendering\Paint;
use Drift\Widget\CustomPainter;

/** 走势画家：网格 + 12 点折线（线段逐段拼）+ 末点标记。只画 Core 层。 */
final class SparklinePainter extends CustomPainter
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
            // P3 的虚线消费者：网格走虚线（图表里最常见的一档）
            $grid = Paint::stroke(new Color(226, 230, 238, 255), 1.0)->withDash(new DashPattern(4.0, 4.0));
            $canvas->drawLine($left, $y, $left + $w, $y, $grid);
            $x = $left + $w * ($g / 3.0);
            $canvas->drawLine($x, $top, $x, $top + $h, $grid);
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

/**
 * P4 画家：把 19 op 的 Optional / Extended 复合 op 当真实消费者用一遍
 * （"没人调的分支等价于未实现"，所以 P4 的每个 op 都要有一个真的绘制出口）。
 *
 * - `drawShadow`：卡片背后的软阴影（几何 + 偏移 + 模糊 + 浓度）
 * - `drawPath`：填充三角形（折线下填色现在能画了，不再等 P4）
 * - `pushLayer` / `popLayer`：半透明合成层
 * - `drawImage`：渐变 swatch（内存 RGBA，行优先 4 字节）
 * - `pushClipPath`：圆形路径裁剪（后续矩形只落在形状内）
 *
 * ★ `drawShadow` / `drawImage` 是 Optional 层 ⇒ `paint()` 开头查一次
 *   `capabilities()`，没有对应位就跳过那一笔（`05` §2.5 的
 *   `optional-op-guard`：demo 页可以演示 Optional op，但不能假设后端有）。
 */
