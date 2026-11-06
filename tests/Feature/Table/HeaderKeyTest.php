<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSort;
use Drift\Widget\TableSortDirection;
use Drift\Widget\TableState;

/**
 * 表头的键盘入口（§61）：`↑` 从首行进表头、`←→` 在可排列间移动、`Enter` 三态循环、`↓` 回行区。
 *
 * ★ 夹具是 `Fixtures::sortable()`：三列里 `id` / `name` 可排、`city` **不可排** ——
 *   正是"跳过不可排列"这条判据的靶子（全可排的表测不出来）。
 * ★ 判据全部落在**返回吃键与否** + 状态字段上，不调私有方法：
 *   `handleFocusKey()` 就是 `keyboard()` 回调实际走的那个入口。
 *
 * ★ 反例自检（每条都实测过红，见 §61.6）：
 *   - 去掉"已在首行"这个条件 ⇒ 从第 2 行按 ↑ 直接跳进表头；
 *   - `sortableColumns()` 返回全部列 ⇒ `→` 停在不可排的 `city` 上；
 *   - 表头焦点框改用行区矩形 ⇒ 几何断言（top=0 而非 −表头高）红。
 */
final class HeaderKeyTest extends SmokeTestCase
{
    /** @return array{0: \Drift\Widget\Element, 1: TableState, 2: SortSink} */
    private static function mountSorted(?TableSort $sort = null): array
    {
        $sink = new SortSink();
        $el = Fixtures::mount(Fixtures::sortable($sort ?? TableSort::none(), $sink));
        $state = Fixtures::stateOf($el);
        $state?->didChangeFocus(true);
        Fixtures::pump($el);

        return [$el, $state, $sink];
    }

    private static function press(?TableState $state, KeyChord $chord): bool
    {
        return $state?->handleFocusKey(KeyEvent::down($chord)) ?? false;
    }

    /** ① 没有可排序列 ⇒ 表头**没有站**，`↑` 在首行吃掉不动 */
    public function testHeaderHasNoStopWithoutSortableColumn(): void
    {
        $el = Fixtures::mount(Fixtures::build(6));
        $state = Fixtures::stateOf($el);
        $state?->didChangeFocus(true);
        Fixtures::pump($el);

        $ate = self::press($state, KeyChord::up());

        check(
            'Table · 表头键盘：一列都不可排 ⇒ 首行 ↑ 不产生表头光标（吃掉不动）',
            $ate
                && $state?->headerFocusedColumn() === null
                && $state?->focusedCell() === ['row' => 0, 'col' => 0],
            'ate=' . ($ate ? 'y' : 'n')
                . ' header=' . \json_encode($state?->headerFocusedColumn())
                . ' cell=' . \json_encode($state?->focusedCell()),
        );

        $this->flushChecks();
    }

    /** ② 首行 ↑ ⇒ 进表头，落在同一列；再 ↑ 吃掉不动 */
    public function testFirstRowUpEntersHeaderOnSameColumn(): void
    {
        [, $state] = self::mountSorted();

        $ate = self::press($state, KeyChord::up());
        $got = $state?->headerFocusedColumn();

        check(
            'Table · 表头键盘：首行 ↑ ⇒ 光标进表头，落在**同一列**（0）',
            $ate && $got === 0 && $state?->focusedCell() === null,
            'ate=' . ($ate ? 'y' : 'n') . ' header=' . \json_encode($got),
        );

        $ateAgain = self::press($state, KeyChord::up());
        check(
            'Table · 表头键盘：表头里再按 ↑ 吃掉不动（单行表头没有上一行）',
            $ateAgain && $state?->headerFocusedColumn() === 0,
            'ate=' . ($ateAgain ? 'y' : 'n') . ' header=' . \json_encode($state?->headerFocusedColumn()),
        );

        $this->flushChecks();
    }

    /** ③ 从第 2 行按 ↑ 只是回到首行 —— 不是"越过" */
    public function testUpFromSecondRowStopsAtFirstRow(): void
    {
        [, $state] = self::mountSorted();
        self::press($state, KeyChord::down());

        check(
            'Table · 表头键盘：先落到第 2 行',
            $state?->focusedCell() === ['row' => 1, 'col' => 0],
            'cell=' . \json_encode($state?->focusedCell()),
        );

        self::press($state, KeyChord::up());

        check(
            'Table · 表头键盘：从第 2 行按 ↑ 只**回到首行**，不进表头',
            $state?->headerFocusedColumn() === null && $state?->focusedCell() === ['row' => 0, 'col' => 0],
            'header=' . \json_encode($state?->headerFocusedColumn())
                . ' cell=' . \json_encode($state?->focusedCell()),
        );

        $this->flushChecks();
    }

