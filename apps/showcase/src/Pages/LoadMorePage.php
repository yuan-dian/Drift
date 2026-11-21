<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Models\PagedRows;
use App\Showcase\Pages\Base\ShowcasePage;
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Geometry\EdgeInsets;
use Drift\State\AsyncCommand;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Card;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\ErrorPlaceholder;
use Drift\Widget\Expanded;
use Drift\Widget\LazyColumn;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\ScrollController;
use Drift\Widget\Spinner;
use Drift\Widget\State;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 加载更多—— **`AsyncCommand::append()` 的消费者**。
 *
 * ## 它和「异步表格」差在哪（一句话）
 *
 * | 页 | 动作 | 第 2 页到达后，界面上的行 |
 * |---|---|---|
 * | 异步表格 | `run($page)` —— **替代** | **换掉**：只剩第 2 页那 20 行 |
 * | 本页 | `append($page)` —— **追加** | **接上**：第 1 页的 20 行还在，共 40 行 |
 *
 * 两者都是"分页取数"，差别只在"已看到的东西该不该留着"。这个差别**不能**靠
 * 一个 flag 表达 —— 它是两种业务（`AsyncCommand` 的类注释有完整论证）。
 *
 * ## 为什么必须有一个页面用它（而不是只留单测）
 *
 * 本仓纪律：**未被消费的分支 = 未实现**。`append()` 的三条判据（合并 / 在途时
 * 旧行还在 / 失败时旧行还在）只有真的跑帧、真的走 `fold()` 才成立；单测能证明
 * `$value` 里有什么，证明不了"界面还在画旧行"。
 * ⇒ 这一页同时是文档里的范文和冒烟/特性测试的被测对象。
 *
 * ## 界面形态
 *
 * 「加载更多」是按钮**也**是滚动触底：滚到底自动取下一页（`ScrollController`
 * 回调里按 `offset >= max - 8` 判），按钮留着给键盘与无障碍——两条路都落到
 * 同一个 `loadMore()`，命令那一侧一行都不用改（"语义在命令、触发在页面"）。
 *
 * ★ 页脚那句"正在加载第 N 页…"来自 `isRefreshing()`：追加中 `fold()` 走的是
 *   **`data` 支**（旧行还在，该画内容），所以"正在忙"必须由页面自己说出来。
 *   同一个理由见 `AsyncTablePage::toolbar()`。
 */
#[Route(id: 'load-more', title: '加载更多 More', icon: IconName::ListBullets)]
final class LoadMorePage extends ShowcasePage
{
    /**
     * 每页行数（必须与 `FetchRowsJob` 的默认 `perPage` 一致 —— 两处不一致会让
     * "下一页"算错，而下一条请求会**静默**跳过几行）。
     */
    public const PER_PAGE = 20;

    /** 行高（逻辑像素）—— `LazyColumn` 的窗口计算全靠它，必须与 `row()` 的实际高度一致 */
    private const ROW_EXTENT = 40.0;

    /**
     * 唯一的异步簿记（`State::asyncCommand()` 造 ⇒ 订阅 + 卸载时自动停）。
     *
     * ★ 载荷是 {@see PagedRows}（不是裸数组）：形状只在它的 `from()` 认一次。
     */
    private AsyncCommand $rows;

    /** 列表的滚动句柄：触底自动翻页（按钮那条路同时留着） */
    private ScrollController $scroll;

    /** @param \Closure(int): Future $fetchRows 同 `AsyncTablePage`：来源由组合根决定 */
    public function __construct(private readonly \Closure $fetchRows)
    {
    }

    public function initState(): void
    {
        $fetch = $this->fetchRows;
        // ★ 取数结果在**落地那一刻**就解码成 `PagedRows`：载荷类型从一而终，
        //   不再"首次 run 是数组、append 之后是对象"来回摇摆。
        // ★ 第二个参数就是本页存在的理由：**合并规则**（`append()` 没有它会抛）。
        //   为什么不让框架默认 `array_merge`：载荷形状是业务知识，框架猜错时的
        //   症状是"数据少了一半"却不报错。规则本身住 `PagedRows::mergedWith()`。
        $this->rows = $this->asyncCommand(
            static fn(int $page): Future => $fetch($page)->then(
                static fn(mixed $payload): PagedRows => PagedRows::from($payload)
            ),
            static fn(mixed $old, mixed $new): PagedRows => PagedRows::from($old)
                ->mergedWith(PagedRows::from($new)),
        );

        // ★ 触底判据要"两个数"，而且只在**真的还有下一页**时才发：
        //   否则到底后每次微滚都发一条，服务端收到一串空请求。
        $this->scroll = ScrollController::make()->onChanged(function (float $offset, float $max): void {
            $value = $this->rows->value();

            if ($max <= 0.0 || $offset < $max - 8.0 || $this->rows->isRunning() || !$value->hasValue()) {
                return;
            }

            $rows = PagedRows::from($value->dataOr(null));
            if ($rows->isComplete()) {
                return;
            }

            $this->loadMore($rows);
        });
    }

    public function onActivate(): void
    {
        $this->rows->run(1);
    }

    /**
     * 取数命令 —— **给测试与调试读的**（页面自己的 `build()` 只读 `value()`）。
     *
     * ★ 存在的理由：本页要证明的是"追加之后**行真的攒起来了**"，而那件事只在
     *   真挂树跑帧之后才成立（golden 基线会跳过"构造器有必填参数"的页，见
     *   `tools/golden-raster.php`）⇒ 需要一个从外面够得着的入口去驱动 `append()`。
     *   页面本身不需要它，所以它是 `@internal` 性质的一行，不是新的业务面。
     */
    public function command(): AsyncCommand
    {
        return $this->rows;
    }

