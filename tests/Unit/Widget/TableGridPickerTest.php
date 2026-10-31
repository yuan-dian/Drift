<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Widget\TableGridPicker;
use Drift\Widget\TableRange;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSelectionBehavior;
use Drift\Widget\TableSelectionMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 选择的**点法语义**：一次"指到第 i 行第 j 列 + 修饰键"应当得到什么选中集与锚点。
 *
 * ★ 这是点击 / Shift 连选 / 拖动框选 / `Ctrl+A` **四种输入的唯一出口**，所以它是
 *   界面上"看着还行、其实已经错了"的第一道网。
 * ★ 每条都能反证：Ctrl 挪锚点 ⇒ 下一次 Shift 的起点会跳；区间改累加 ⇒
 *   反向拖会留下上一次的尾巴；Shift 无锚点却去算区间 ⇒ 空选。
 *
 * ★ **Rows 行为那 9 条是 `TableRowPickerTest` 原样搬来的**（那个类已并入本类）——
 *   整行选择的语义一个字没改，这是"换存储不换行为"的证据。
 */
final class TableGridPickerTest extends TestCase
{
    /** @var list<string> */
    private const KEYS = ['a', 'b', 'c', 'd', 'e'];

    /** @var list<string> */
    private const COLS = ['name', 'age', 'city'];

    private const ROWS_BEHAVIOR = TableSelectionBehavior::Rows;
    private const CELLS_BEHAVIOR = TableSelectionBehavior::Cells;

    /**
     * @return array<string, array{0: list<string>, 1: int, 2: ?TableSelectionMode, 3: ?string, 4: bool, 5: bool, 6: list<string>, 7: ?string}>
     */
    public static function rowCases(): array
    {
        $multi = TableSelectionMode::Multiple;
        $single = TableSelectionMode::Single;

        return [
            // 无修饰 = 替换（WPF DataGrid / ag-Grid 的默认），锚点跟过去
            '无修饰 ⇒ 替换为该行，锚点跟过去' => [['c', 'a'], 2, $multi, null, false, false, ['c'], 'c'],
            // Ctrl 反选，锚点**不动**（否则下一次 Shift 起点会跳到刚点的行）
            'Ctrl ⇒ 反选该行（锚点不动）' => [['c', 'a'], 2, $multi, 'a', true, false, ['a'], 'a'],
            // Shift 是**替换**为区间而不是累加 —— 累加会让"缩小区间"永远做不到
            'Shift ⇒ 锚点到目标替换为区间（不是累加）' => [[], 3, $multi, 'a', false, true, ['a', 'b', 'c', 'd'], 'a'],
            // ★ 上面那条 current 是空集 ⇒ `of` 与 `withAll` 结果相同，区分不出"替换/合并"。
            //   这条带一个**区间外的**旧选中 ⇒ 必须被丢掉（变异实测：改 withAll 时只有这条红）。
            'Shift 替换区间 ⇒ 区间外的旧选中被丢掉（不是合并）' => [['x', 'y'], 3, $multi, 'a', false, true, ['a', 'b', 'c', 'd'], 'a'],
            '反向拖（目标在锚点上方）⇒ 区间同样覆盖' => [['a', 'b'], 1, $multi, 'e', false, true, ['b', 'c', 'd', 'e'], 'e'],
            // 第一次按 Shift 没有锚点 ⇒ 只把锚点落下来，选的是单行，不是"0 到 i"的区间
            'Shift 时还没有锚点 ⇒ 只选这一行，并把锚点落下来' => [[], 1, $multi, null, false, true, ['b'], 'b'],
            // 单选忽略修饰键（区间与累加都是多选专属）
            '单选模式忽略 Shift / Ctrl' => [['a', 'b'], 3, $single, 'a', false, true, ['d'], 'd'],
            // 锚点被过滤掉：不炸，退化成替换。★ 锚点**保持**旧值（不悄悄改写成当前行）
            '锚点所在行已被过滤掉 ⇒ 退化成替换，且不炸' => [['x'], 2, $multi, 'zzz', false, true, ['c'], 'zzz'],
            // 表没启用选择 ⇒ 原样返回同一个实例（调用方据此跳过通知）
            '表没启用选择（mode=null）⇒ 原样返回' => [['a'], 2, null, 'a', true, true, ['a'], 'a'],
        ];
    }

