<?php
declare(strict_types=1);

namespace App\Showcase\Pages\States;

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
use App\Showcase\Pages\Base\ShowcaseState;

final class LoadMorePageState extends ShowcaseState
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
     * ★ 第二个参数就是本页存在的理由：**合并规则**。没有它 `append()` 会抛。
     */
    private AsyncCommand $rows;

    /** 列表的滚动句柄：触底自动翻页（按钮那条路同时留着） */
    private ScrollController $scroll;

    public function initState(): void
    {
        $caps = $this->caps;
        $this->rows = $this->asyncCommand(
            static fn(int $page): Future => $caps->fetchRows($page),
            static function (mixed $old, mixed $new): array {
                return self::mergePages($old, $new);
            },
        );

        // ★ 触底判据要"两个数"，而且只在**真的还有下一页**时才发：
        //   否则到底后每次微滚都发一条，服务端收到一串空请求。
        $this->scroll = ScrollController::make()->onChanged(function (float $offset, float $max): void {
            if ($max <= 0.0 || $offset < $max - 8.0 || $this->rows->isRunning()) {
                return;
            }

            $value = $this->rows->value();
            if (!$value->hasValue() || \count(self::itemsOf($value)) >= self::totalOf($value)) {
                return;
            }

            $this->loadMore($value);
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

    /**
     * 合并两页 —— **本命令唯一的数据形状知识**，写在一处。
     *
     * ★ 为什么不是框架给个默认的 `array_merge`：载荷形状是业务知识（这里是
     *   `{items, total, source}`），框架猜错时的症状是"数据少了一半"却不报错。
     *   见 `AsyncCommand` 的类注释"合并规则从哪来"。
     *
     * ★ `total` 取**新的**（后端每次都会回同一个总数）；`source` 同理。
     *   旧值只在 `items` 上参与合并 —— 其余字段"以最新一次为准"。
     */
    private static function mergePages(mixed $old, mixed $new): array
    {
        $oldItems = \is_array($old) && isset($old['items']) && \is_array($old['items']) ? $old['items'] : [];
        $newItems = \is_array($new) && isset($new['items']) && \is_array($new['items']) ? $new['items'] : [];

        return [
            'items' => \array_merge($oldItems, $newItems),
            'total' => \is_array($new) ? (int) ($new['total'] ?? 0) : 0,
            'source' => \is_array($new) ? (string) ($new['source'] ?? '') : '',
        ];
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
    private static function itemsOf(AsyncValue $value): array
    {
        $data = $value->dataOr(null);

        return \is_array($data) && isset($data['items']) && \is_array($data['items']) ? $data['items'] : [];
    }

    private static function totalOf(AsyncValue $value): int
    {
        $data = $value->dataOr(null);

        return \is_array($data) ? (int) ($data['total'] ?? 0) : 0;
    }

    /**
     * 下一页的页码 —— **由已加载行数推出来，不另存一个计数器**。
     *
     * ★ 存在两个地方（`$rows` 的值 + `$nextPage` 字段）就一定会不同步：失败后
     *   重试、取消、卸载兜底，每条路都得记得别动它。派生量只跟着值走，没有第二条路。
     */
    private function nextPage(AsyncValue $value): int
    {
        return \intdiv(\count(self::itemsOf($value)), self::PER_PAGE) + 1;
    }

    private function loadMore(AsyncValue $value): void
    {
        $this->rows->append($this->nextPage($value));
    }

    /** 工具条：已加载多少 / 数据来源 / 「加载更多」「重试」 */
    private function toolbar(AsyncValue $value): Widget
    {
        $loaded = \count(self::itemsOf($value));
        $total = self::totalOf($value);
        $running = $this->rows->isRunning();

        // ★ 四支写全（`fold()` 缺键当场抛）；刷新中走 `data` 支 ⇒ 这里问 isRefreshing()
        $label = $value->fold([
            'idle' => static fn(AsyncValue $v): string => '尚未请求',
            'loading' => static fn(AsyncValue $v): string => '正在加载第 1 页…',
            'error' => fn(AsyncValue $v): string => $v->hasValue()
                ? '加载失败（已加载的 ' . $loaded . ' 行还在）'
                : '加载失败（可重试）',
            'data' => fn(AsyncValue $v): string => $v->isRefreshing()
                ? '已加载 ' . $loaded . ' 行 · 正在加载第 ' . $this->nextPage($v) . ' 页…'
                : '已加载 ' . $loaded . ' / 共 ' . $total . ' 行',
        ]);

        return Row::make()->spacing(12.0)->children([
            Expanded::make(Text::make($label)->caption()),

            // ★ 边界判据画在按钮上：已经全了就不该能点（而不是点了没反应）
            Button::make('加载更多')
                ->enabled(!$running && $total > 0 && $loaded < $total)
                ->onPressed(fn() => $this->loadMore($value)),

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
        $items = self::itemsOf($value);

        if ($items === []) {
            return Center::make(Text::make('这一页没有数据')->caption());
        }

        $note = '';
        if ($value->hasError()) {
            $note = '加载第 ' . $this->nextPage($value) . ' 页失败：' . $value->message();
        } elseif ($value->isRefreshing()) {
            $note = '正在加载第 ' . $this->nextPage($value) . ' 页…';
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