    /** ④ `←→` 只在**可排**列间移动，`city`（第 3 列）不是站 */
    public function testArrowsSkipUnsortedColumns(): void
    {
        [, $state] = self::mountSorted();
        self::press($state, KeyChord::up());

        $toName = self::press($state, KeyChord::right());
        check(
            'Table · 表头键盘：→ 从 id 到 name（相邻可排列）',
            $toName && $state?->headerFocusedColumn() === 1,
            'header=' . \json_encode($state?->headerFocusedColumn()),
        );

        $atEdge = self::press($state, KeyChord::right());
        check(
            'Table · 表头键盘：→ 在末个可排列（name）吃掉不动，**跳过**不可排的 city',
            $atEdge && $state?->headerFocusedColumn() === 1,
            'ate=' . ($atEdge ? 'y' : 'n') . ' header=' . \json_encode($state?->headerFocusedColumn()),
        );

        self::press($state, KeyChord::home());
        check(
            'Table · 表头键盘：Home 到首个可排列（id）',
            $state?->headerFocusedColumn() === 0,
            'header=' . \json_encode($state?->headerFocusedColumn()),
        );

        self::press($state, KeyChord::end());
        check(
            'Table · 表头键盘：End 到末个可排列（name，不是 city）',
            $state?->headerFocusedColumn() === 1,
            'header=' . \json_encode($state?->headerFocusedColumn()),
        );

        $this->flushChecks();
    }

    /** ⑤ `Enter` 三态循环，且**光标留在表头**（排序不该把人踢回行区） */
    public function testEnterCyclesSortAndStaysInHeader(): void
    {
        [, $state, $sink] = self::mountSorted();
        self::press($state, KeyChord::up());
        $ate = self::press($state, KeyChord::enter());

        check(
            'Table · 表头键盘：无排序时 Enter ⇒ 上报 `id` 升序（列序号 0），且吃掉按键',
            $ate
                && $sink->last !== null
                && $sink->last->primaryColumn() === 'id'
                && $sink->last->primaryDirection() === TableSortDirection::Ascending
                && $sink->columnIndex === 0,
            'sink=' . \json_encode($sink->last?->keys() === null ? null : \array_map(
                static fn(array $k): string => $k[0] . ($k[1] === TableSortDirection::Ascending ? '↑' : '↓'),
                $sink->last?->keys() ?? [],
            )),
        );

        check(
            'Table · 表头键盘：排序上报后光标**仍在表头**（不被踢回行区）',
            $state?->headerFocusedColumn() === 0 && $state?->focusedCell() === null,
            'header=' . \json_encode($state?->headerFocusedColumn()),
        );

        $this->flushChecks();
    }

    /** ⑥ 已经升序 ⇒ Enter 上报降序；已经降序 ⇒ Enter 上报"无排序" */
    public function testEnterContinuesTheThreeStateCycle(): void
    {
        [, $state, $sink] = self::mountSorted(TableSort::by('id', TableSortDirection::Ascending));
        self::press($state, KeyChord::up());
        self::press($state, KeyChord::enter());

        check(
            'Table · 表头键盘：已升序 ⇒ Enter 上报降序',
            $sink->last?->primaryDirection() === TableSortDirection::Descending,
            'dir=' . ($sink->last?->primaryDirection() === TableSortDirection::Descending ? 'desc' : 'other'),
        );

        [, $state2, $sink2] = self::mountSorted(TableSort::by('id', TableSortDirection::Descending));
        self::press($state2, KeyChord::up());
        self::press($state2, KeyChord::enter());

        check(
            'Table · 表头键盘：已降序 ⇒ Enter 上报"无排序"（三态闭环）',
            $sink2->last?->isEmpty() === true,
            'empty=' . ($sink2->last?->isEmpty() === true ? 'y' : 'n'),
        );

        $this->flushChecks();
    }

