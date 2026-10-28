<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * 列宽的分配算术：**策略初值 → 分剩余 → 每列夹 → 用户拖拽覆盖**。
 *
 * ★ 纯函数、不吃 `TableColumn`：入参是已经摊平的规格数组 ⇒ 可以脱离 `Table` / `State`
 *   单测。这四五层规则里有三个坑（override 不参与 flex 重算、override 静默忽略、
 *   clamp 之后总和会变），埋在 2000 行的 State 里只能靠真机试出来。
 *
 * ★ 为什么规格要摊平而不是直接收 `TableColumn[]`：`Layout` 不认 `Widget` ——
 *   反过来依赖会成环。摊平的那 8 行放在调用方，本类只做算术。
 *
 * @api 内部使用
 */
final class ColumnWidths
{
    /**
     * @param list<array{key: string, flex: bool, weight: float, px: float, min: ?float, max: ?float}> $specs
     *        `px` 对 flex 列无意义（传 0 即可）；`min/max` 为 `null` = 不夹
     * @param array<string, float> $overrides 列 key ⇒ 用户拖出来的宽（第 ④ 层）
     * @param float $viewportW 视口宽（不含列间距）
     * @param float $gap 单个列间距（调用方已乘 `n-1` 之外的那一份）
     *
     * @return array<int, float> 与 `$specs` 同序的宽度
     */
    public static function resolve(array $specs, array $overrides, float $viewportW, float $gap): array
    {
        $n = \count($specs);
        $avail = \max(0.0, $viewportW - $gap * \max(0, $n - 1));

        /** @var array<int, float> $widths */
        $widths = [];
        $used = 0.0;
        $flexSum = 0.0;

        // ④ 用户拖出来的宽度**最优先**，且**不参与 flex 分配**（拖过就变固定宽）。
        //   ★ 指向不存在的列时**静默忽略**（与 `sort()` 不同）：override 是"历史
        //     偏好"——列被删掉之后存盘的旧值还在，是正常情况，不是拼写错误。
        foreach ($specs as $i => $spec) {
            $override = $overrides[$spec['key']] ?? null;
            if ($override !== null) {
                $widths[$i] = $override;
                $used += $override;

                continue;
            }

            if ($spec['flex']) {
                $widths[$i] = 0.0;   // 占位：等固定列量完才知道剩余
                $flexSum += $spec['weight'];

                continue;
            }

            $widths[$i] = $spec['px'];
            $used += $spec['px'];
        }

        // ② 分剩余。剩余为负（固定列已超宽）⇒ flex 列拿 0，溢出交给裁剪 ——
        //   不在这里报错：阶段 1 的裁决是"装不下就裁"（有横滚之后这里才会变）。
        if ($flexSum > 0.0) {
            $free = \max(0.0, $avail - $used);

            foreach ($specs as $i => $spec) {
                // ★ 被 override 的列**不参与重算**：它的规格可能还是 flex，只按 flex
                //   判会让第 ④ 层被第 ② 层覆盖回去（真机表现：拖宽一列，松手后又弹回
                //   原值 —— 因为 override 只挡住了"初值"没挡住"重算"）。
                if (!$spec['flex'] || isset($overrides[$spec['key']])) {
                    continue;
                }

                $widths[$i] = $free * $spec['weight'] / $flexSum;
            }
        }

        // ③ 每列夹（★ 夹完总和会变，所以调用方要重算内容宽）
        foreach ($specs as $i => $spec) {
            $widths[$i] = self::clamp($widths[$i], $spec['min'], $spec['max']);
        }

        return $widths;
    }

    /** 第 ③ 层：夹进 `[min, max]`（`null` = 那一侧不夹） */
    public static function clamp(float $width, ?float $min, ?float $max): float
    {
        $w = \max(0.0, $width);

        if ($min !== null) {
            $w = \max($min, $w);
        }

        if ($max !== null) {
            $w = \min($max, $w);
        }

        return $w;
    }
}
