<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 选择的**点法语义**：一次"指到第 i 行第 j 列 + 修饰键"应当得到什么选中集与锚点。
 *
 * 它是**所有输入的唯一出口** —— 鼠标点击、Shift 连选、拖动框选、`Ctrl+A` 全表
 * 都从这里算"点完之后该是什么"。留一份实现在 `TableState` 里，拖动那条路必然
 * 要复制一遍判断，然后两边迟早不一致。
 *
 * ## 一张表说清两种行为
 *
 * | 修饰键 | Rows（默认） | Cells |
 * |---|---|---|
 * | 无 | 替换为**该行** | 替换为**该格** |
 * | `Ctrl` | **反选**该行（复选框语义，Explorer / WPF 同款） | **累加**该格（Excel 同款：反选会让矩形挖洞） |
 * | `Shift` | 锚点行 → 目标行，**替换**为区间 | 锚点格 → 目标格，**替换**为矩形 |
 * | 单选模式 | 忽略修饰键，只选这一行 | 忽略修饰键，只选这一格 |
 *
 * ★ **`Ctrl` 在两种行为下不同**不是疏漏：整行是"勾选"（可反选），格是"加一块"
 *   （Excel 里 `Ctrl+点` 也是加，不反选）。要让格矩形能反选，存储就得支持
 *   "矩形挖洞"，而挖洞会让选区变成非矩形 —— 收益不抵复杂度，明确不做。
 *
 * ★ 入参是**行 / 列的 key 序列**而不是 `Table`：锚点与区间都按"当前行序"算
 *   （排序 / 过滤后行号会变），所以需要的是"某一刻的 key 序列"，不是控件本身。
 * ★ 下标**不压缩**：`$rowKeys` 与行一一对应，没 key 的行留 `null` 占位，
 *   这样调用方可以直接用行下标索引（压缩会引入两套下标，是错位 bug 的源头）。
 *
 * @internal 应用只认 `Table`
 */
final class TableGridPicker
{
    /**
     * @param list<string|int|null> $rowKeys 当前行序的 key（`null` = 该行没有身份）
     * @param list<string> $colKeys 当前列序的 key（`TableColumn::keyName()`）
     * @param ?TableSelectionMode $mode `null` = 表没启用选择
     * @param ?array{row: string|int, col: ?string} $anchor 上一次落下的锚点
     *
     * @return array{0: TableSelection, 1: ?array{row: string|int, col: ?string}}
     */
    public static function pick(
        TableSelection $current,
        array $rowKeys,
        array $colKeys,
        int $targetRow,
        int $targetCol,
        ?TableSelectionMode $mode,
        TableSelectionBehavior $behavior,
        ?array $anchor,
        bool $ctrl,
        bool $shift,
    ): array {
        if ($mode === null) {
            return [$current, $anchor];
        }

        if ($behavior === TableSelectionBehavior::Rows) {
            return self::pickRows($current, $rowKeys, $targetRow, $mode, $anchor, $ctrl, $shift);
        }

        return self::pickCells($current, $rowKeys, $colKeys, $targetRow, $targetCol, $mode, $anchor, $ctrl, $shift);
    }

    /**
     * 整行：与 2026-10-26 起的 `TableRowPicker` **逐字同语义**（那一批 9 条用例
     * 原样搬成了本类的测试）。
     *
     * @param list<string|int|null> $rowKeys
     * @param ?array{row: string|int, col: ?string} $anchor
     *
     * @return array{0: TableSelection, 1: ?array{row: string|int, col: ?string}}
     */
    private static function pickRows(
        TableSelection $current,
        array $rowKeys,
        int $targetRow,
        TableSelectionMode $mode,
        ?array $anchor,
        bool $ctrl,
        bool $shift,
    ): array {
        $key = $rowKeys[$targetRow] ?? null;
        if ($key === null) {
            return [$current, $anchor];
        }

        $cell = ['row' => $key, 'col' => null];

        // 单选忽略修饰键（Shift 连选是多选专属；Single 下 Ctrl 也不该累加）
        if ($mode === TableSelectionMode::Single) {
            return [$current->only([$key]), $cell];
        }

        // ★ 锚点用**旧值**算区间、用新值返回 —— 顺序与原实现逐字相同：
        //   "Shift 时锚点为空" ⇒ 本次只是把锚点落下来，选的是单行，不是区间。
        $anchorRow = $anchor['row'] ?? null;
        $nextAnchor = $anchor;
        if (!$ctrl && !$shift) {
            $nextAnchor = $cell;
        } elseif ($shift && $anchorRow === null) {
            $nextAnchor = $cell;
        }

        if ($shift && $anchorRow !== null) {
            $anchorIndex = self::indexOf($rowKeys, $anchorRow);
            if ($anchorIndex !== null) {
                return [TableSelection::of(self::between($rowKeys, $anchorIndex, $targetRow)), $nextAnchor];
            }
        }

        return [$ctrl ? $current->toggle($key) : $current->only([$key]), $nextAnchor];
    }

