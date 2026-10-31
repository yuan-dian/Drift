<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 选择的**单位**：整行还是单个格子。
 *
 * 与 `TableSelectionMode`（单选 / 多选）是**两个正交的轴** —— 这是成熟控件的共同
 * 形状，不是本仓发明的：
 *
 * | 框架 | 单位 | 数量 |
 * |---|---|---|
 * | Qt `QAbstractItemView` | `SelectionBehavior`（Rows / Items / Columns） | `SelectionMode` |
 * | WPF `DataGrid` | `SelectionUnit`（FullRow / Cell） | `SelectionMode` |
 * | ag-Grid | `rowSelection` | `rangeSelection` |
 *
 * ★ **为什么不塞进 `TableSelectionMode`**：`Single`/`Multiple` 与 `Rows`/`Cells`
 *   能组合出四种真实用法（单选整行 / 多选整行 / 单选单格 / 多选单格矩形），
 *   合成一个枚举就要 4 个 case，且以后加"选列"会变成 8 个。
 *
 * @api
 */
enum TableSelectionBehavior
{
    /** 整行（默认）—— 与 2026-10-26 起的行为逐字相同 */
    case Rows;

    /** 单元格 —— 点选一格、Shift / 拖动扩成矩形、`Ctrl+A` 全表 */
    case Cells;
}
