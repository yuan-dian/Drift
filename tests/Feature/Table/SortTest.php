<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\CursorShape;
use Drift\Widget\Element;
use Drift\Widget\Icon;
use Drift\Widget\IconName;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSort;
use Drift\Widget\TableSortDirection;

/**
 * 表头排序（受控三态 + 箭头）。
 *
 * ★ 控件**不排序**：点击只上报"点完之后该是什么"，重排由应用做
 *   （`docs/guides/widgets.md` §13）。所以这里验的是"下一态"与"箭头"，
 *   不是数据顺序 —— 控件无从知道某列该按数值还是按拼音比。
 *
 * ★ 反例自检（两条都实测为红）：三态循环改成两态（升 ⇄ 降，不取消）⇒ 第三条红；
 *   箭头改成"可排的列都画" ⇒ "没排序时表头不画箭头"红。
 * ⚠️ 本块验不出 `ownsPress`：直接调 `pointerHandlerValue()` 绕过了按下路由
 *   —— 行点击那次就是这样漏的（只有真机点得出"没反应"）。
 *
 * ★ 三条子进程断言（`sortable()` 没接 `onSortChange` / `sort()` 指向不存在的列 /
 *   空列 key）留在 `tools/smoke.php`：判据是退出码，与"改 Table 只跑 Table"无关。
 */
final class SortTest extends SmokeTestCase
{
    /** `TableSortDirection` 是**非 backed** 枚举 ⇒ 不能 `json_encode`（会返回 false） */
    private static function describe(?TableSort $sort): string
    {
        if ($sort === null) {
            return '没有上报';
        }
        if ($sort->isEmpty()) {
            return '（无排序）';
        }

        $parts = [];
        foreach ($sort->keys() as [$key, $dir]) {
            $parts[] = $key . ($dir === TableSortDirection::Ascending ? '↑' : '↓');
        }

        return \implode(' → ', $parts);
    }

    public function testOnlySortableColumnsGetPointer(): void
    {
        $sink = new SortSink();
        $el = Fixtures::mount(Fixtures::sortable(TableSort::none(), $sink));
        $cells = Fixtures::headerCellsOf($el);

        check(
            'Table · 可排列的表头挂 pointer + PointingHand，不可排列不挂（3 列 ⇒ 2 个可点）',
            \count($cells) === 3
                && $cells[0]->widget()->pointerHandlerValue() !== null
                && $cells[1]->widget()->pointerHandlerValue() !== null
                && $cells[2]->widget()->pointerHandlerValue() === null
                && $cells[0]->widget()->cursorShape() === CursorShape::PointingHand,
            \sprintf('cell=%d', \count($cells)),
        );

        $this->flushChecks();
    }

    public function testThreeStateCycle(): void
    {
        $tableMount = Fixtures::mount(...);
        $headerCellsOf = Fixtures::headerCellsOf(...);
        $tapHeader = Fixtures::tapHeader(...);

        // ④ 三态循环：无 → 升 → 降 → 无
        $sink = new SortSink();
        $el = $tableMount(Fixtures::sortable(TableSort::none(), $sink));
        $tapHeader($headerCellsOf($el)[0]);
        check(
            'Table · 点表头（无排序）⇒ 上报该列升序',
            $sink->last?->primaryColumn() === 'id'
                && $sink->last?->primaryDirection() === TableSortDirection::Ascending,
            self::describe($sink->last),
        );

        $sink = new SortSink();
        $el = $tableMount(Fixtures::sortable(TableSort::by('id', TableSortDirection::Ascending), $sink));
        $tapHeader($headerCellsOf($el)[0]);
        check(
            'Table · 再点（已升序）⇒ 上报降序',
            $sink->last?->primaryDirection() === TableSortDirection::Descending,
            self::describe($sink->last),
        );

        $sink = new SortSink();
        $el = $tableMount(Fixtures::sortable(TableSort::by('id', TableSortDirection::Descending), $sink));
        $tapHeader($headerCellsOf($el)[0]);
        check(
            'Table · 第三次点（已降序）⇒ 上报取消排序（TableSort::none()）',
            $sink->last !== null && $sink->last->isEmpty(),
            self::describe($sink->last),
        );

        // ⑤ 点非主键列 ⇒ 升格为主键升序
        $sink = new SortSink();
        $el = $tableMount(Fixtures::sortable(TableSort::by('id', TableSortDirection::Ascending), $sink));
        $tapHeader($headerCellsOf($el)[1]);
        check(
            'Table · 点另一列 ⇒ 该列升格为主键升序（不是"点一下没反应"）',
            $sink->last?->primaryColumn() === 'name'
                && $sink->last?->primaryDirection() === TableSortDirection::Ascending
                && $sink->last?->directionOf('id') === null,
            self::describe($sink->last),
        );
        check(
            'Table · 上报带列序号（应用按"第几列"分派比较规则时直接当下标用）',
            $sink->columnIndex === 1,
            '实际 ' . $sink->columnIndex,
        );

        $this->flushChecks();
    }

    public function testArrowsFollowSortState(): void
    {
        $sink = new SortSink();
        $ascEl = Fixtures::mount(Fixtures::sortable(TableSort::by('id', TableSortDirection::Ascending), $sink));
        $sink2 = new SortSink();
        $descEl = Fixtures::mount(Fixtures::sortable(TableSort::by('id', TableSortDirection::Descending), $sink2));
        $sink3 = new SortSink();
        $plainEl = Fixtures::mount(Fixtures::sortable(TableSort::none(), $sink3));

        $ascIcon = findElement($ascEl, static fn(Element $e): bool => $e->widget() instanceof Icon);
        $descIcon = findElement($descEl, static fn(Element $e): bool => $e->widget() instanceof Icon);

        check(
            'Table · 升序列表头画 ArrowUp、降序画 ArrowDown',
            $ascIcon?->widget() instanceof Icon
                && $ascIcon->widget()->iconName() === IconName::ArrowUp
                && $descIcon?->widget() instanceof Icon
                && $descIcon->widget()->iconName() === IconName::ArrowDown,
            \sprintf(
                '升=%s / 降=%s',
                $ascIcon === null ? '无' : $ascIcon->widget()->iconName()->value,
                $descIcon === null ? '无' : $descIcon->widget()->iconName()->value,
            ),
        );
        check(
            'Table · 没排序时表头不画箭头（"有箭头"就等于"这列在排序"）',
            findElement($plainEl, static fn(Element $e): bool => $e->widget() instanceof Icon) === null,
            '表头里出现了 Icon',
        );

        $this->flushChecks();
    }

    /** ⑦ 表头点击不碰行选择（表头不是行 —— 两条通道互不串） */
    public function testHeaderClickDoesNotTouchRowSelection(): void
    {
        $selSink = new SelectionSink();
        $sortSink = new SortSink();
        $el = Fixtures::mount(
            Table::make(
                [TableColumn::make('id', 'ID')->field('id')->sortable()],
                Fixtures::rows(6),
            )->keyOf(static fn(array $row): int => $row['id'])
                ->rowHeight(Fixtures::ROW_H)
                ->headerHeight(Fixtures::HEADER_H)
                ->selection(TableSelection::none())
                ->onSelectionChange($selSink->receive(...))
                ->onSortChange($sortSink->receive(...)),
        );

        Fixtures::tapHeader(Fixtures::headerCellsOf($el)[0]);

        check(
            'Table · 点表头不改变行选择（表头点击与行点击是两条通道）',
            $selSink->last === null,
            $selSink->last === null ? '选择未变' : \json_encode($selSink->last->keys()),
        );

        $this->flushChecks();
    }
}
