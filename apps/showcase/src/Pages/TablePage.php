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
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 24「表格（Table）」—— P2 阶段 1 + 阶段 2 的**真实消费者**（2026-10-26）。
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
 * ★ **阶段 2 的选择是受控的**：选中集住本页的 State，`Table` 只回调"点完之后应该
 *   是什么"。所以"已选 N 项"与"清空"都能工作 —— 如果选中集住在控件的 `State` 里，
 *   应用**永远读不到**（`State` 外部无引用通道，本仓也没有 `GlobalKey`）。
 *
 * ★ **诚实边界**（页面底部也写了一句）：无排序、无列宽拖拽、无键盘焦点格、
 *   列装不下时被裁。它们是阶段 3~7，结构都已预留。
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

    private function selectionNow(): TableSelection
    {
        if ($this->selection === null) {
            $this->selection = TableSelection::none();
        }

        return $this->selection;
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('表格（Table）')->title(),
                Text::make(
                    '10000 行 × 5 列 —— 表头固定不滚，行虚拟化（只造视口覆盖的那几行）。'
                    . '列宽三层：fixed / flex / min-max 夹；奇数行斑马。'
                    . '选择受控：点行选中、Ctrl 加选、Shift 连选、单选模式只留一行。'
                    . '阶段 2 边界：无键盘焦点格；无排序、无列宽拖拽；列装不下时被裁。'
                )->caption(),

                $this->selectionBar(),

                // ★ 必须放进**有界高度**：`Table` 自己就是滚动容器（同 `LazyColumn`），
                //   塞进无界主轴会按内容高铺开 ⇒ 虚拟化失效（窗口变成全部行）。
                Expanded::make(
                    Table::make(self::columns(), self::rows())
                        ->keyOf(static fn(array $row): int => $row['id'])
                        ->rowHeight(self::ROW_HEIGHT)
                        ->headerHeight(self::HEADER_HEIGHT)
                        ->selection($this->selectionNow())
                        ->onSelectionChange(fn(TableSelection $next) => $this->setState(
                            function () use ($next): void {
                                $this->selection = $next;
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

    /** @return list<TableColumn> */
    private static function columns(): array
    {
        return [
            TableColumn::make('id', 'ID')
                ->field('id')
                ->width(TableColumnWidth::fixed(72.0))
                ->align(TextAlign::End),

            TableColumn::make('name', '姓名')
                ->field('name')
                ->width(TableColumnWidth::flex(2.0))
                ->minWidth(120.0),

            TableColumn::make('email', '邮箱')
                ->field('email')
                ->width(TableColumnWidth::flex(3.0)),

            TableColumn::make('city', '城市')
                ->field('city')
                ->width(TableColumnWidth::fixed(96.0))
                ->align(TextAlign::Center),

            // ★ 派生值走 `cell()`：状态不是行里的字段，而是由 id 算出来的
            TableColumn::make('status', '状态')
                ->cell(static fn(array $row): Widget => Text::make(
                    ((int) $row['id']) % 3 === 0 ? '停用' : '正常'
                )->caption())
                ->width(TableColumnWidth::fixed(88.0))
                ->align(TextAlign::Center),
        ];
    }

    /** @return list<array{id: int, name: string, email: string, city: string}> */
    private static function rows(): array
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
