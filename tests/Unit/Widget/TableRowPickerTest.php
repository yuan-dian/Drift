<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Widget\TableRowPicker;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSelectionMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 行选择的**点法语义**：一次"指到第 i 行 + 修饰键"应当得到什么选中集与锚点。
 *
 * ★ 这是点击 / Shift 连选 / 拖动框选**三种输入的唯一出口**，所以它是界面上
 *   "看着还行、其实已经错了"的第一道网。
 * ★ 每条都能反证：Ctrl 挪锚点 ⇒ 下一次 Shift 的起点会跳；区间改累加 ⇒
 *   反向拖会留下上一次的尾巴；Shift 无锚点却去算区间 ⇒ 空选。
 */
final class TableRowPickerTest extends TestCase
{
    /** @var list<string> */
    private const KEYS = ['a', 'b', 'c', 'd', 'e'];

    /**
     * @return array<string, array{0: list<string>, 1: int, 2: ?TableSelectionMode, 3: ?string, 4: bool, 5: bool, 6: list<string>, 7: ?string}>
     */
    public static function pickCases(): array
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
    #[DataProvider('pickCases')]
    public function testPick(
        array $currentKeys,
        int $target,
        ?TableSelectionMode $mode,
        ?string $anchor,
        bool $ctrl,
        bool $shift,
        array $expectedKeys,
        ?string $expectedAnchor,
    ): void {
        [$next, $nextAnchor] = TableRowPicker::pick(
            TableSelection::of($currentKeys),
            self::KEYS,
            $target,
            $mode,
            $anchor,
            $ctrl,
            $shift,
        );

        self::assertSame($expectedKeys, $next->keys());
        self::assertSame($expectedAnchor, $nextAnchor);
    }

    public function testPickOnKeylessRowReturnsSameInstance(): void
    {
        $current = TableSelection::none();
        // 下标 1 没有身份 ⇒ 原样返回**同一实例**（不是"内容相同的新实例"）
        [$next, $anchor] = TableRowPicker::pick($current, ['a', null, 'c'], 1, TableSelectionMode::Multiple, 'a', false, false);

        self::assertSame($current, $next);
        self::assertSame('a', $anchor);
    }

    /** 下标不压缩：孔洞留 `null` 占位，取 key 时才滤（压缩会引入两套下标，是错位 bug 的源头） */
    public function testBetweenSkipsHolesWithoutShiftingIndexes(): void
    {
        self::assertSame(['a', 'c'], TableRowPicker::between(['a', null, 'c'], 0, 2));
        // 反向同样覆盖（内部先 min/max）
        self::assertSame(['a', 'b', 'c'], TableRowPicker::between(['a', 'b', 'c'], 2, 0));
        // 越界下标静默跳过，不抛
        self::assertSame(['c'], TableRowPicker::between(['a', 'b', 'c'], 2, 9));
    }

    public function testIndexOfRespectsKeyType(): void
    {
        self::assertSame(1, TableRowPicker::indexOf([7, '7', null], '7'));
        self::assertSame(0, TableRowPicker::indexOf([7, '7', null], 7));
        self::assertNull(TableRowPicker::indexOf(['a'], 'zzz'));
    }
}
