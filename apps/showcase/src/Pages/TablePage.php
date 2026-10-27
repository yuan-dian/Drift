<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Text\TextAlign;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Expanded;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSort;
use Drift\Widget\TableSortDirection;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 24「表格（Table）」—— P2 阶段 1 + 2 + 3 的**真实消费者**（2026-10-26）。
 *
 * ★ 它存在的理由同 `BigListPage`：让"表头固定 + 行虚拟化"在**生产形态**下被走通
 *   一次。10000 行 × 5 列只造视口覆盖的六行；换成 `Each` 全量，同数据单帧数秒。
 *
 * ★ 这一页同时演示**两条取值路径**：
 *   · `field()`：关联数组行按字段名取（ID / 姓名 / 邮箱 / 城市）；
 *   · `cell()`：派生值（状态由 id 算出来，不是行里的字段）。
 *   ★ 两者**不能同时**写在同一列上（挂载期 Fatal）—— 这是 `TableColumn` 的契约。
 *
 * ★ 列宽三层在这页都出现了：`fixed`（ID / 城市 / 状态）、`flex(2)` / `flex(3)`
 *   （姓名 / 邮箱按权重分剩余）、`minWidth`（姓名再挤也不小于 120）。
 *   对齐三种都有：`Start`（默认）/ `End`（数字右对齐）/ `Center`。
 *
 * ★ **选择与排序都是受控的**：选中集与排序状态住本页的 `State`，`Table` 只回调
 *   "点完之后应该是什么"。所以"已选 N 项 / 清空 / 清除排序"都能工作 —— 如果它们
 *   住在控件的 `State` 里，应用**永远读不到**（`State` 外部无引用通道，本仓也没有
 *   `GlobalKey`）。
 *
 * ★ **排序由本页重排**（不是控件）：`Table` 不知道 `$row` 里 `name` 是中文还是
 *   日期，比较规则只有应用知道。看 `comparators()` —— 连"状态"这个**派生列**都能排，
 *   因为闭包与 `sortable()` 互不约束。
 *
 * ★ **诚实边界**（页面底部也写了一句）：无 Ctrl+方向键扩选、无拖动框选 ——
 *   都是阶段 7 后半及其前置。单元格编辑已在（阶段 7a 键盘 / 7b 指针：单击落格、
 *   双击进编辑、插入符落在指针处）。横向滚轮已在（阶段 5b：触控板横滑 / Shift+竖轮）。
 */
final class TablePage extends StatefulWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new TablePageState();
    }
}

final class TablePageState extends State
{
    private const ROWS = 10000;

    /** 行高 / 表头高（逻辑像素）—— 定高是虚拟化的窗口计算前提 */
    private const ROW_HEIGHT = 40.0;
    private const HEADER_HEIGHT = 40.0;

    /** @var list<string> */
    private const CITIES = ['北京', '上海', '广州', '深圳', '杭州'];

    /**
     * 选中集（**受控**：应用持有，`Table` 只上报新值）。
     *
     * ★ 初值写成 `null` 而不是 `TableSelection::none()`：属性默认值必须是**常量
     *   表达式**，而 `none()` 是方法调用（tpc 直接拒："Constant expression contains
     *   invalid operations"）。这是 PHP 的规矩，不是本仓的。
     */
    private ?TableSelection $selection = null;

    /** 当前排序（受控；`null` 只到 `sortNow()` 为止，见上一条） */
    private ?TableSort $sort = null;

    /**
     * 当前按第几列排序（`-1` = 没有排序）。
     *
     * ★ 存列序号是为了在 `comparators()` 里直接当下标 —— 本页的列定义顺序就是
     *   比较器的顺序，不必再维护一份 key ⇒ 比较器 的映射表。
     * ★ `TableSort` 里存的是列 key（跨插列稳定的身份，箭头按它画）。两者用途不同，
     *   不是同一个东西的两种写法。
     */
    private int $sortColumnIndex = -1;

    /**
     * 原始行 —— **只造一次**。
     *
     * ★ 这一条是本页的真 bug 修复（2026-10-26 加排序时发现）：原先 `rows()` 是
     *   在 `build()` 里调的，也就是**每次 build 都重新格式化 10000 行字符串**
     *   （滚动一格、悬停换行、加选一项都算一次 build）。虚拟化省下的正是这笔钱，
     *   结果在数据侧又花回去了。
     */
    private ?array $allRows = null;

    /** 当前显示的行（排序后；`null` = 与原始顺序相同） */
    private ?array $viewRows = null;

