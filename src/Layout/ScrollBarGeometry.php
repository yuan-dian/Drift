<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Axis;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;

/**
 * 滚动条的几何（`RenderScrollView` / `RenderTable` 共用）。
 *
 * ★ 抽出来的理由只有一条，就是本仓反复踩的那条：**两处各算一遍必然漂**。
 *   滚动条上「长度 / 位置 / 可走距离」是三个必须互相一致的量 —— 绘制用长度、
 *   命中用矩形、拖动映射用可走距离。多一个实现就多一份漂移面，而它们的漂移
 *   表现是"条画在这里、抓不到"或"拖一格跳半屏"，都不报错。
 *
 * ★ 公式是 2026-10-17 起在 `RenderScrollView` 里跑熟的，这里**一字未改**地搬过来
 *   （含 `$maxScroll <= 0` 的除法保护：`appliedOffset() / 0` 是
 *   `DivisionByZeroError`，不是"什么都不发生"）。
 *
 * @internal 只有滚动盒用；应用侧不接触
 */
final class ScrollBarGeometry
{
    /** 条粗（逻辑像素） */
    public const float THICKNESS = 6.0;

    /** 条离视口边缘的空隙 */
    public const float MARGIN = 3.0;

    /** thumb 最短长度（内容再长也留这么一截，否则细到抓不住） */
    public const float MIN_THUMB = 24.0;

    /**
     * thumb 长度：视口占比 × 轨道。
     *
     * ★ 用 `track² / (track + maxScroll)` 而不是 `track × viewport / content`：
     *   两者在数学上等价（`viewport = track`、`content = track + maxScroll`），
     *   但前者不需要"内容总长"这个可能拿不到的中间量。
     */
    public static function thumbLength(float $track, float $maxScroll): float
    {
        return min($track, max(self::MIN_THUMB, $track * $track / ($track + $maxScroll)));
    }

    /** thumb 在轨道里**可走**的距离（拖动映射的分母；0 = 拖不动） */
    public static function travel(float $track, float $maxScroll): float
    {
        return max(0.0, $track - 2.0 * self::MARGIN - self::thumbLength($track, $maxScroll));
    }

    /**
     * thumb 矩形（**盒内**坐标；`null` = 内容不溢出、不该画）。
     *
     * ★ `null` 的三条来源都是"画了就是撒谎"：不溢出（暗示能滚）、
     *   还没有尺寸（没布局）、轨道为 0（条会比盒还长）。
     *
     * ★ 轴换算与 `extent()` / `crossExtent()` / `project()` 共用一套（`Axis` 只是
     *   值，不该在每个调用点各写一次三元式 —— 漏一处就是"条按宽画、按高命中"）。
     */
    public static function thumbRect(Size $size, float $maxScroll, float $offset, Axis $axis): ?Rect
    {
        if ($maxScroll <= 0.0) {
            return null;
        }

        $vertical = $axis === Axis::Vertical;
        $track = self::extent($size, $axis);
        $cross = self::crossExtent($size, $axis);
        if ($track <= 0.0) {
            return null;
        }

        $len = self::thumbLength($track, $maxScroll);
        $travel = self::travel($track, $maxScroll);
        // ★ 偏移先夹一次：调用方给的可能是**未夹**的原始值（State 只夹下界），
        //   不夹会让条画到轨道外面。
        $pos = self::MARGIN + (min(max(0.0, $offset), $maxScroll) / $maxScroll) * $travel;

        return $vertical
            ? new Rect($cross - self::THICKNESS - self::MARGIN, $pos, self::THICKNESS, $len)
            : new Rect($pos, $cross - self::THICKNESS - self::MARGIN, $len, self::THICKNESS);
    }

    /**
     * 画一根轴上的条（`$bar` 来自 `thumbRect()`；调用方负责"有没有条"）。
     *
     * ★ 抽到这里而不是各盒各写一份：条**长什么样**（胶囊圆角、alpha 折算）
     *   与**它在哪**（`thumbRect()`）是同一件事的两半。`RenderScrollView` 与
     *   `RenderTable` 各抄过一遍，`RenderTextArea` 是第三个 —— 再抄一遍就成了
     *   "三处公式同源、圆角半径各写一次"的典型漂移面。
     * ★ `$opacity` 是 `ScrollBarFade` 的淡出值；alpha 归零 ⇒ **不画**（而不是
     *   画一层全透明）—— 两者画面相同，但后者每帧多一条空命令进重放流。
     */
    public static function paint(Canvas $canvas, Rect $bar, Color $ink, float $opacity): void
    {
        $alpha = (int) \round($ink->a * $opacity);
        if ($alpha <= 0) {
            return;
        }

        $canvas->drawRect($bar, Paint::fill($ink->withAlpha($alpha)), self::THICKNESS * 0.5);
    }

    /** 该轴上的长度（纵向 ⇒ 高、横向 ⇒ 宽） */
    public static function extent(Size $size, Axis $axis): float
    {
        return $axis === Axis::Vertical ? $size->height : $size->width;
    }

    /** 该轴的交叉长度（纵向 ⇒ 宽）：条贴的那一侧从它扣 */
    public static function crossExtent(Size $size, Axis $axis): float
    {
        return $axis === Axis::Vertical ? $size->width : $size->height;
    }

    /** 点在该轴上的分量（拖动映射：纵向取 y、横向取 x） */
    public static function project(float $x, float $y, Axis $axis): float
    {
        return $axis === Axis::Vertical ? $y : $x;
    }
}
