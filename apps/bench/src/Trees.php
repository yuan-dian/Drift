<?php
declare(strict_types=1);

namespace Drift\Bench;

use App\Showcase\ShowcaseRoot;
use Drift\Layout\Overflow;
use Drift\Widget\Column;
use Drift\Widget\Modal;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Snackbar;
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

    /**
     * showcase 的真实应用根（M3 第一档 / M4 的窗口内容）。
     *
     * ★ **必须显式传三个能力闭包**：`ShowcaseRoot` 的构造器要
     *   `(notify, delay, showModal)`，而 `new ShowcaseRoot()` 在 AOT 下是
     *   `ArgumentCountError`（tpc 不会因为"参数没默认值"放过）—— 现象是
     *   **M0–M2 全跑完、M3 一行不出、进程 exit=255**，`bench-out.txt` 停在 M2
     *   末尾，看着像"M3 没写"。
     * ★ 这里递的是**空实现**而不是真接线：基准只量"建树 + 布局 + 绘制"，
     *   浮层/定时器/模态在无头基准里没有宿主，接真的反而要造一个假 Application。
     */
    public static function showcase(): ShowcaseRoot
    {
        return ShowcaseRoot::make(
            static function (Snackbar $snackbar): void {
            },
            static function (int $milliseconds, \Closure $callback): void {
            },
            static function (Modal $modal): void {
            },
        );
    }
}
