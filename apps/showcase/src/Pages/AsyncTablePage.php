<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Geometry\EdgeInsets;
use Drift\Text\TextAlign;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Card;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\ErrorPlaceholder;
use Drift\Widget\Expanded;
use Drift\Widget\FutureBuilder;
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
 *   ② 三态由 `FutureBuilder` 收口（loading / error / data，不再是每页手写）；
 *   ③ 数据到达在**帧内**（`drain` 在 `settle` 之前）⇒ `setState` 同帧生效，不空一帧。
 *
 * ## 数据来源完全由组合根决定（页面零感知）
 *
 * 页面只认一个 `\Closure(int): Future`（"取第 N 页"）：
 *   · 真机（`Bootstrap`）⇒ `AppScope::taskHost()->submit(new FetchRowsJob(...))`（真慢任务）；
 *   · 基准 / 整页快照（`Trees::showcase()`）⇒ `Future::resolved([...静态...])`（稳定、不转圈）。
 *   ⇒ 换成"真 HTTP / 本地模拟 / 直接给数据"都不用改本页一行 —— 这是"业务与 UI 隔离"
 *     在**接线层**的形态，也是本页能被无头基准稳定渲染的原因。
 *
 * ## 为什么整页都放进 `FutureBuilder` 的 builder 里
 *
 * 工具条要显示"第 X 页 / 共 Y 页 / 数据来源"，而这三样只有**数据到达后**才知道。
 * 把工具条留在 builder 外面 ⇒ 要么在 `build()` 里写 State 字段（build 期写 = Fatal），
 * 要么拿不到 total。放进 builder 之后，`AsyncValue` 就是唯一的数据来源。
 *
 * ## 分页按钮不做 disabled（诚实边界）
 *
 * `Button` 的 disabled 视觉在这里没有额外信息（点越界会被 `max/min` 夹住），
 * 所以只夹页码，不加 disabled 态 —— 少一层状态就少一处不一致。
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
     * 当前这一页的 Future（`null` = 还没开始 ⇒ `FutureBuilder` 画 `idle` 态）。
     *
     * ★ 用**字段**而不是"每次 build 都提交一个新任务"：build 可能一帧跑多次
     *   （主题 / 尺寸变化），每次提交就是"一帧几个请求"。请求只由 `load()` 发起。
     */
    private ?Future $future = null;

    /** 当前页（1 起） */
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
     * ★ 在 `initState()` 里调 `setState()` 是安全的：`setState()` 只标脏，
     *   真正的重建发生在首帧 settle 段（`State::setState()` 的注释）。
     */
    public function initState(): void
    {
        $this->load(1);
    }

    /**
     * 发起一次取数（**唯一的提交点**）。
     *
     * ★ 新请求替换旧 Future ⇒ `FutureBuilder` 的 `didUpdateWidget` 看到差异，
     *   重订阅 + 把快照重置回 `loading`（"刷新时能看到转圈"）。
     * ★ 不在这里 `cancel()` 旧 Future：取消是**调用方**的决定（同一个 Future 可能
     *   被别人共享）；本页的行为是"旧结果到达时被新快照覆盖"，等价于取消。
     *   ★ 真实业务里要"迟到的旧响应不许盖新数据"，`apps/users` 的 `UserStore`
     *     演示了显式 `cancel()` 的写法；本页用"换 Future"达到同一效果。
     *
     * ★★ **`$force` 是给"重试"用的**，同时挡住两类白跑：
     *   - 第 1 页点「上一页」⇒ `$page - 1 = 0`，钳回 1，与当前页相同 ⇒ 不重复提交；
     *   - 转圈时连点 ⇒ 同页且在途 ⇒ 忽略（否则排起第二条 1.2s 的后台请求）。
     *   ★ 这两件事本该由 `Button` 的**禁用态**表达（Flutter `onPressed: null`、
     *     WPF `IsEnabled`、Qt `setEnabled(false)` 都有），但 `Button` 目前只有
     *     `onPressed(callable)`、没有 enabled —— 缺口记在
     *     `docs/ASYNC-2026-10-30.md` §11.3，这里先在页面内兜住。
     */
    private function load(int $page, bool $force = false): void
    {
        $target = $page < 1 ? 1 : $page;

        if (!$force && $this->future !== null && $target === $this->page) {
            return;
        }

        $fetch = $this->fetchRows;

        $this->setState(function () use ($fetch, $target): void {
            $this->page = $target;
            $this->future = $fetch($target);
        });
    }

    /**
     * 离开页面 ⇒ **放弃在途请求**。
     *
     * ★ 这是 `FutureBuilder::dispose()` 留下的那一半：它只 `++generation`（丢弃迟到
     *   回调），且在注释里写明"取消是调用方的事"—— 因为同一条 `Future` 可能被多方
     *   共享，只有交出它的人知道"还有没有人要结果"。本页是唯一持有者 ⇒ 这里停是安全的。
     *   不停也能"正确"，只是 worker 会白跑满 1.2s（翻十页留十个空转进程）。
     *
     * ★ `cancel()` **不减 `pending()`** —— 在途任务若把计数掉到 0，帧时钟会睡死，
     *   那条结果就永远躺在完成队列里（`Future::cancel()` 的注释）。所以这里只是
     *   "不再交付"，对帧时钟零影响。
     */
    public function dispose(): void
    {
        $this->future?->cancel();
        $this->future = null;

        parent::dispose();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('异步表格 · FutureBuilder')->title(),
                Text::make(
                    '进页面就请求（后台慢任务 1.2s）—— 转圈时窗口照常可拖、动画照常转；'
                    . '数据到达在帧内 setState ⇒ 同帧重绘。失败可重试，分页换 Future。'
                )->caption(),

                // ★ 必须有界高度：`Table` 自己是滚动容器（同 `TablePage` 的注释）。
                // ★ `$future` 为 `null`（还没开始）时 `FutureBuilder` 自己给 `idle`
                //   ⇒ 本页不必再写一层 `$future === null ? 占位 : FutureBuilder(...)`。
                //   那是 2026-10-30 之前的样子：一个只为"首帧前一瞬"存在的分支。
                Expanded::make(
                    FutureBuilder::make($this->future, function (AsyncValue $value): Widget {
                        return Column::make()->spacing(12.0)->children([
                            $this->toolbar($value),
                            Expanded::make($this->bodyOf($value)),
                        ]);
                    })
                ),
            ])
        )->paddingAll(0.0);
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

        // ★ 这里用 `fold()` 而不是 `if/elseif/else`：**后者只有三个出口**，
        //   `idle` 会落进 `else` 被当成"有数据" ⇒ 显示「第 1 页 / 共 0 页 · 共 0 行」。
        //   四态之后那个 `else` 本身就是错的（漏态 = 静默错）。
        // ★ 四支签名完全一致（都收 `AsyncValue`）—— 见 `fold()` 的类注释：
        //   混合签名（data 收值、idle 零参）在解释器下写错也静默、只在 AOT 崩。
        $label = $value->fold([
            'idle' => static fn(AsyncValue $v): string => '尚未请求',
            'loading' => fn(AsyncValue $v): string => '正在加载第 ' . $this->page . ' 页…',
            'error' => static fn(AsyncValue $v): string => '加载失败（可重试）',
            'data' => function (AsyncValue $v): string {
                $data = $v->value();
                $total = \is_array($data) ? (int) ($data['total'] ?? 0) : 0;
                $pages = $total > 0 ? (int) \ceil($total / self::PER_PAGE) : 0;

                return '第 ' . $this->page . ' 页 / 共 ' . $pages . ' 页 · 共 ' . $total . ' 行';
            },
        ]);

        return Row::make()->spacing(12.0)->children([
            Expanded::make(Text::make($label)->caption()),
            Expanded::make(Text::make($source === '' ? '' : '数据来源：' . $source)->caption()),
            Button::make('上一页')->onPressed(fn() => $this->load($this->page - 1)),
            Button::make('重试')->onPressed(fn() => $this->load($this->page, true)),
            Button::make('下一页')->onPressed(fn() => $this->load($this->page + 1)),
        ]);
    }

    /**
     * 四态 → 子树（`AsyncValue::fold()` 的典型用法）。
     *
     * ★ 2026-10-30 之前是 `if (isLoading) … if (hasError) … else …`：漏写 `error`
     *   分支就落进 `else`、用 `dataOr([])` 画一张**空表** —— 用户看到"这一页没有
     *   数据"，真相却是请求失败了。`fold()` 让漏分支当场抛（见其类注释）。
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
                    Button::make('重试')->onPressed(fn() => $this->load($this->page, true)),
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
