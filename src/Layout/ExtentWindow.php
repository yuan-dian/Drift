<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * 一串"长度可变"的段，落在视口里的窗口是哪些。
 *
 * ★ 为什么不是行虚拟化那套闭式：行高固定 ⇒ `first = floor(offset / rowH)` 是 O(1)；
 *   列宽可变（`Fixed` / `Flex` / 拖拽覆盖）⇒ 必须累加才知道第 j 列从哪开始。
 *   形状不同，抽不成同一个概念，但"求可见窗口"这件事本身与 Table 无关。
 *
 * ★ 纯函数、不持有状态：同一组入参永远同一结果 ⇒ 可以脱离 `Table` / `State` 单测，
 *   也不必挤进 2196 行的 `TableState`。
 *
 * ★ `$cache`（两端各预取多远）不是可选优化，是**正确性前提之一**：没有它，
 *   偏移动一像素窗口就变 ⇒ 每滚一帧都重建（A 批第 1 项同样的教训）。
 *
 * @api 内部使用
 */
final class ExtentWindow
{
    /**
     * @param list<float> $extents 每段长度（不含 gap）
     * @param float $gap 段间空隙
     * @param float $offset 内容坐标下的可视起点（调用方负责已夹取）
     * @param float $viewport 可视长度
     * @param float $cache 两端各预取多远
     *
     * @return array{0: int, 1: int, 2: float} `[起, 止)`（止为开区间）+ 起点段在内容坐标里的位置
     */
    public static function visible(
        array $extents,
        float $gap,
        float $offset,
        float $viewport,
        float $cache,
    ): array {
        $n = \count($extents);
        if ($n === 0) {
            return [0, 0, 0.0];
        }

        $from = \max(0.0, $offset - $cache);
        $to = $offset + $viewport + $cache;

        $pos = 0.0;
        $first = -1;
        $leading = 0.0;
        $end = $n;

        for ($i = 0; $i < $n; $i++) {
            if ($pos >= $to) {
                $end = $i;
                break;
            }
            // 用 `>` 而不是 `>=`：长度为 0 的段不占可见区，别把它算进窗口。
            if ($first < 0 && $pos + $extents[$i] > $from) {
                $first = $i;
                $leading = $pos;
            }
            $pos += $extents[$i] + $gap;
        }

        // 全落在 `from` 之前（只有"全零长 + 零 gap"这种退化输入，偏移已被夹过）
        // ⇒ 退回**全造**：宁可少省，不露空段。
        if ($first < 0) {
            return [0, $n, 0.0];
        }

        return [$first, $end, $leading];
    }

    /**
     * `[起, 止)` 这一段扫过去占的内容长度（含内部 gap，不含两端的 gap）。
     *
     * @param list<float> $extents
     */
    public static function extentOf(array $extents, int $first, int $end, float $gap): float
    {
        $sum = 0.0;
        for ($i = $first; $i < $end; $i++) {
            $sum += $extents[$i];
        }
        $k = $end - $first;
        if ($k > 1) {
            $sum += $gap * ($k - 1);
        }

        return $sum;
    }
}
