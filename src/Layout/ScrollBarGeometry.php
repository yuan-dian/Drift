<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Rect;
use Drift\Geometry\Size;

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
    public const THICKNESS = 6.0;

    /** 条离视口边缘的空隙 */
    public const MARGIN = 3.0;

    /** thumb 最短长度（内容再长也留这么一截，否则细到抓不住） */
    public const MIN_THUMB = 24.0;

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
     */
    public static function thumbRect(Size $size, float $maxScroll, float $offset, bool $vertical): ?Rect
    {
        if ($maxScroll <= 0.0) {
            return null;
        }

        $track = $vertical ? $size->height : $size->width;
        $cross = $vertical ? $size->width : $size->height;
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
}
