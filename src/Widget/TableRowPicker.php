<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 行选择的**点法语义**：一次"指到第 i 行 + 修饰键"应当得到什么选中集与锚点。
 *
 * | 修饰键 | 单选 | 多选 |
 * |---|---|---|
 * | 无 | 替换为该行 | 替换为该行（`WPF DataGrid` / `ag-Grid` 的默认） |
 * | Ctrl | 同无修饰 | 反选该行 |
 * | Shift | 同无修饰 | 从锚点到该行**替换**为区间 |
 *
 * ★ 抽成纯函数的理由：它是**三种输入共用的唯一出口** —— 鼠标点击、Shift 连选、
 *   以及拖动框选（拖动就是"连续的 Shift 点击"）。留一份实现在 State 里，
 *   拖动那条路必然要复制一遍判断，然后两边迟早不一致。
 *
 * ★ 入参是**行序 key 列表**而不是 `Table`：锚点与区间都按"当前行序"算（排序/过滤
 *   后行号会变），所以需要的是"某一刻的 key 序列"，不是控件本身。
 *
 * ★ 下标**不压缩**：`$rowKeys` 与行一一对应，没 key 的行留 `null` 占位，
 *   这样调用方可以直接用行下标索引（压缩会引入两套下标，是错位 bug 的源头）。
 *
 * @internal 应用只认 `Table`
 */
final class TableRowPicker
{
    /**
     * @param list<string|int|null> $rowKeys 当前行序的 key（`null` = 该行没有身份）
     * @param ?TableSelectionMode $mode `null` = 表没启用选择
     * @param string|int|null $anchorKey 上一次无修饰点击落下的锚点
     *
     * @return array{0: TableSelection, 1: string|int|null} `[新选中集, 新锚点]`
     */
    public static function pick(
        TableSelection $current,
        array $rowKeys,
        int $targetIndex,
        ?TableSelectionMode $mode,
        string|int|null $anchorKey,
        bool $ctrl,
        bool $shift,
    ): array {
        $key = $rowKeys[$targetIndex] ?? null;
        if ($key === null || $mode === null) {
            return [$current, $anchorKey];
        }

        // 单选忽略修饰键（Shift 连选是多选专属；Single 下 Ctrl 也不该累加）
        if ($mode === TableSelectionMode::Single) {
            return [$current->only([$key]), $key];
        }

        // ★ 锚点用**旧值**算区间、用新值返回 —— 顺序与前身逐字相同：
        //   "Shift 时锚点为空" ⇒ 本次只是把锚点落下来，选的是单行，不是区间。
        $nextAnchor = $anchorKey;
        if (!$ctrl && !$shift) {
            $nextAnchor = $key;
        } elseif ($shift && $anchorKey === null) {
            $nextAnchor = $key;
        }

        if ($shift && $anchorKey !== null) {
            $anchorIndex = self::indexOf($rowKeys, $anchorKey);
            if ($anchorIndex !== null) {
                return [TableSelection::of(self::between($rowKeys, $anchorIndex, $targetIndex)), $nextAnchor];
            }
        }

        return [$ctrl ? $current->toggle($key) : $current->only([$key]), $nextAnchor];
    }

    /** @param list<string|int|null> $rowKeys */
    public static function indexOf(array $rowKeys, string|int $key): ?int
    {
        foreach ($rowKeys as $index => $candidate) {
            if ($candidate === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * 两个行下标之间的所有 key（含两端，跳过无身份的孔洞）。
     *
     * @param list<string|int|null> $rowKeys
     *
     * @return list<string|int>
     */
    public static function between(array $rowKeys, int $from, int $to): array
    {
        $low = \min($from, $to);
        $high = \max($from, $to);
        $keys = [];
        for ($i = $low; $i <= $high; $i++) {
            $key = $rowKeys[$i] ?? null;
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
