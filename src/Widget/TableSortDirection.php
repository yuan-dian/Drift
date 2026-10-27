<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 排序方向（`TableSort` 的一维）。
 *
 * ★ 用枚举而不是 `bool $asc`：`Table::onSortChange(fn(..., bool $asc) => ...)` 在
 *   调用点读不出 true 是升序还是降序（`TableSelectionMode` 同一条理由）。
 * ★ 没有 `None` 成员：那会让"某列不参与排序"与"整表没有排序"两件事混在一个维度上
 *   （`TableSort::directionOf()` 返回 `null` 就是"这列没参与"，不需要第三个枚举值）。
 *
 * @api
 */
enum TableSortDirection
{
    /** 升序（小 → 大、A → Z） */
    case Ascending;

    /** 降序（大 → 小、Z → A） */
    case Descending;
}