    /**
     * 用户拖出来的列宽（**受控**第 ④ 层：列 key ⇒ 逻辑像素）。
     *
     * ★ 本页只做"拖动中实时更新"，没有存盘 —— 真实应用该在这里 `json_encode`
     *   落盘、下次开窗 `columnWidths()` 传回来。这正是它必须受控的理由：
     *   住在控件里就没有地方能存。
     */
    private array $widthOverrides = [];


    private function selectionNow(): TableSelection
    {
        if ($this->selection === null) {
            $this->selection = TableSelection::none();
        }

        return $this->selection;
    }

    private function sortNow(): TableSort
    {
        if ($this->sort === null) {
            $this->sort = TableSort::none();
        }

        return $this->sort;
    }

    /** 递给 `Table` 的行：原始顺序，或排序后的那份（都只算一次） */
    private function rowsNow(): array
    {
        if ($this->allRows === null) {
            $this->allRows = self::generateRows();
        }

        return $this->viewRows ?? $this->allRows;
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('表格（Table）')->title(),
                Text::make(
                    '10000 行 × 5 列 —— 表头固定不滚，行虚拟化（只造视口覆盖的那几行）。'
                    . '列宽三层：fixed / flex / min-max 夹；奇数行斑马。'
                    . '选择受控：点行选中、Ctrl 加选、Shift 连选。'
                    . '排序受控：点表头三态循环（升 → 降 → 取消），重排由本页做。'
                    . '列宽可拖：拖表头右缘（光标变左右箭头），重置按钮回到声明值。'
                    . '横向可滚：把列拖宽到超出表格宽 ⇒ 底部出现横条，拖它看右边（与竖条同时在右下角交叠，角上归竖条）；'
                    . '不抓条也行 —— 触控板横滑，或按住 Shift 滚竖轮。'
                    . '键盘：Tab 进来出现光标框，方向键移格、Home/End 到首末行（移出视口会自动滚过去）。'
                    . '指针：单击落在哪一格光标框就跳到哪一格；**双击**「姓名」列直接进编辑，'
                    . '插入符落在你点的那个字之间。'
                    . '单元格编辑：也可以让光标停在「姓名」列按 Enter 或 F2 进入，直接打字；'
                    . 'Enter 提交并下移、Esc 取消、点到别的行/失焦/滚动都会提交。'
                    . '边界：无 Ctrl+方向键扩选、无拖动框选。'
                )->caption(),

                $this->selectionBar(),
                $this->sortBar(),

                // ★ 必须放进**有界高度**：`Table` 自己就是滚动容器（同 `LazyColumn`），
                //   塞进无界主轴会按内容高铺开 ⇒ 虚拟化失效（窗口变成全部行）。
                Expanded::make(
                    Table::make(self::columns(), $this->rowsNow())
                        ->keyOf(static fn(array $row): int => $row['id'])
                        ->rowHeight(self::ROW_HEIGHT)
                        ->headerHeight(self::HEADER_HEIGHT)
                        ->selection($this->selectionNow())
                        ->onSelectionChange(fn(TableSelection $next) => $this->setState(
                            function () use ($next): void {
                                $this->selection = $next;
                            }
                        ))
                        ->columnWidths($this->widthOverrides)
                        ->onColumnResize(fn(string $key, float $width, bool $done) => $this->setState(
                            function () use ($key, $width): void {
                                $this->widthOverrides[$key] = $width;
                            }
                        ))
                        ->sort($this->sortNow())
                        ->onSortChange(fn(TableSort $next, int $columnIndex) => $this->setState(
                            function () use ($next, $columnIndex): void {
                                $this->sort = $next;
                                $this->sortColumnIndex = $columnIndex;
                                $this->viewRows = $this->sortRows($next, $columnIndex);
                            }
                        ))
                        // ★ 单元格编辑（阶段 7a）：提交的是**行 key + 列 key + 新文本**，
                        //   写回数据由本页做（控件不碰数据）。改完按当前排序重排一次 ——
                        //   否则改了"姓名"列而正按姓名排序时，行序会与数据不一致。
                        ->onCellEdit(fn(int $rowKey, string $column, string $value) => $this->setState(
                            function () use ($rowKey, $column, $value): void {
                                if ($this->allRows === null) {
                                    $this->allRows = self::generateRows();
                                }

                                foreach ($this->allRows as $index => $row) {
                                    if ((int) $row['id'] === $rowKey) {
                                        $this->allRows[$index][$column] = $value;
                                        break;
                                    }
                                }

                                $this->viewRows = $this->sortRows($this->sortNow(), $this->sortColumnIndex);
                            }
                        ))
                ),
            ])
        )->paddingAll(0.0);
    }

    /** "已选 N 项" + 清空（受控选择才可能做出这一行） */
    private function selectionBar(): Widget
    {
        $count = $this->selectionNow()->count();

        return Row::make()->spacing(12.0)->children([
            Text::make($count === 0 ? '未选中任何行' : '已选 ' . $count . ' 项（首尾可见）')->caption(),
            Text::make($count === 0 ? '' : $this->readoutOfSelection())->caption(),
            Spacer::make(),
            Button::make('清空选择')->onPressed(fn() => $this->setState(
                function (): void {
                    $this->selection = TableSelection::none();
                }
            )),
        ]);
    }

    /** 当前排序 + 清除（受控排序才可能做出这个按钮） */
    private function sortBar(): Widget
    {
        $sort = $this->sortNow();
        $column = $sort->primaryColumn();

        if ($column === null) {
            $label = '未排序（原始顺序）—— 点表头开始排序';
        } else {
            $dir = $sort->primaryDirection() === TableSortDirection::Ascending ? '升序 ↑' : '降序 ↓';
            $label = '排序：按 ' . $this->sortedColumnTitle() . ' ' . $dir . '（再点切换方向，第三次取消）';
        }

        return Row::make()->spacing(12.0)->children([
            Text::make($label)->caption(),
            Spacer::make(),
            Button::make('清除排序')->onPressed(fn() => $this->setState(
                function (): void {
                    $this->sort = TableSort::none();
                    $this->sortColumnIndex = -1;
                    $this->viewRows = null;
                }
            )),
            Button::make('重置列宽')->onPressed(fn() => $this->setState(
                function (): void {
                    $this->widthOverrides = [];
                }
            )),
        ]);
    }

    /** 当前排序列的标题（按序号取，不比字符串 —— 见 `$sortColumnIndex`） */
    private function sortedColumnTitle(): string
    {
        $columns = self::columns();

        return isset($columns[$this->sortColumnIndex]) ? $columns[$this->sortColumnIndex]->titleText() : '';
    }

    /** 只印前 3 个 key —— 选中一万行时不该把文本撑爆 */
    private function readoutOfSelection(): string
    {
        $keys = $this->selectionNow()->keys();
        $head = \array_slice($keys, 0, 3);
        $more = \count($keys) > 3 ? ' …' : '';
        $labels = [];
        foreach ($head as $key) {
            $labels[] = (string) $key;
        }

        return 'key = ' . \implode(', ', $labels) . $more;
    }

    /**
     * 按当前排序重排**一份副本**（`$rows` 是数组，PHP 赋值即 COW ⇒ 原始不会被改）。
     *
     * ★ 取消排序 ⇒ 返回 `null`：本页不需要"记住上一次的顺序"，原始数组一直在
     *   `$allRows` 里。应用的原始顺序可能来自数据库 `ORDER BY`，控件无从知道。
     */
    private function sortRows(TableSort $sort, int $columnIndex): ?array
    {
        $dir = $sort->primaryDirection();
        if ($dir === null) {
            return null;
        }

        $comparators = self::comparators();
        if (!isset($comparators[$columnIndex])) {
            return null;
        }

        $sign = $dir === TableSortDirection::Ascending ? 1 : -1;

        return self::mergeSort($this->allRows ?? self::generateRows(), $comparators[$columnIndex], $sign);
    }

    /**
     * 每列的比较规则 —— **这就是"控件不排序"的理由本身**。
     *
     * ★ `status` 是 `cell()` 派生列（不是字段）：只有本页知道"停用 = id 能被 3 整除"，
     *   所以也只有本页能定义它的顺序。控件要是自己排，这种列就永远排不了。
     * ★ 下标就是列序号（`onSortChange` 的第二参），"第几列 ⇒ 用哪个比较器"是一次
     *   数组下标，不需要把列 key 再翻译一遍。
     * ★ 闭包内部一律用**字面量键**取字段（`$a['id']` 而不是 `$a[$key]`）：动态键
     *   取出来是 `mixed`，比较就退化成混合类型比较。
     * ★ 中文按 UTF-8 字节序（`strcmp`），不是拼音序 —— 真要拼音序得应用自己带
     *   `collator`，同样是应用侧知识。
     *
     * @return array<int, callable(array<string, mixed>, array<string, mixed>): int>
     */
    private static function comparators(): array
    {
        return [
            0 => static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id'],
            1 => static fn(array $a, array $b): int => \strcmp((string) $a['name'], (string) $b['name']),
            2 => static fn(array $a, array $b): int => \strcmp((string) $a['email'], (string) $b['email']),
            3 => static fn(array $a, array $b): int => \strcmp((string) $a['city'], (string) $b['city']),
            4 => static fn(array $a, array $b): int => self::disabledRank((int) $a['id'])
                <=> self::disabledRank((int) $b['id']),
        ];
    }

    /**
     * 稳定归并排序 —— **不用 `\usort`**，为的是**稳定**。
     *
     * ★ 表格排序的稳定性是硬要求：按「状态」排时同组（正常/停用）内部必须保持原
     *   顺序，否则点一次表头整张表就"抖"一下。`\usort` 自 PHP 8 起稳定，但 AOT 产物
     *   里的 sort 由运行时自己实现（`std::sort` 这类不稳定算法很常见）——表格不能赌
     *   这一条，几十行归并换"顺序确定"是划算的。
     * ★ 一次排序 = 一次性操作（点击表头触发），所以 `array_slice` 的临时数组
     *   开销可以接受；**每帧**不做任何排序。
     *
     * @param list<array<string, mixed>>                     $rows
     * @param callable(array<string, mixed>, array<string, mixed>): int $cmp
     * @return list<array<string, mixed>>
     */
    private static function mergeSort(array $rows, callable $cmp, int $sign): array
    {
        $n = \count($rows);
        if ($n < 2) {
            return $rows;
        }

        $mid = \intdiv($n, 2);
        $left = self::mergeSort(\array_slice($rows, 0, $mid), $cmp, $sign);
        $right = self::mergeSort(\array_slice($rows, $mid), $cmp, $sign);

        $lc = \count($left);
        $rc = \count($right);
        $i = 0;
        $j = 0;
        $out = [];

        while ($i < $lc && $j < $rc) {
            if ($sign * $cmp($left[$i], $right[$j]) <= 0) {
                $out[] = $left[$i];
                $i++;
            } else {
                $out[] = $right[$j];
                $j++;
            }
        }

        while ($i < $lc) {
            $out[] = $left[$i];
            $i++;
        }

        while ($j < $rc) {
            $out[] = $right[$j];
            $j++;
        }

        return $out;
    }

    private static function disabledRank(int $id): int
    {
        return $id % 3 === 0 ? 1 : 0;
    }

    /** @return list<TableColumn> */
    private static function columns(): array
    {
        return [
            TableColumn::make('id', 'ID')
                ->field('id')
                ->width(TableColumnWidth::fixed(72.0))
                ->minWidth(48.0)
                ->align(TextAlign::End)
                ->sortable()
                ->resizable(),

            TableColumn::make('name', '姓名')
                ->field('name')
                ->width(TableColumnWidth::flex(2.0))
                ->minWidth(120.0)
                ->sortable()
                ->resizable()
                // ★ 唯一可编辑的列（阶段 7a）：`field()` 列才有"起始文本 + 提交字段"
                ->editable(),

            TableColumn::make('email', '邮箱')
                ->field('email')
                ->width(TableColumnWidth::flex(3.0))
                ->minWidth(160.0)
                ->sortable()
                ->resizable(),

            TableColumn::make('city', '城市')
                ->field('city')
                ->width(TableColumnWidth::fixed(96.0))
                ->minWidth(64.0)
                ->align(TextAlign::Center)
                ->sortable()
                ->resizable(),

            // ★ 派生值走 `cell()`：状态不是行里的字段，而是由 id 算出来的。
            //   ★★ 它**照样能排** —— `sortable()` 与 `cell()` 互不约束（见 comparators()）
            TableColumn::make('status', '状态')
                ->cell(static fn(array $row): Widget => Text::make(self::disabledRank((int) $row['id']) === 1
                    ? '停用'
                    : '正常')->caption())
                ->width(TableColumnWidth::fixed(88.0))
                ->minWidth(64.0)
                ->align(TextAlign::Center)
                ->sortable()
                ->resizable(),
        ];
    }

    /** @return list<array{id: int, name: string, email: string, city: string}> */
    private static function generateRows(): array
    {
        $rows = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $rows[] = [
                'id' => $i,
                'name' => sprintf('用户 %d', $i),
                'email' => sprintf('user%d@example.com', $i),
                'city' => self::CITIES[$i % 5],
            ];
        }

        return $rows;
    }
}
