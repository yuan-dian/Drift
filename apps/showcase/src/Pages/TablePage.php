<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;
use Drift\Core\Computed;
use Drift\Geometry\EdgeInsets;
use Drift\State\Cell;
use Drift\Text\TextAlign;
use Drift\Input\KeyChord;
use Drift\Widget\Bind;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Expanded;
use Drift\Widget\Menu\ContextMenu;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\Row;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSort;
use Drift\Widget\TableSortDirection;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 表格（Table）—— P2 阶段 1 + 2 + 3 的**真实消费者**。
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
 * ★ **选择与排序都是受控的**：选中集与排序状态住本页，`Table` 只回调
 *   "点完之后应该是什么"。所以"已选 N 项 / 清空 / 清除排序"都能工作 —— 如果它们
 *   住在控件的 `State` 里，应用**永远读不到**（`State` 外部无引用通道，本仓也没有
 *   `GlobalKey`）。
 *
 * ★ **选中集走的是通道形态，不是值形态**（本页是 `Table::selectionFrom()` 的
 *   真实消费者）：格子归页面，读点归 `TableState` ⇒ 点一行只重建表格子树，
 *   整页 `build()` 不跑。写回仍是本页的事（`onSelectionChange` 里写同一个格子）。
 *   "已选 N 项"那一行用 `Bind` 自己开读点 —— 否则它只能等下一次整页重建才刷新。
 *   排序不同：排序变了要换数据（`Computed` 重算），页面本来就得重建，所以
 *   `sort` 走值形态。
 *
 * ★ **排序由本页重排**（不是控件）：`Table` 不知道 `$row` 里 `name` 是中文还是
 *   日期，比较规则只有应用知道。看 `comparators()` —— 连"状态"这个**派生列**都能排，
 *   因为闭包与 `sortable()` 互不约束。
 *
 * ★ **诚实边界**（页面底部也写了一句）：无 Ctrl+方向键扩选、无拖动框选 ——
 *   都是阶段 7 后半及其前置。单元格编辑已在（阶段 7a 键盘 / 7b 指针：单击落格、
 *   双击进编辑、插入符落在指针处）。横向滚轮已在（阶段 5b：触控板横滑 / Shift+竖轮）。
 *   表头的键盘排序已在（§61：首行 ↑ 进表头 / Enter 三态；§62：Shift 多键追加次键）。
 *   排序后光标**留在原位置**（`focusedRow` 是下标，"眼睛在哪"不跟数据身份，§61.5）。
 */
#[Route(id: 'table', title: '表格 Table', icon: IconName::Grid)]
final class TablePage extends ShowcasePage
{
    private const ROWS = 10000;

    /** 行高 / 表头高（逻辑像素）—— 定高是虚拟化的窗口计算前提 */
    private const ROW_HEIGHT = 40.0;
    private const HEADER_HEIGHT = 40.0;

    /** @var list<string> */
    private const CITIES = ['北京', '上海', '广州', '深圳', '杭州'];

    /**
     * 选中集 —— **通道形态**：`Table` 在它自己的 `build()` 里读，所以点行只重建
     * 表格子树。值形态（`selection(值)`）要求每次点击都用 `setState` 重建整页。
     */
    private Cell $selection;

    /** 当前排序（受控）。值形态递给 `Table`：排序变了要换数据，页面重建不可避免 */
    private Cell $sort;

    /**
     * 数据版本 —— `onCellEdit` 改完行内容后 +1，让派生行重算。
     *
     * ★ 为什么不用"数组身份"当依赖：`allRows` 是普通字段，不是通道；`Computed`
     *   只认通道的版本。改完数据发一次信号，比"每次 build 都重新排序"便宜得多。
     */
    private Cell $dataVersion;

    /**
     * 原始行 —— 只造一次。
     *
     * ★ 这一条是本页的真 bug 修复（加排序时发现）：原先 `rows()` 是在 `build()`
     *   里调的，也就是**每次 build 都重新格式化 10000 行字符串**（滚动一格、
     *   悬停换行、加选一项都算一次 build）。虚拟化省下的正是这笔钱。
     */
    private array $allRows = [];

