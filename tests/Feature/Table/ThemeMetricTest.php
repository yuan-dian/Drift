<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Layout\RenderBox;
use Drift\Rendering\MetricToken;
use Drift\Theme\ThemeData;
use Drift\Theme\ThemeScope;
use Drift\Widget\Element;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use PHPUnit\Framework\TestCase;

/**
 * **行高/表头高从主题来**（§43）：`Table` 不显式设置时读 `MetricToken::RowHeight`
 * / `HeaderHeight`，换肤因此能改表格密度。
 *
 * ★ 断言量的是**行盒的绝对 y 差**，不是 `TableGeometry` 的字段：字段对了、
 *   却被别的地方覆盖掉，画面上看不出来 —— 那种"改了个数但没生效"正是本棒要防的。
 * ★ 一条反例：改**无关** token（`Gap`）必须不动行距。否则这套测试只要"主题变过"
 *   就会绿，连"没接线"都能过。
 */
final class ThemeMetricTest extends TestCase
{
    /** 刻意不设行高/表头高 —— 这一组测的就是"没设时读什么" */
    private static function bare(int $count): Table
    {
        $columns = [
            TableColumn::make('id', 'ID')->field('id')->width(TableColumnWidth::fixed(60.0)),
            TableColumn::make('name', '姓名')->field('name')->width(TableColumnWidth::flex(2.0)),
            TableColumn::make('city', '城市')->field('city')->width(TableColumnWidth::flex(1.0)),
        ];

        return Table::make($columns, Fixtures::rows($count))
            ->keyOf(static fn(array $row): int => $row['id']);
    }

    private static function mount(ThemeData $theme, ?Table $table = null): Element
    {
        $element = ThemeScope::make($theme, $table ?? self::bare(20))->createElement();
        $element->mount(null, smokeContext());
        $element->effectiveBox()?->layout(Fixtures::tight());

        return $element;
    }

    /** 第 `$index` 行的顶边（表头高 = 第 0 行的顶边） */
    private static function rowTop(Element $root, int $index): float
    {
        $box = Fixtures::rowBoxOf($root, $index);
        self::assertInstanceOf(RenderBox::class, $box, '第 ' . $index . ' 行不在树里');

        return RenderBox::absoluteOrigin($box)[1];
    }

    private static function rowGap(Element $root): float
    {
        return self::rowTop($root, 1) - self::rowTop($root, 0);
    }

    /** 默认主题 ⇒ 与接线前的写死值一致（44 / 40）：外观零变化是这条的判据 */
    public function testBaselineMatchesPreWiringDefaults(): void
    {
        $root = self::mount(ThemeData::light());

        self::assertEqualsWithDelta(40.0, self::rowTop($root, 0), 0.001, '表头高');
        self::assertEqualsWithDelta(44.0, self::rowGap($root), 0.001, '行高');
    }

    /** 主题改 `RowHeight` ⇒ 行距跟着变；表头**不受影响** */
    public function testThemeRowHeightReachesLayout(): void
    {
        $root = self::mount(ThemeData::light()->withMetric(MetricToken::RowHeight, 30.0));

        self::assertEqualsWithDelta(30.0, self::rowGap($root), 0.001);
        self::assertEqualsWithDelta(40.0, self::rowTop($root, 0), 0.001, '表头不该被行高的主题值带走');
    }

    /** 主题改 `HeaderHeight` ⇒ 表头高与行区起点一起变；行距不变 */
    public function testThemeHeaderHeightReachesLayout(): void
    {
        $root = self::mount(ThemeData::light()->withMetric(MetricToken::HeaderHeight, 24.0));

        self::assertEqualsWithDelta(24.0, self::rowTop($root, 0), 0.001);
        self::assertEqualsWithDelta(44.0, self::rowGap($root), 0.001, '行距不该被表头的主题值带走');
    }

    /** 单表显式值优先于主题（"同一份主题下某张表要紧凑"的用法） */
    public function testExplicitRowHeightBeatsTheme(): void
    {
        $table = self::bare(20)->rowHeight(50.0)->headerHeight(36.0);
        $root = self::mount(
            ThemeData::light()
                ->withMetric(MetricToken::RowHeight, 30.0)
                ->withMetric(MetricToken::HeaderHeight, 24.0),
            $table,
        );

        self::assertEqualsWithDelta(50.0, self::rowGap($root), 0.001);
        self::assertEqualsWithDelta(36.0, self::rowTop($root, 0), 0.001);
    }

    /** 反例：无关 token 变了，行距必须不动 —— 否则上面几条可能只是"主题变过就绿" */
    public function testUnrelatedMetricLeavesRowsAlone(): void
    {
        $root = self::mount(ThemeData::light()->withMetric(MetricToken::Gap, 40.0));

        self::assertEqualsWithDelta(44.0, self::rowGap($root), 0.001);
        self::assertEqualsWithDelta(40.0, self::rowTop($root, 0), 0.001);
    }
}