    /**
     * ⑦ 从**不可排**的列进表头 ⇒ 落到最近的可排列；`↓` 回的是**夹取后**那一列。
     *
     * ★ 这条是"进表头时把列一起带过去"的唯一判据：不同步的话，`↓` 会回到
     *   `city`（原来那一列），光标从表头跳到了旁边一格。
     */
    public function testEnteringHeaderFromUnsortedColumnSnapsToNearestSortable(): void
    {
        [, $state] = self::mountSorted();
        self::press($state, KeyChord::right());
        self::press($state, KeyChord::right());

        check(
            'Table · 表头键盘：光标先到不可排的 city（第 3 列）',
            $state?->focusedCell() === ['row' => 0, 'col' => 2],
            'cell=' . \json_encode($state?->focusedCell()),
        );

        self::press($state, KeyChord::up());
        check(
            'Table · 表头键盘：从 city 进表头 ⇒ 夹到最近的**可排**列（name，第 2 列）',
            $state?->headerFocusedColumn() === 1,
            'header=' . \json_encode($state?->headerFocusedColumn()),
        );

        self::press($state, KeyChord::down());
        check(
            'Table · 表头键盘：↓ 回行区 ⇒ 落在**夹取后**那一列（name），不是原来的 city',
            $state?->focusedCell() === ['row' => 0, 'col' => 1],
            'cell=' . \json_encode($state?->focusedCell()),
        );

        $this->flushChecks();
    }

    /** ⑧ `↓` 回行区，且回到**同一列**；表头框随之消失 */
    public function testDownReturnsToRowAreaOnSameColumn(): void
    {
        [$el, $state] = self::mountSorted();
        self::press($state, KeyChord::up());
        self::press($state, KeyChord::right());
        Fixtures::pump($el);

        $box = Fixtures::boxOf($el);
        $headerRect = $box?->headerFocusRect();
        check(
            'Table · 表头键盘：表头焦点框走**表头区坐标**（top=0、高=表头高），行区框为空',
            $headerRect !== null
                && abs($headerRect->top) < 0.01
                && abs($headerRect->height - Fixtures::HEADER_H) < 0.01
                && $box?->focusRect() === null,
            $headerRect === null
                ? 'null'
                : \sprintf('(%.1f,%.1f) %.1f×%.1f', $headerRect->left, $headerRect->top, $headerRect->width, $headerRect->height),
        );

        $ate = self::press($state, KeyChord::down());
        Fixtures::pump($el);

        check(
            'Table · 表头键盘：↓ 回行区首行、停在**同一列**（1），表头框消失',
            $ate
                && $state?->headerFocusedColumn() === null
                && $state?->focusedCell() === ['row' => 0, 'col' => 1]
                && Fixtures::boxOf($el)?->headerFocusRect() === null
                && Fixtures::boxOf($el)?->focusRect() !== null,
            'header=' . \json_encode($state?->headerFocusedColumn())
                . ' cell=' . \json_encode($state?->focusedCell()),
        );

        $this->flushChecks();
    }

    /** ⑧ 指针落在数据行 ⇒ 表头光标让位（不能同时亮两个焦点） */
    public function testPointerOnRowClearsHeaderFocus(): void
    {
        $sink = new SortSink();
        $selSink = new SelectionSink();
        $el = Fixtures::mount(Fixtures::sortable(TableSort::none(), $sink, TableSelection::none(), $selSink));
        $state = Fixtures::stateOf($el);
        $state?->didChangeFocus(true);
        Fixtures::pump($el);

        self::press($state, KeyChord::up());

        check(
            'Table · 表头键盘：指针点行之前光标在表头',
            $state?->headerFocusedColumn() === 0,
            'header=' . \json_encode($state?->headerFocusedColumn()),
        );

        Fixtures::tapRow($el, 2, PointerEvent::down(30.0, Fixtures::HEADER_H + Fixtures::ROW_H * 2.0 + 10.0));
        Fixtures::pump($el);

        check(
            'Table · 表头键盘：指针落数据行 ⇒ 表头光标清空、行区光标落位',
            $state?->headerFocusedColumn() === null && $state?->focusedCell() !== null,
            'header=' . \json_encode($state?->headerFocusedColumn())
                . ' cell=' . \json_encode($state?->focusedCell()),
        );

        $this->flushChecks();
    }

    /** ⑨ 失焦保留位置、不画框（与行区光标同一条纪律） */
    public function testBlurKeepsPositionButDrawsNoRing(): void
    {
        [$el, $state] = self::mountSorted();
        self::press($state, KeyChord::up());

        $state?->didChangeFocus(false);
        Fixtures::pump($el);

        check(
            'Table · 表头键盘：失焦 ⇒ 表头框不画，但位置保留（Tab 回来还在这列）',
            Fixtures::boxOf($el)?->headerFocusRect() === null
                && $state?->headerFocusedColumn() === 0,
            'rect=' . (Fixtures::boxOf($el)?->headerFocusRect() === null ? 'null' : '有')
                . ' header=' . \json_encode($state?->headerFocusedColumn()),
        );

        $this->flushChecks();
    }
}
