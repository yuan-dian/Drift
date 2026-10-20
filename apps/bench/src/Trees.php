<?php
declare(strict_types=1);

namespace Drift\Bench;

use Drift\Layout\Overflow;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * M3 的参数化夹具树。
 *
 * 形状：`ScrollView > Column > rows × Row > cols × Text`
 * ⇒ 节点数 = 2 + rows + rows*cols（由 `M3::countElements()` 现数，不在这里估）。
 *
 * ── 为什么不是三层 `Column` 嵌套（第一版写法，实测崩了）───────────────────
 * 三层 `Column` 是**垂直累加**：叶子 64 个竖排 ⇒ 子项共需 1075.2，可用 800
 * ⇒ 直接踩 C5「放不下」的 Fatal（这是对的，默认 `Report` 就是要响亮失败）。
 * 夹具不该被自己的形状撑死 ⇒ 改成"横向铺开 + 外层滚动视口"：
 *   · `Row` 横向铺 ⇒ 宽度受控（`Overflow::Clip` 兜住极端情况）；
 *   · `ScrollView` 给 Column 一个**无界主轴** ⇒ 自然高度多大都不报错。
 *
 * ★ 为什么不用 `LazyColumn`：它是**有界主轴 + 只建可见项**，节点数不随数据量变
 *   ⇒ 拿它测"树大了以后 layout 占比怎么变"会测不出变化（那正是它的价值）。
 *   这里要的是"全量树"，所以用普通 `Column` + 滚动视口。
 *
 * ★ `ScrollView` 不会削弱这次测量：滚动视口的布局仍然要量完**整棵** Column 的
 *   自然高度（无界约束传下去）⇒ layout 成本照旧是全树 O(n)。
 */
final class Trees
{
    public static function build(int $rows, int $cols): Widget
    {
        $list = [];
        for ($r = 0; $r < $rows; $r++) {
            $cells = [];
            for ($c = 0; $c < $cols; $c++) {
                $cells[] = Text::make('R' . (string) $r . 'C' . (string) $c);
            }
            $list[] = Row::make()->children($cells)->overflow(Overflow::Clip);
        }

        return ScrollView::make(Column::make()->children($list));
    }
}
