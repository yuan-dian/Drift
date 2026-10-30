<?php
declare(strict_types=1);

namespace Drift\Bench;

use App\Showcase\ShowcaseRoot;
use Drift\Async\Future;
use Drift\Layout\Overflow;
use Drift\Widget\Column;
use Drift\Widget\Modal;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Snackbar;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
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
     * **虚拟化表格**（`Table`，默认 1000 行 × 6 列）—— M3 的"真实滚动容器"档。
     *
     * ★ 存在的理由：上面 `build()` 那几档是**全量树**（行全在树里），而生产里
     *   最大的表面是 `Table` / `LazyColumn` —— 它们**只建可见行** ⇒ 树小、但每次
     *   滚动都会重建窗口里的行。两种成本形状完全不同，不能拿全量树的数字去推
     *   "滚动一帧要多少"（那会把"树大"的成本错算到"重建"头上）。
     *
     * ★ 1000 行只是**数据量**，进树的只有可见的十几行 —— 这正是要证明的事：
     *   数据量不影响帧成本，**窗口重建频率**才影响。
     *
     * ★★ **数据与列定义必须缓存**（第一版没缓存，量出来的数是错的）：不缓存的话
     *   "每帧重建"这一档每次都重造 1000 行数组 + 6 个 `TableColumn`，那部分成本
     *   与滚动毫无关系（真实滚动不会重造数据源）⇒ 会把 `settle` 虚高，
     *   进而把"重建有多贵"这个结论建立在假数字上。
     */
    /**
     * @param float $colWidth 每列固定宽（`0` = 不设宽，即 flex 压缩填满视口）
     *
     * ★ 列虚拟化只有在**列宽固定且总宽超出视口**时才有事可做：flex 列会被压到
     *   视口内 ⇒ 永远填满 ⇒ 每列都可见 ⇒ 裁与不裁结果相同（那是 `flex` 的语义，
     *   不是 bug）。所以要量这项收益必须给固定宽。
     */
    public static function table(int $rows, int $cols, float $colWidth = 0.0): Widget
    {
        [$columns, $data] = self::tableData($rows, $cols, $colWidth);

        return Table::make($columns, $data)
            ->keyOf(static fn(array $row): int => (int) $row['id']);
    }

    /** @var array<string, array{0: list<TableColumn>, 1: list<array<string, mixed>>}> */
    private static array $tableCache = [];

    /**
     * @return array{0: list<TableColumn>, 1: list<array<string, mixed>>}
     */
    private static function tableData(int $rows, int $cols, float $colWidth = 0.0): array
    {
        $id = (string) $rows . 'x' . (string) $cols . ($colWidth > 0.0 ? '@' . (string) $colWidth : '');
        $hit = self::$tableCache[$id] ?? null;
        if ($hit !== null) {
            return $hit;
        }

        $columns = [];
        for ($c = 0; $c < $cols; $c++) {
            $column = TableColumn::make('c' . (string) $c, 'C' . (string) $c)
                ->field('c' . (string) $c);
            if ($colWidth > 0.0) {
                $column = $column->width(TableColumnWidth::fixed($colWidth));
            }
            $columns[] = $column;
        }

        $data = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = ['id' => $r];
            for ($c = 0; $c < $cols; $c++) {
                $row['c' . (string) $c] = 'R' . (string) $r . 'C' . (string) $c;
            }
            $data[] = $row;
        }

        self::$tableCache[$id] = [$columns, $data];

        return [$columns, $data];
    }

    /**
     * showcase 的真实应用根（M3 第一档 / M4 的窗口内容）。
     *
     * ★ **必须显式传五样**：`ShowcaseRoot` 的构造器要
     *   `(notify, delay, showModal, dialog, setCloseConfirm)`，而 `new ShowcaseRoot()` 在 AOT 下是
     *   `ArgumentCountError`（tpc 不会因为"参数没默认值"放过）—— 现象是
     *   **M0–M2 全跑完、M3 一行不出、进程 exit=255**，`bench-out.txt` 停在 M2
     *   末尾，看着像"M3 没写"。
     * ★ 这里递的是**空实现**而不是真接线：基准只量"建树 + 布局 + 绘制"，
     *   浮层/定时器/模态在无头基准里没有宿主，接真的反而要造一个假 Application。
     *   对话框同理 —— `NullDialog` 的行为是"用户取消"（见该类的注释）。
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
            new NullDialog(),
            static function (bool $enabled): void {
            },
            // ★ N6：取数给**已结算的静态数据**，不是真任务。
            //   整页快照要稳定 —— `Future::resolved` 在 `initState` 里同步结算
            //   ⇒ 首帧就是 data，不会把转圈动画画进基线（转圈每帧相位都在变）。
            static fn(int $page): Future => Future::resolved(self::staticRows($page)),
        );
    }

    /**
     * 异步表格页在基准 / 快照里的静态数据（与 `FetchRowsJob` 的 local 形状一致）。
     *
     * ★ 与 `FetchRowsJob::simulateSlowLocal()` **不共享代码**：那份在 worker 进程里
     *   跑（还带 1.2s 睡眠），基准要的是"零耗时、确定性"。共享会把睡眠带进基准。
     *
     * @return array{items: list<array<string, mixed>>, total: int, source: string}
     */
    private static function staticRows(int $page): array
    {
        $cities = ['北京', '上海', '广州', '深圳', '杭州'];
        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = [
                'id' => $i,
                'name' => '用户 ' . $i,
                'email' => 'user' . $i . '@example.com',
                'city' => $cities[$i % 5],
            ];
        }

        return ['items' => $rows, 'total' => 8, 'source' => '静态数据'];
    }
}
