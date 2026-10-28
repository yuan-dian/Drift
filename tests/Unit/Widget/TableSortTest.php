<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Widget\TableSort;
use Drift\Widget\TableSortDirection;
use PHPUnit\Framework\TestCase;

/**
 * 排序值对象：空态 / 方向 / 次键 / 无关列。
 *
 * ★ `thenBy()` 有一条反直觉的语义：**已存在的列会被移到末尾**（主键换成它），
 *   而不是"把它降为次键"。写成"降为次键"不会报错，只在多列排序时表现为
 *   "点了新列，主序还是旧列"。
 * ★ `TableSortDirection` 是**非 backed** 枚举 ⇒ `json_encode()` 返回 `false`
 *   （见 `SortTest` 的 `describe()`：断言 detail 只能用字符串拼）。
 */
final class TableSortTest extends TestCase
{
    public function testNoneAndDirectionOf(): void
    {
        $empty = TableSort::none();

        self::assertTrue($empty->isEmpty());
        self::assertNull($empty->primaryColumn());

        $desc = TableSort::by('name', TableSortDirection::Descending);
        self::assertSame(TableSortDirection::Descending, $desc->directionOf('name'));
        // 无关列 ⇒ null（不是 Ascending 的默认值）
        self::assertNull($desc->directionOf('id'));
        // 不给方向 ⇒ 默认升序
        self::assertSame(TableSortDirection::Ascending, TableSort::by('name')->directionOf('name'));
    }

    public function testThenByMovesExistingColumnToEnd(): void
    {
        $two = TableSort::by('a')->thenBy('b', TableSortDirection::Descending);
        self::assertSame(['a', 'b'], \array_column($two->keys(), 0));
        self::assertSame('a', $two->primaryColumn());

        // ★ 已存在的列被移到**末尾** ⇒ 主键变成 b
        $moved = $two->thenBy('a', TableSortDirection::Descending);
        self::assertSame('b', $moved->primaryColumn());
        self::assertSame(TableSortDirection::Descending, $moved->directionOf('a'));
        self::assertSame(['b', 'a'], \array_column($moved->keys(), 0));
    }
}
