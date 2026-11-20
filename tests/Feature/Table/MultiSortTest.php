<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use App\Showcase\Pages\TablePage;
use Drift\Dev\Smoke\App\ShowcaseCaps;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Element;
use Drift\Widget\Icon;
use Drift\Widget\IconName;
use Drift\Widget\TableSort;
use Drift\Widget\TableSortDirection;
use Drift\Widget\TableState;

/**
 * 多键排序（§62）：`Shift+点表头` / `Shift+Enter` 追加次键，两条入口共用一份三态。
 *
 * ★ 夹具是 `Fixtures::sortable()`：三列里 `id` / `name` 可排、`city` 不可排。
 * ★ 值对象层的断言是"为什么不能复用 `thenBy()`"的钉子：`cycleMulti()` 改方向时
 *   必须**保持优先级位置**，否则把主键降成次键，整表顺序会突然大变。
 * ★ 应用侧那条不是走控件：`showcase` 只按主键排的话，画面上两列有箭头、数据
 *   却只按一列排 —— 控件层全绿也验不出，所以直接验重排结果。
 */
final class MultiSortTest extends SmokeTestCase
{
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

    /** @return list<Icon> */
    private static function iconsOf(Element $root): array
    {
        $out = [];
        $root->visitChildren(static function (Element $child) use (&$out): void {
            if ($child->widget() instanceof Icon) {
                $out[] = $child->widget();
            }

            foreach (self::iconsOf($child) as $nested) {
                $out[] = $nested;
            }
        });

        return $out;
    }

    /** @return array{0: Element, 1: ?TableState, 2: SortSink} */
    private static function mountSorted(TableSort $sort): array
    {
        $sink = new SortSink();
        $el = Fixtures::mount(Fixtures::sortable($sort, $sink));
        $state = Fixtures::stateOf($el);
        $state?->didChangeFocus(true);
        Fixtures::pump($el);

        return [$el, $state, $sink];
    }

    private static function press(?TableState $state, KeyChord $chord): bool
    {
        return $state?->handleFocusKey(KeyEvent::down($chord)) ?? false;
    }

    /** ① 未参与的列追加到最低优先级，主键不动 */
    public function testCycleMultiAppendsAsLowestPriority(): void
    {
        $fromNone = TableSort::none()->cycleMulti('id');
        check(
            'TableSort · 无排序时 Shift+点 ⇒ 该列升序（空序列里它就是主键）',
            $fromNone->primaryColumn() === 'id'
                && $fromNone->primaryDirection() === TableSortDirection::Ascending,
            self::describe($fromNone),
        );

        $two = TableSort::by('id')->cycleMulti('name');
        check(
            'TableSort · 已有主键时 Shift+点另一列 ⇒ 追加为次键、主键不变',
            $two->primaryColumn() === 'id'
                && $two->directionOf('name') === TableSortDirection::Ascending
                && \count($two->keys()) === 2,
            self::describe($two),
        );

        $this->flushChecks();
    }

    /** ② 已参与的列**留在原位**切方向 —— 这是不能复用 `thenBy()` 的理由 */
    public function testCycleMultiKeepsPriorityPosition(): void
    {
        $sort = TableSort::by('id')->thenBy('name');
        $cycled = $sort->cycleMulti('id');
        $viaThenBy = $sort->thenBy('id', TableSortDirection::Descending);

        check(
            'TableSort · Shift+点主键（升 ⇒ 降）⇒ 仍是主键，次键原样保留',
            $cycled->primaryColumn() === 'id'
                && $cycled->primaryDirection() === TableSortDirection::Descending
                && $cycled->directionOf('name') === TableSortDirection::Ascending,
            self::describe($cycled),
        );
        check(
            'TableSort · 对照：`thenBy()` 会把已存在的键移到末尾（两种意图不能混用）',
            $viaThenBy->primaryColumn() === 'name',
            self::describe($viaThenBy),
        );

        $this->flushChecks();
    }

    /** ③ 降序再 Shift+点 ⇒ 只移除这一键，其它键的优先级不变 */
    public function testCycleMultiRemovesDescendingKey(): void
    {
        $two = TableSort::by('id', TableSortDirection::Descending)->thenBy('name');
        $kept = $two->cycleMulti('id');
        $empty = TableSort::by('id', TableSortDirection::Descending)->cycleMulti('id');

        check(
            'TableSort · 移除主键 ⇒ 原来的次键顶上，不是整表清空',
            $kept->primaryColumn() === 'name' && \count($kept->keys()) === 1,
            self::describe($kept),
        );
        check(
            'TableSort · 移除最后一个键 ⇒ none()',
            $empty->isEmpty(),
            self::describe($empty),
        );

        $this->flushChecks();
    }

    /** ④ 普通点击仍是单键语义（`cycle()` 收编后不变） */
    public function testCycleRemainsSingleKey(): void
    {
        $promoted = TableSort::by('id')->thenBy('name')->cycle('name');
        check(
            'TableSort · 点次键列 ⇒ 升格为主键升序、其它键全清（不是"点一下没反应"）',
            $promoted->primaryColumn() === 'name'
                && $promoted->directionOf('id') === null
                && \count($promoted->keys()) === 1,
            self::describe($promoted),
        );

        $down = TableSort::by('id')->cycle('id');
        $none = TableSort::by('id', TableSortDirection::Descending)->cycle('id');
        check(
            'TableSort · 单键三态：升 ⇒ 降 ⇒ 无',
            $down->primaryDirection() === TableSortDirection::Descending && $none->isEmpty(),
            self::describe($down) . ' / ' . self::describe($none),
        );

        $this->flushChecks();
    }