    public function build(BuildContext $ctx): Widget
    {
        $value = $this->rows->value();

        return Card::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('加载更多 · append')->title(),
                Text::make(
                    '第 2 页到达时**接在后面**（不是替代）—— 已读的 20 行不会消失；'
                    . '追加中页脚显示进度，失败时行还在、错误说得出来、可重试。'
                )->caption(),

                $this->toolbar($value),

                // ★ 必须有界高度：`LazyColumn` 自己就是滚动视口
                Expanded::make($this->bodyOf($value)),
            ])
        )->paddingAll(0.0);
    }

    /** 已加载的行（`data` / 刷新中 / 失败但带旧值 三种情况都有） */
    private static function rowsOf(AsyncValue $value): PagedRows
    {
        return PagedRows::from($value->dataOr(null));
    }

    private function loadMore(PagedRows $rows): void
    {
        $this->rows->append($rows->nextPage(self::PER_PAGE));
    }

    /** 工具条：已加载多少 / 数据来源 / 「加载更多」「重试」 */
    private function toolbar(AsyncValue $value): Widget
    {
        $rows = self::rowsOf($value);
        $loaded = $rows->count();
        $total = $rows->total();
        $running = $this->rows->isRunning();

        // ★ 四支写全（`fold()` 缺键当场抛）；刷新中走 `data` 支 ⇒ 这里问 isRefreshing()
        $label = $value->fold([
            'idle' => static fn(AsyncValue $v): string => '尚未请求',
            'loading' => static fn(AsyncValue $v): string => '正在加载第 1 页…',
            'error' => static fn(AsyncValue $v): string => $v->hasValue()
                ? '加载失败（已加载的 ' . $loaded . ' 行还在）'
                : '加载失败（可重试）',
            'data' => static fn(AsyncValue $v): string => $v->isRefreshing()
                ? '已加载 ' . $loaded . ' 行 · 正在加载第 '
                    . self::rowsOf($v)->nextPage(self::PER_PAGE) . ' 页…'
                : '已加载 ' . $loaded . ' / 共 ' . $total . ' 行',
        ]);

        return Row::make()->spacing(12.0)->children([
            Expanded::make(Text::make($label)->caption()),

            // ★ 边界判据画在按钮上：已经全了就不该能点（而不是点了没反应）
            Button::make('加载更多')
                ->enabled(!$running && !$rows->isComplete())
                ->onPressed(fn() => $this->loadMore($rows)),

            Button::make('重试')
                ->enabled(!$running && $value->hasError())
                ->onPressed(fn() => $this->rows->retry()),

            // 长列表回顶：走 animateTo（不碰 scrollTo 的"调完就到"语义）
            Button::make('回到顶部')
                ->onPressed(fn() => $this->scroll->animateTo(0.0)),
        ]);
    }

    /**
     * 四态 → 子树。
     *
     * ★★ 与异步表格页的**唯一差别**在 `error` 支：这里**带旧值时仍然画列表**。
     *   翻页场景下一页失败，用户还有前 20 行可看；整块换成错误页等于把它们藏起来。
     *   框架不改这个决定（`fold()` 照旧把 `error` 路由到 `error` 支）—— 失败必须
     *   让用户看见，但"看见"的方式由页面定：错误条 + 列表，而不是错误页。
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

            'error' => function (AsyncValue $v): Widget {
                if ($v->hasValue()) {
                    return $this->listWithFooter($v);
                }

                return Center::make(
                    Column::make()->spacing(12.0)->children([
                        ErrorPlaceholder::make($v->message()),
                        Button::make('重试')
                            ->enabled(!$this->rows->isRunning())
                            ->onPressed(fn() => $this->rows->retry()),
                    ])
                );
            },

            'data' => fn(AsyncValue $v): Widget => $this->listWithFooter($v),
        ]);
    }

    /** 列表 + 页脚（页脚说"正在忙"或"失败了"；都空闲时什么都不说） */
    private function listWithFooter(AsyncValue $value): Widget
    {
        $rows = self::rowsOf($value);
        $items = $rows->items();

        if ($items === []) {
            return Center::make(Text::make('这一页没有数据')->caption());
        }

        $next = $rows->nextPage(self::PER_PAGE);
        $note = '';
        if ($value->hasError()) {
            $note = '加载第 ' . $next . ' 页失败：' . $value->message();
        } elseif ($value->isRefreshing()) {
            $note = '正在加载第 ' . $next . ' 页…';
        }

        return Column::make()->spacing(8.0)->children([
            Expanded::make(
                LazyColumn::make(self::ROW_EXTENT)
                    ->items($items, static fn(array $row): Widget => self::row($row))
                    ->controller($this->scroll)
            ),
            Text::make($note)->caption(),
        ]);
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): Widget
    {
        return Padding::make(
            Row::make()->spacing(12.0)->children([
                Text::make((string) ($row['id'] ?? ''))->width(56.0),
                Expanded::make(Text::make((string) ($row['name'] ?? ''))->body()),
                Expanded::make(Text::make((string) ($row['email'] ?? ''))->caption()),
                Text::make((string) ($row['city'] ?? ''))->width(64.0),
            ])
        )->symmetric(12.0, 8.0);
    }
}
