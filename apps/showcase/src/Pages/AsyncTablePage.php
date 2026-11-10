<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Geometry\EdgeInsets;
use Drift\State\AsyncCommand;
use Drift\Text\TextAlign;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Card;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\ErrorPlaceholder;
use Drift\Widget\Expanded;
use Drift\Widget\Row;
use Drift\Widget\Spinner;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 32「异步表格」—— **事件循环 / 异步能力接入示例**（N3 + N5 + N6）。
 *
 * ## 它演示的真实业务形状（不是玩具）
 *
 * 一个列表页最常见的样子：**进页面就请求数据（慢）→ 转圈 → 失败可重试 → 成功画表**。
 * 这里的三个关键点正是用户要的"不能让 UI 卡住"：
 *   ① 取数在**后台**（`ProcessTaskHost` 的 worker 进程里跑，主进程一帧不停）；
 *   ② 请求的整个生命周期由 `AsyncCommand` 收口（发起 / 去重 / 取消 / 重试）；
 *   ③ 数据到达在**帧内**（`drain` 在 `settle` 之前）⇒ `setState` 同帧生效，不空一帧。
 *
 * ## 页面的异步簿记只有两行
 *
 * ```php
 * $this->rows = $this->asyncCommand($this->fetchRows);   // initState：造 + 绑定
 * $this->rows->run($page);                               // 发起
 * $this->rows->value()                                   // build：读四态
 * ```
 *
 * ★ **连 `dispose()` 都不用写**：`State::asyncCommand()` 造的命令由框架在
 *   `unmount()` 时统一停掉（与清理袋同一条兜底）—— 于是"转圈中离开页面 ⇒
 *   worker 白跑满 1.2s"不需要谁记得防。
 *
 * ## 翻页时**表格不消失**（2026-10-31）
 *
 * 点「下一页」发的是 `loading`，但这个 `loading` **带着上一页的行**
 * （`AsyncValue::carriedFrom()`）⇒ `fold()` 把它路由到 `data` 支 ⇒ 表格照旧画，
 * 只有标题变成「正在加载第 2 页…」、翻页按钮变灰。上一版这里整块换成转圈，
 * 内容消失又出现（用户读到的是"卡了一下"）。
 *
 * ★ 于是"刷新中"这句话必须由**页面**说（`isRefreshing()`）—— 数据还在，界面
 *   看起来就和"没在跑"一样。框架不会替页面决定提示长什么样。
 *
 * 之前（用 `FutureBuilder`）同一件事要写：一个 `$future` 字段、一个"换 Future
 * 触发重订阅"的提交点、一层 `FutureBuilder::make(...)` 包装、一段"同页 / 在途就
 * 不重复提交"的判断。那四样里只有最后一样是业务，其余全是**异步机制的簿记**
 * —— 现在它们在 `AsyncCommand` 里，全仓一份。
 *
 * ## 数据来源完全由组合根决定（页面零感知）
 *
 * 页面只认一个 `\Closure(int): Future`（"取第 N 页"）：
 *   · 真机（`Bootstrap`）⇒ `AppScope::taskHost()->submit(new FetchRowsJob(...))`（真慢任务）；
 *   · 基准 / 整页快照（`Trees::showcase()`）⇒ `Future::resolved([...静态...])`（稳定、不转圈）。
 *   ⇒ 换成"真 HTTP / 本地模拟 / 直接给数据"都不用改本页一行 —— 这是"业务与 UI 隔离"
 *     在**接线层**的形态，也是本页能被无头基准稳定渲染的原因。
 *
 * ## 分页用**禁用态**表达边界，不用回调里 return
 *
 * 第 1 页的「上一页」、转圈时的翻页，都由 `Button::enabled()` 画出来（用户看得见
 * "现在不能点"），而不是在 `onPressed` 里悄悄 `return`（点了没反应、也不知道为什么）。
 * 命令的 `isRunning()` 直接就是那个判据 —— 这是 `AsyncCommand` 的存在理由之一。
 */
final class AsyncTablePage extends StatefulWidget
{
    /**
     * @param \Closure(int): Future $fetchRows 组合根接线："取第 N 页"（返回一个 Future）
     */
    public function __construct(private \Closure $fetchRows)
    {
        parent::__construct();
    }

    /**
     * @param \Closure(int): Future $fetchRows
     */
    public static function make(\Closure $fetchRows): self
    {
        return new self($fetchRows);
    }

    public function createState(): State
    {
        return new AsyncTablePageState($this->fetchRows);
    }
}