    /**
     * @param list<string> $currentKeys
     * @param list<string> $expectedKeys
     */
    #[DataProvider('rowCases')]
    public function testRowsBehaviorKeepsOldSemantics(
        array $currentKeys,
        int $target,
        ?TableSelectionMode $mode,
        ?string $anchor,
        bool $ctrl,
        bool $shift,
        array $expectedKeys,
        ?string $expectedAnchor,
    ): void {
        [$next, $nextAnchor] = TableGridPicker::pick(
            TableSelection::of($currentKeys),
            self::KEYS,
            self::COLS,
            $target,
            0,
            $mode,
            self::ROWS_BEHAVIOR,
            $anchor === null ? null : ['row' => $anchor, 'col' => null],
            $ctrl,
            $shift,
        );

        self::assertSame($expectedKeys, $next->keys());
        self::assertSame($expectedAnchor, $nextAnchor['row'] ?? null);
        // 整行行为下**永远**是整行选区：列端点必须是 null，否则渲染会按格着色
        foreach ($next->ranges() as $range) {
            self::assertTrue($range->isWholeRow(), 'Rows 行为产出的选区必须是整行');
        }
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: ?array{row: string, col: ?string}, 3: bool, 4: bool, 5: list<array{row: string, col: string}>, 6: ?array{row: string, col: string}}>
     */
    public static function cellCases(): array
    {
        return [
            // 无修饰：替换为单格，锚点跟过去
            '无修饰 ⇒ 只有这一格' => [
                2, 1, null, false, false,
                [['row' => 'c', 'col' => 'age']],
                ['row' => 'c', 'col' => 'age'],
            ],
            // Shift：锚点格 → 目标格的**矩形**（行与列各取区间）
            // ★ 锚点**不动**（与整行行为同一条纪律：Shift 只扩选，不挪起点）
            'Shift ⇒ 锚点到目标的矩形（2 行 × 2 列）' => [
                2, 2, ['row' => 'b', 'col' => 'name'], false, true,
                [
                    ['row' => 'b', 'col' => 'name'], ['row' => 'b', 'col' => 'age'], ['row' => 'b', 'col' => 'city'],
                    ['row' => 'c', 'col' => 'name'], ['row' => 'c', 'col' => 'age'], ['row' => 'c', 'col' => 'city'],
                ],
                ['row' => 'b', 'col' => 'name'],
            ],
            // 反向（目标在锚点左上）：矩形同样覆盖，行序 / 列序都按当前顺序展开
            '反向 Shift（目标在锚点左上）⇒ 矩形同样覆盖' => [
                0, 0, ['row' => 'c', 'col' => 'city'], false, true,
                [
                    ['row' => 'a', 'col' => 'name'], ['row' => 'a', 'col' => 'age'], ['row' => 'a', 'col' => 'city'],
                    ['row' => 'b', 'col' => 'name'], ['row' => 'b', 'col' => 'age'], ['row' => 'b', 'col' => 'city'],
                    ['row' => 'c', 'col' => 'name'], ['row' => 'c', 'col' => 'age'], ['row' => 'c', 'col' => 'city'],
                ],
                ['row' => 'c', 'col' => 'city'],
            ],
            // 单列宽的矩形：列区间只有一个 key
            'Shift 同列 ⇒ 单列多行' => [
                3, 1, ['row' => 'b', 'col' => 'age'], false, true,
                [
                    ['row' => 'b', 'col' => 'age'],
                    ['row' => 'c', 'col' => 'age'],
                    ['row' => 'd', 'col' => 'age'],
                ],
                ['row' => 'b', 'col' => 'age'],
            ],
            // 第一次 Shift 没有锚点 ⇒ 只把锚点落下来，选的是单格（不是"0 到 i"的矩形）
            'Shift 时还没有锚点 ⇒ 只选这一格，并把锚点落下来' => [
                1, 2, null, false, true,
                [['row' => 'b', 'col' => 'city']],
                ['row' => 'b', 'col' => 'city'],
            ],
        ];
    }

    /**
     * @param ?array{row: string, col: ?string} $anchor
     * @param list<array{row: string, col: string}> $expectedCells
     * @param ?array{row: string, col: string} $expectedAnchor
     */
    #[DataProvider('cellCases')]
    public function testCellsBehaviorBuildsRectangle(
        int $targetRow,
        int $targetCol,
        ?array $anchor,
        bool $ctrl,
        bool $shift,
        array $expectedCells,
        ?array $expectedAnchor,
    ): void {
        [$next, $nextAnchor] = TableGridPicker::pick(
            TableSelection::none(),
            self::KEYS,
            self::COLS,
            $targetRow,
            $targetCol,
            TableSelectionMode::Multiple,
            self::CELLS_BEHAVIOR,
            $anchor,
            $ctrl,
            $shift,
        );

        $actual = [];
        foreach (self::KEYS as $rowKey) {
            foreach (self::COLS as $colKey) {
                if ($next->covers($rowKey, $colKey)) {
                    $actual[] = ['row' => $rowKey, 'col' => $colKey];
                }
            }
        }

        self::assertSame($expectedCells, $actual);
        self::assertSame($expectedAnchor, $nextAnchor);
    }