    /** ⑤ 指针入口：Shift+点表头 ⇒ 上报两个键；普通点仍是单键 */
    public function testShiftHeaderTapReportsTwoKeys(): void
    {
        $sink = new SortSink();
        $el = Fixtures::mount(Fixtures::sortable(TableSort::by('id'), $sink));
        Fixtures::tapHeader(Fixtures::headerCellsOf($el)[1], shift: true);

        check(
            'Table · Shift+点表头 ⇒ 主键保持、被点列追加为次键，列序号照常上报',
            $sink->last !== null
                && $sink->last->primaryColumn() === 'id'
                && $sink->last->directionOf('name') === TableSortDirection::Ascending
                && \count($sink->last->keys()) === 2
                && $sink->columnIndex === 1,
            self::describe($sink->last),
        );

        $plain = new SortSink();
        $plainEl = Fixtures::mount(Fixtures::sortable(TableSort::by('id'), $plain));
        Fixtures::tapHeader(Fixtures::headerCellsOf($plainEl)[1]);
        check(
            'Table · 对照：不带 Shift 的点 ⇒ 仍是"换主键"（既有语义没被改坏）',
            $plain->last !== null
                && $plain->last->primaryColumn() === 'name'
                && \count($plain->last->keys()) === 1,
            self::describe($plain->last),
        );

        $this->flushChecks();
    }

    /** ⑥ 键盘入口：Shift+Enter 与指针同款（`KeyChord::is()` 全字段比较的坑） */
    public function testShiftEnterReportsTwoKeys(): void
    {
        [, $state, $sink] = self::mountSorted(TableSort::by('id'));
        self::press($state, KeyChord::up());
        self::press($state, KeyChord::right());

        $ate = self::press($state, KeyChord::shift('Enter'));

        check(
            'Table · 表头 Shift+Enter ⇒ 吃键、上报两个键（主键保持）',
            $ate
                && $sink->last !== null
                && $sink->last->primaryColumn() === 'id'
                && $sink->last->directionOf('name') === TableSortDirection::Ascending,
            'ate=' . ($ate ? 'yes' : 'no') . ' ' . self::describe($sink->last),
        );

        $this->flushChecks();
    }

    /** ⑦ 只剥 shift：Ctrl+Enter 不该触发排序 */
    public function testCtrlEnterDoesNotSort(): void
    {
        [, $state, $sink] = self::mountSorted(TableSort::by('id'));
        self::press($state, KeyChord::up());

        $ate = self::press($state, KeyChord::ctrl('Enter'));

        check(
            'Table · Ctrl+Enter 不是排序快捷键（剥 shift 时保留 ctrl/alt）',
            !$ate && $sink->last === null,
            'ate=' . ($ate ? 'yes' : 'no') . ' ' . self::describe($sink->last),
        );

        $this->flushChecks();
    }

    /** ⑧ 两列各画各的箭头：`directionOf()` 对任何参与键都给方向 */
    public function testBothKeysDrawTheirOwnArrow(): void
    {
        $two = TableSort::by('id')->thenBy('name', TableSortDirection::Descending);
        $sink = new SortSink();
        $el = Fixtures::mount(Fixtures::sortable($two, $sink));

        $icons = self::iconsOf($el);
        $names = [];
        foreach ($icons as $icon) {
            $names[] = $icon->iconName();
        }

        check(
            'Table · 两键排序 ⇒ 表头两个箭头，升/降各按自己的方向',
            \count($icons) === 2
                && \in_array(IconName::ArrowUp, $names, true)
                && \in_array(IconName::ArrowDown, $names, true),
            'icons=' . \count($icons),
        );

        $this->flushChecks();
    }

    /** ⑨ 应用侧真按比较器链重排（只读主键＝画面撒谎） */
    public function testShowcaseSortsByWholeKeyChain(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'B', 'email' => 'b@x', 'city' => '北京'],
            ['id' => 2, 'name' => 'A', 'email' => 'a@x', 'city' => '北京'],
            ['id' => 3, 'name' => 'C', 'email' => 'c@x', 'city' => '上海'],
        ];

        // State 已拆到独立文件，这里仍走 `createState()` 拿实例、用 `$page::class` 反射，
        // 不依赖 State 类名随实现改名。
        $page = TablePage::make(ShowcaseCaps::make())->createState();
        (new \ReflectionProperty($page::class, 'allRows'))->setValue($page, $rows);
        $method = new \ReflectionMethod($page::class, 'sortRows');

        $asc = $method->invoke($page, TableSort::by('city')->thenBy('name'));
        $ids = [];
        foreach ($asc ?? [] as $row) {
            $ids[] = $row['id'];
        }

        check(
            'showcase · 主键相等才轮到次键（上海组在前、北京组内 A 在 B 前）',
            $ids === [3, 2, 1],
            'ids=' . \implode(',', $ids),
        );

        $desc = $method->invoke(
            $page,
            TableSort::by('city', TableSortDirection::Descending)->thenBy('name'),
        );
        $descIds = [];
        foreach ($desc ?? [] as $row) {
            $descIds[] = $row['id'];
        }

        check(
            'showcase · 主键降序也生效（北京组在前），次键方向不受影响',
            $descIds === [2, 1, 3],
            'ids=' . \implode(',', $descIds),
        );

        $this->flushChecks();
    }
}
