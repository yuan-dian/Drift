<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 表格的选择模式（§A.2 表单词汇的表格版）。
 *
 * ★ 用枚举而不是 `bool $multiple`：`Table::make(...)->selectionMode(true)` 在调用点
 *   读不出"true 是单选还是多选"，而 `TableSelectionMode::Single` 不用查文档。
 * ★ 与 `SemanticsRole` 的取值同源（单选 = `Radio` 语义、多选 = `Checkbox` 语义），
 *   阶段 2b 建语义树时直接映射，不再发明第二套。
 *
 * @api
 */
enum TableSelectionMode
{
    /** 单选：选中集里最多一个 key（点行 = 替换） */
    case Single;

    /** 多选：Ctrl 切换、Shift 从锚点连选、无修饰 = 替换（`WPF DataGrid` / `ag-Grid` 同款） */
    case Multiple;
}