    /** `Ctrl+点` 在 Cells 行为下是**累加**（Excel 语义），而且不挪锚点 */
    public function testCtrlClickInCellsBehaviorAddsWithoutMovingAnchor(): void
    {
        $current = TableSelection::ofCells(['a'], ['name']);

        [$next, $anchor] = TableGridPicker::pick(
            $current,
            self::KEYS,
            self::COLS,
            3,
            2,
            TableSelectionMode::Multiple,
            self::CELLS_BEHAVIOR,
            ['row' => 'a', 'col' => 'name'],
            true,
            false,
        );

        self::assertTrue($next->covers('a', 'name'), '原来那格还在（累加，不是替换）');
        self::assertTrue($next->covers('d', 'city'), '新那格被加进来');
        self::assertSame(2, \count($next->ranges()), '两块选区');
        self::assertSame('a', $anchor['row'] ?? null, '锚点不动（否则下一次 Shift 的起点会跳）');
    }

    /** 已选中的格再 `Ctrl+点` ⇒ 原样返回**同一实例**（调用方据此跳过通知） */
    public function testCtrlClickOnSelectedCellReturnsSameInstance(): void
    {
        $current = TableSelection::ofCells(['a'], ['name']);

        [$next] = TableGridPicker::pick(
            $current,
            self::KEYS,
            self::COLS,
            0,
            0,
            TableSelectionMode::Multiple,
            self::CELLS_BEHAVIOR,
            null,
            true,
            false,
        );

        self::assertSame($current, $next);
    }

    /** 行没有身份 / 列 key 缺失 ⇒ 原样返回同一实例，不炸 */
    public function testKeylessRowOrColumnReturnsSameInstance(): void
    {
        $current = TableSelection::none();

        [$a] = TableGridPicker::pick(
            $current,
            ['a', null, 'c'],
            self::COLS,
            1,
            0,
            TableSelectionMode::Multiple,
            self::CELLS_BEHAVIOR,
            null,
            false,
            false,
        );
        self::assertSame($current, $a);

        [$b] = TableGridPicker::pick(
            $current,
            self::KEYS,
            ['name'],
            0,
            5,
            TableSelectionMode::Multiple,
            self::CELLS_BEHAVIOR,
            null,
            false,
            false,
        );
        self::assertSame($current, $b);
    }

    /** 下标不压缩：孔洞留 `null` 占位，取 key 时才滤（压缩会引入两套下标，是错位 bug 的源头） */
    public function testBetweenSkipsHolesWithoutShiftingIndexes(): void
    {
        self::assertSame(['a', 'c'], TableGridPicker::between(['a', null, 'c'], 0, 2));
        self::assertSame(['a', 'b', 'c'], TableGridPicker::between(['a', 'b', 'c'], 2, 0));
        self::assertSame(['c'], TableGridPicker::between(['a', 'b', 'c'], 2, 9));
    }

    public function testIndexOfRespectsKeyType(): void
    {
        self::assertSame(1, TableGridPicker::indexOf([7, '7', null], '7'));
        self::assertSame(0, TableGridPicker::indexOf([7, '7', null], 7));
        self::assertNull(TableGridPicker::indexOf(['a'], 'zzz'));
    }

    /** `Ctrl+A`：Rows 行为给整行、Cells 行为给"所有行 × 所有列"，各自**一条** range */
    public function testSelectAllIsOneRangeInBothBehaviors(): void
    {
        $rows = TableGridPicker::selectAll(self::KEYS, self::COLS, self::ROWS_BEHAVIOR);
        self::assertSame(self::KEYS, $rows->keys());
        self::assertCount(1, $rows->ranges());
        self::assertTrue($rows->ranges()[0]->isWholeRow());

        $cells = TableGridPicker::selectAll(self::KEYS, self::COLS, self::CELLS_BEHAVIOR);
        self::assertSame(self::KEYS, $cells->keys());
        self::assertCount(1, $cells->ranges());
        self::assertTrue($cells->covers('c', 'city'));
        self::assertFalse($cells->ranges()[0]->isWholeRow());
    }

    /** 空表 `Ctrl+A` ⇒ 空选区（不是"一条空 range"，那会让 `isEmpty()` 与 `ranges()` 打架） */
    public function testSelectAllOnEmptyTableIsNone(): void
    {
        $none = TableGridPicker::selectAll([null, null], self::COLS, TableSelectionBehavior::Rows);

        self::assertTrue($none->isEmpty());
        self::assertSame([], $none->ranges());
    }
}