final class AsyncTablePageState extends State
{
    private const PER_PAGE = 20;

    private const ROW_HEIGHT = 36.0;

    private const HEADER_HEIGHT = 36.0;

    /**
     * 取数命令（**唯一的异步簿记**）。
     *
     * ★ 由 `State::asyncCommand()` 造 ⇒ 已经订阅好、且卸载时会被框架停掉
     *   （见该方法的注释；页面不写 `dispose()`）。
     */
    private AsyncCommand $rows;

    /** 当前页（1 起）—— 纯 UI 态，不属于命令（命令只认"这一次的参数"） */
    private int $page = 1;

    /**
     * @param \Closure(int): Future $fetchRows
     */
    public function __construct(private \Closure $fetchRows)
    {
    }

    /**
     * 进页面就加载第一页 —— 这是"列表页"的真实形状（不是点按钮才加载）。
     *
     * ★ `run()` 在 `initState()` 里调是安全的：它只标脏，真正的重建发生在首帧
     *   settle 段；同步后端（`SyncTaskHost`）下任务当场结算 ⇒ 首帧直接是 `data`。
     */
    public function initState(): void
    {
        $this->rows = $this->asyncCommand($this->fetchRows);
        $this->rows->run(1);
    }

    public function build(BuildContext $ctx): Widget
    {
        $value = $this->rows->value();

        return Card::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('异步表格 · AsyncCommand')->title(),
                Text::make(
                    '进页面就请求（后台慢任务 1.2s）—— 转圈时窗口照常可拖、动画照常转；'
                    . '数据到达在帧内 ⇒ 同帧重绘。转圈时翻页按钮禁用，失败可重试。'
                )->caption(),

                // ★ 必须有界高度：`Table` 自己是滚动容器（同 `TablePage` 的注释）。
                // ★ 不再有 `FutureBuilder` 包装：命令的值直接从 State 读，
                //   界面就是"当前值 → 子树"这一件（少一层控件、少一份订阅机制）。
                Expanded::make(
                    Column::make()->spacing(12.0)->children([
                        $this->toolbar($value),
                        Expanded::make($this->bodyOf($value)),
                    ])
                ),
            ])
        )->paddingAll(0.0);
    }

    /**
     * 翻页（`AsyncCommand` 负责去重：同页且在途 ⇒ 忽略）。
     *
     * ★ 越界由**按钮禁用**挡（第 1 页没有「上一页」、末页没有「下一页」），
     *   这里只做一次钳位兜底 —— 兜底不是判据，判据画在界面上。
     */
    private function load(int $page): void
    {
        $target = $page < 1 ? 1 : $page;

        $this->page = $target;   // 重建在帧末 ⇒ 这里改值安全（不是 build 期写）
        $this->rows->run($target);
    }

    private function retry(): void
    {
        $this->rows->retry();
    }

    /** 工具条：页码 / 数据来源 / 上一页·重试·下一页（全都从 AsyncValue 读） */
    private function toolbar(AsyncValue $value): Widget
    {
        // 数据来源只在 data 态有值；其余三态给空串（`dataOr(null)` 已经保证不串态）
        $source = '';
        $raw = $value->dataOr(null);
        if (\is_array($raw)) {
            $source = (string) ($raw['source'] ?? '');
        }

        // ★ 用 `fold()` 而不是 `if/elseif/else`：**后者只有三个出口**，`idle`
        //   会落进 `else` 被当成"有数据" ⇒ 显示「第 1 页 / 共 0 页 · 共 0 行」。
        // ★ 四支签名完全一致（都收 `AsyncValue`）—— 混合签名在解释器下写错也
        //   静默、只在 AOT 崩（见 `AsyncValue::fold()` 的注释）。
        // ★★ 刷新中走的是 **`data` 支**（旧值还在 ⇒ `fold()` 按"该画哪一块"路由），
        //   所以这里要自己判 `isRefreshing()`：此时表格里还是**上一页**的行，
        //   标题若照旧写「第 2 页」就是在说一句没有依据的话。
        $label = $value->fold([
            'idle' => static fn(AsyncValue $v): string => '尚未请求',
            'loading' => fn(AsyncValue $v): string => $this->loadingLabel(),
            'error' => static fn(AsyncValue $v): string => '加载失败（可重试）',
            'data' => function (AsyncValue $v): string {
                if ($v->isRefreshing()) {
                    return $this->loadingLabel();
                }

                $data = $v->value();
                $total = \is_array($data) ? (int) ($data['total'] ?? 0) : 0;
                $pages = $total > 0 ? (int) \ceil($total / self::PER_PAGE) : 0;

                return '第 ' . $this->page . ' 页 / 共 ' . $pages . ' 页 · 共 ' . $total . ' 行';
            },
        ]);

        // ★★ 禁用态 = "现在能不能点"的唯一表达（Flutter / WPF / Qt 同款）：
        //   转圈时禁用翻页与重试；第 1 页禁用「上一页」；末页禁用「下一页」。
        //   判据直接读命令的 `isRunning()` —— 不需要页面自己记"在不在途"。
        $running = $this->rows->isRunning();
        $pages = $this->pagesOf($value);

        return Row::make()->spacing(12.0)->children([
            Expanded::make(Text::make($label)->caption()),
            Expanded::make(Text::make($source === '' ? '' : '数据来源：' . $source)->caption()),
            Button::make('上一页')
                ->enabled(!$running && $this->page > 1)
                ->onPressed(fn() => $this->load($this->page - 1)),
            Button::make('重试')
                ->enabled(!$running)
                ->onPressed(fn() => $this->retry()),
            Button::make('下一页')
                ->enabled(!$running && $pages > $this->page)
                ->onPressed(fn() => $this->load($this->page + 1)),
        ]);
    }

    /**
     * "正在加载第 N 页…"—— `loading`（首屏）与 `data`+`isRefreshing()`（翻页）共用。
     *
     * ★ 一句话只写一遍：两处各写一份，改文案时必然只改一处。
     */
    private function loadingLabel(): string
    {
        return '正在加载第 ' . $this->page . ' 页…';
    }

    /**
     * 四态 → 子树（`AsyncValue::fold()` 的典型用法）。
     *
     * ★ 手写 `if (isLoading) … elseif (hasError) … else …` 时，漏写 `error`
     *   分支就落进 `else`、画一张**空表** —— 用户看到"这一页没有数据"，真相
     *   却是请求失败了。`fold()` 让漏分支当场抛（见其类注释）。
     */
    private function bodyOf(AsyncValue $value): Widget
    {
        return $value->fold([
            'idle' => static fn(AsyncValue $v): Widget => Center::make(Text::make('尚未加载')->caption()),

            'loading' => static fn(AsyncValue $v): Widget => Center::make(
                Column::make()->spacing(12.0)->children([
                    Spinner::make()->diameter(32.0),
                    Text::make('正在从后台取数…（UI 线程空闲）')->caption(),
                ])
            ),

            'error' => fn(AsyncValue $v): Widget => Center::make(
                Column::make()->spacing(12.0)->children([
                    ErrorPlaceholder::make($v->message()),
                    Button::make('重试')
                        ->enabled(!$this->rows->isRunning())
                        ->onPressed(fn() => $this->retry()),
                ])
            ),

            'data' => function (AsyncValue $v): Widget {
                $data = $v->value();
                $rows = [];
                if (\is_array($data) && isset($data['items']) && \is_array($data['items'])) {
                    $rows = $data['items'];
                }

                if ($rows === []) {
                    return Center::make(Text::make('这一页没有数据')->caption());
                }

                return Table::make(self::columns(), $rows)
                    ->keyOf(static fn(array $row): int => (int) $row['id'])
                    ->rowHeight(self::ROW_HEIGHT)
                    ->headerHeight(self::HEADER_HEIGHT);
            },
        ]);
    }

    /** 总页数（`data` 态从 `total` 算；其余三态未知 ⇒ 0，用于"下一页"的禁用判据） */
    private function pagesOf(AsyncValue $value): int
    {
        $data = $value->dataOr(null);
        if (!\is_array($data)) {
            return 0;
        }

        $total = (int) ($data['total'] ?? 0);

        return $total > 0 ? (int) \ceil($total / self::PER_PAGE) : 0;
    }

    /** @return list<TableColumn> */
    private static function columns(): array
    {
        return [
            TableColumn::make('id', 'ID')
                ->field('id')
                ->width(TableColumnWidth::fixed(72.0))
                ->minWidth(48.0)
                ->align(TextAlign::End),

            TableColumn::make('name', '姓名')
                ->field('name')
                ->width(TableColumnWidth::flex(2.0))
                ->minWidth(120.0),

            TableColumn::make('email', '邮箱')
                ->field('email')
                ->width(TableColumnWidth::flex(3.0))
                ->minWidth(160.0),

            TableColumn::make('city', '城市')
                ->field('city')
                ->width(TableColumnWidth::fixed(96.0))
                ->minWidth(64.0)
                ->align(TextAlign::Center),
        ];
    }
}