    /**
     * 单元格：锚点到目标扩成矩形（行 / 列各自按当前顺序取区间）。
     *
     * @param list<string|int|null> $rowKeys
     * @param list<string> $colKeys
     * @param ?array{row: string|int, col: ?string} $anchor
     *
     * @return array{0: TableSelection, 1: ?array{row: string|int, col: ?string}}
     */
    private static function pickCells(
        TableSelection $current,
        array $rowKeys,
        array $colKeys,
        int $targetRow,
        int $targetCol,
        TableSelectionMode $mode,
        ?array $anchor,
        bool $ctrl,
        bool $shift,
    ): array {
        $rowKey = $rowKeys[$targetRow] ?? null;
        $colKey = $colKeys[$targetCol] ?? null;
        if ($rowKey === null || $colKey === null) {
            return [$current, $anchor];
        }

        $cell = ['row' => $rowKey, 'col' => $colKey];

        if ($mode === TableSelectionMode::Single) {
            return [TableSelection::ofCells([$rowKey], [$colKey]), $cell];
        }

        $anchorRow = $anchor['row'] ?? null;
        $anchorCol = $anchor['col'] ?? null;

        $nextAnchor = $anchor;
        if (!$ctrl && !$shift) {
            $nextAnchor = $cell;
        } elseif ($shift && ($anchorRow === null || $anchorCol === null)) {
            $nextAnchor = $cell;
        }

        if ($shift && $anchorRow !== null && $anchorCol !== null) {
            $rowFrom = self::indexOf($rowKeys, $anchorRow);
            $colFrom = self::indexOf($colKeys, $anchorCol);
            if ($rowFrom !== null && $colFrom !== null) {
                return [
                    TableSelection::ofCells(
                        self::between($rowKeys, $rowFrom, $targetRow),
                        self::betweenKeys($colKeys, $colFrom, $targetCol),
                    ),
                    $nextAnchor,
                ];
            }
        }

        // Ctrl = 累加一格（已选 ⇒ 原样返回同一实例 ⇒ 调用方跳过通知）
        if ($ctrl) {
            if ($current->covers($rowKey, $colKey)) {
                return [$current, $nextAnchor];
            }

            return [$current->withRange(new TableRange([$rowKey], [$colKey]), false), $nextAnchor];
        }

        return [TableSelection::ofCells([$rowKey], [$colKey]), $nextAnchor];
    }

    /**
     * 全选：一次给出"所有行 × 所有列"。
     *
     * ★ 不做"逐行拼 key 列表"的 `withAll()`：那是 N 个 range、N 次比较；
     *   一块矩形一条 range 就够，且 `Ctrl+A` 之后再 `Shift+点` 的语义
     *   （替换成区间）也一致。
     *
     * @param list<string|int|null> $rowKeys
     * @param list<string> $colKeys
     */
    public static function selectAll(
        array $rowKeys,
        array $colKeys,
        TableSelectionBehavior $behavior,
    ): TableSelection {
        $rows = [];
        foreach ($rowKeys as $key) {
            if ($key !== null) {
                $rows[] = $key;
            }
        }

        if ($rows === []) {
            return TableSelection::none();
        }

        if ($behavior === TableSelectionBehavior::Rows) {
            return TableSelection::of($rows);
        }

        return TableSelection::ofCells($rows, $colKeys);
    }

    /**
     * @param list<string|int|null> $rowKeys
     */
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

    /**
     * 两个列下标之间的所有列 key（含两端）。
     *
     * @param list<string> $colKeys
     *
     * @return list<string>
     */
    public static function betweenKeys(array $colKeys, int $from, int $to): array
    {
        $low = \min($from, $to);
        $high = \max($from, $to);
        $keys = [];
        for ($i = $low; $i <= $high; $i++) {
            if (isset($colKeys[$i])) {
                $keys[] = $colKeys[$i];
            }
        }

        return $keys;
    }
}