    /** 当前显示的行（排序后的派生值，`Computed` 缓存到下一次排序 / 改数据） */
    private Computed $rows;

    /**
     * 用户拖出来的列宽（**受控**第 ④ 层：列 key ⇒ 逻辑像素）。
     *
     * ★ 本页只做"拖动中实时更新"，没有存盘 —— 真实应用该在这里 `json_encode`
     *   落盘、下次开窗 `columnWidths()` 传回来。这正是它必须受控的理由：
     *   住在控件里就没有地方能存。
     */
    private array $widthOverrides = [];

    public function initState(): void
    {
        $this->selection = Cell::of(TableSelection::none())->named('table.selection');
        $sort = Cell::of(TableSort::none())->named('table.sort');
        $version = Cell::of(0)->named('table.dataVersion');
        $this->sort = $sort;
        $this->dataVersion = $version;
        $this->allRows = self::generateRows();

        // 只钉两个格子，不把整页钉进依赖图：sort 变或数据变才重排。
        $this->rows = Computed::of(function () use ($sort, $version): array {
            $version->get();
            $current = $sort->get();

            return $this->sortedRows($current instanceof TableSort ? $current : TableSort::none());
        });
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
                    . '排序受控：点表头三态循环（升 → 降 → 取消），Shift+点追加次键，重排由本页做。'
                    . '列宽可拖：拖表头右缘（光标变左右箭头），重置按钮回到声明值。'
                    . '横向可滚：把列拖宽到超出表格宽 ⇒ 底部出现横条，拖它看右边（与竖条同时在右下角交叠，角上归竖条）；'
                    . '不抓条也行 —— 触控板横滑，或按住 Shift 滚竖轮。'
                    . '键盘：Tab 进来出现光标框，方向键移格、Home/End 到首末行（移出视口会自动滚过去）；'
                    . '在首行再按 ↑ 光标进表头（只停在**可排**的列上），Enter 或空格三态排序、'
                    . 'Shift+Enter 追加次键、←→ 换列、Home/End 到首末可排列、↓ 回行区同列。'
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
                        // ★ 通道形态：读点在 TableState，点行不重建本页。
                        ->selectionFrom($this->selection)
                        ->onSelectionChange(function (TableSelection $next): void {
                            $this->selection->set($next);
                        })
                        ->columnWidths($this->widthOverrides)
                        // 上下文菜单的真消费者（§59）：焦点在表上时，右键与 `Shift+F10` / `Apps`
                        // 都开到这一份声明。命令复用 `showcase.refresh`（本应用只注册了它）
                        // ⇒ 真机上选完一项能看见 Snackbar。
                        ->contextMenu(ContextMenu::make()->items([
                            MenuItem::make('刷新数据')->chord(KeyChord::ctrl('R'))->command('showcase.refresh')
                                ->semanticsMeta(acceleratorKey: 'Ctrl+R'),
                            MenuItem::separator(),
                            MenuItem::make('清空选择')->onPressed($this->selection->setTo(TableSelection::none())),
                        ]))
                        ->onColumnResize(fn(string $key, float $width, bool $done) => $this->setState(
                            function () use ($key, $width): void {
                                $this->widthOverrides[$key] = $width;
                            }
                        ))
                        ->sort($this->sortNow())
                        // 第二参是列序号：单键应用拿它当下标，多键应用按 `keys()` 遍历
                        // —— 本页属后者，但回调签名照接（框架契约，不擅自少接参数）。
                        // 重排由 `$rows` 这个 `Computed` 做：写格子就够，不用在这里算。
                        ->onSortChange(function (TableSort $next, int $columnIndex): void {
                            $this->sort->set($next);
                        })
                        // ★ 单元格编辑（阶段 7a）：提交的是**行 key + 列 key + 新文本**，
                        //   写回数据由本页做（控件不碰数据）。改完发一次数据版本信号 ——
                        //   否则改了"姓名"列而正按姓名排序时，行序会与数据不一致。
                        ->onCellEdit(fn(int $rowKey, string $column, string $value) => $this->editCell(
                            $rowKey,
                            $column,
                            $value,
                        ))
                ),
            ])
        )->paddingAll(0.0);
    }

    /** 写回一行并让派生行重算（`onCellEdit` 的落点，事件期、不在 build 里） */
    private function editCell(int $rowKey, string $column, string $value): void
    {
        foreach ($this->allRows as $index => $row) {
            if ((int) $row['id'] === $rowKey) {
                $this->allRows[$index][$column] = $value;
                break;
            }
        }

        $this->dataVersion->update(static fn(int $n): int => $n + 1);
    }

    /**
     * "已选 N 项" + 清空。
     *
     * ★ 包一层 `Bind`：这一行是本页唯一跟着选中变的地方，让读点落在它自己身上
     *   （不包 ⇒ 页面订阅 `$selection`，点一次行整页重建，通道形态白做）。
     */
    private function selectionBar(): Widget
    {
        return Bind::make(fn(): Widget => $this->selectionReadout());
    }

    /**
     * 当前选中集（类型收窄 + 本页**唯一**的 `get()` 读点）。
     *
     * ★ `Cell::get()` 返回 `mixed`：AOT 下 `mixed->count()` 走 `php::Var` 的慢路径，
     *   漏写方法还会在真机上静默崩。收窄一次，后面全是静态类型。
     * ★ **只在 `Bind` 的 builder 里调用** —— 在页面 `build()` 里调就等于页面订阅，
     *   点一次行整页重建，通道形态白做。
     */
    private function selectionNow(): TableSelection
    {
        $selection = $this->selection->get();

        return $selection instanceof TableSelection ? $selection : TableSelection::none();
    }

    /** 派生行（`Computed::get()` 也是 `mixed`，同样要收窄一次） */
    private function rowsNow(): array
    {
        $rows = $this->rows->get();

        return \is_array($rows) ? $rows : [];
    }

    /** 当前排序（同上：类型收窄；页面级读订阅整页，这是预期的 —— 排序要换数据） */
    private function sortNow(): TableSort
    {
        $sort = $this->sort->get();

        return $sort instanceof TableSort ? $sort : TableSort::none();
    }

    private function selectionReadout(): Widget
    {
        $count = $this->selectionNow()->count();

        return Row::make()->spacing(12.0)->children([
            Expanded::make(Text::make($count === 0 ? '未选中任何行' : '已选 ' . $count . ' 项（首尾可见）')->caption()),
            Expanded::make(Text::make($count === 0 ? '' : $this->readoutOfSelection())->caption()),
            Button::make('清空选择')->onPressed($this->selection->setTo(TableSelection::none())),
        ]);
    }

    /** 当前排序 + 清除（受控排序才可能做出这个按钮） */
    private function sortBar(): Widget
    {
        $keys = $this->sortNow()->keys();

        if ($keys === []) {
            $label = '未排序（原始顺序）—— 点表头开始排序';
        } else {
            $parts = [];
            foreach ($keys as [$key, $dir]) {
                $parts[] = self::columnTitleOf($key) . ' ' . ($dir === TableSortDirection::Ascending ? '↑' : '↓');
            }

            // 箭头只说"这一列在排序"，优先级得靠这里的先后读出来（ag-Grid 显示序号，
            // 本页用文字链，不改控件的通用表头外观）。
            $label = '排序：' . \implode(' → ', $parts)
                . '（再点切方向、第三次取消；Shift+点表头或 Shift+Enter 追加次键）';
        }

        return Row::make()->spacing(12.0)->children([
            Expanded::make(Text::make($label)->caption()),
            // 排序是格子变、行是 `Computed` 跟着重算 —— 不用在这里重排、也不用复位派生值。
            Button::make('清除排序')->onPressed($this->sort->setTo(TableSort::none())),
            Button::make('重置列宽')->onPressed(fn() => $this->setState(
                function (): void {
                    $this->widthOverrides = [];
                }
            )),
        ]);
    }

    /** 列 key ⇒ 表头标题（按 key 找，插列不漂） */
    private static function columnTitleOf(string $key): string
    {
        foreach (self::columns() as $column) {
            if ($column->keyName() === $key) {
                return $column->titleText();
            }
        }

        return $key;
    }

    /** 只印前 3 个 key —— 选中一万行时不该把文本撑爆。**只在 `Bind` 内调用** */
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
     * ★ 取消排序 ⇒ 直接给原始数组：本页不需要"记住上一次的顺序"，原始数组一直在
     *   `$allRows` 里。应用的原始顺序可能来自数据库 `ORDER BY`，控件无从知道。
     * ★ 多键按 `keys()` 顺序组比较器链（主键相等才轮到次键）。只读主键会让画面上
     *   两列有箭头、数据却只按一列排 —— 那是撒谎。
     */
    private function sortedRows(TableSort $sort): array
    {
        $comparators = self::comparators();
        $chain = [];

        foreach ($sort->keys() as [$key, $dir]) {
            if (isset($comparators[$key])) {
                $chain[] = [$comparators[$key], $dir === TableSortDirection::Ascending ? 1 : -1];
            }
        }

        if ($chain === []) {
            return $this->allRows;
        }

        return self::mergeSort($this->allRows, $chain);
    }

    /**
     * 每列的比较规则 —— **这就是"控件不排序"的理由本身**。
     *
     * ★ `status` 是 `cell()` 派生列（不是字段）：只有本页知道"停用 = id 能被 3 整除"，
     *   所以也只有本页能定义它的顺序。控件要是自己排，这种列就永远排不了。
     * ★ 按**列 key** 索引（不是列序号）：`TableSort::keys()` 给的就是 key，插一列
     *   不会让整张比较器表错位。
     * ★ 闭包内部一律用**字面量键**取字段（`$a['id']` 而不是 `$a[$key]`）：动态键
     *   取出来是 `mixed`，比较就退化成混合类型比较。
     * ★ 中文按 UTF-8 字节序（`strcmp`），不是拼音序 —— 真要拼音序得应用自己带
     *   `collator`，同样是应用侧知识。
     *
     * @return array<string, callable(array<string, mixed>, array<string, mixed>): int>
     */
    private static function comparators(): array
    {
        return [
            'id' => static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id'],
            'name' => static fn(array $a, array $b): int => \strcmp((string) $a['name'], (string) $b['name']),
            'email' => static fn(array $a, array $b): int => \strcmp((string) $a['email'], (string) $b['email']),
            'city' => static fn(array $a, array $b): int => \strcmp((string) $a['city'], (string) $b['city']),
            'status' => static fn(array $a, array $b): int => self::disabledRank((int) $a['id'])
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
     * @param list<array<string, mixed>> $rows
     * @param list<array{0: callable(array<string, mixed>, array<string, mixed>): int, 1: int}> $chain
     * @return list<array<string, mixed>>
     */
    private static function mergeSort(array $rows, array $chain): array
    {
        $n = \count($rows);
        if ($n < 2) {
            return $rows;
        }

        $mid = \intdiv($n, 2);
        $left = self::mergeSort(\array_slice($rows, 0, $mid), $chain);
        $right = self::mergeSort(\array_slice($rows, $mid), $chain);

        $lc = \count($left);
        $rc = \count($right);
        $i = 0;
        $j = 0;
        $out = [];

        while ($i < $lc && $j < $rc) {
            if (self::compareChain($left[$i], $right[$j], $chain) <= 0) {
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

    /** 主键相等才轮到次键；全相等返回 0，归并的 `<= 0` 保住原始顺序（稳定） */
    private static function compareChain(array $a, array $b, array $chain): int
    {
        foreach ($chain as [$cmp, $sign]) {
            $r = $sign * $cmp($a, $b);
            if ($r !== 0) {
                return $r;
            }
        }

        return 0;
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
