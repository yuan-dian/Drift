<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Models\ConcurrentEntry;
use App\Showcase\Models\ConcurrentResult;
use App\Showcase\Pages\Base\ShowcasePage;
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Geometry\EdgeInsets;
use Drift\State\AsyncCommand;
use Drift\Widget\AsyncView;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Card;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Expanded;
use Drift\Widget\Row;
use Drift\Widget\Spinner;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 并发取数—— **`Future::allSettled()` 的消费者**。
 *
 * ## 它回答的那个问题
 *
 * 「同时等 N 个接口」用 `maxWorkers = 3` + `Future::allSettled([...])`。
 * 不走单 worker `curl_multi`：本运行时 `curl_multi_init()` 一句就 `exit 139`
 * （`ASYNC-ARCH-2026-10-31.md` §14.1）。不走 5 worker：多两份运行时开销，
 * 结果拼接也没有现成先例；本页只证明并发聚合。
 *
 * ## 页面做的事（两行）
 *
 * ```php
 * $this->dash = $this->asyncCommand(
 *     fn(mixed $pages): Future => ConcurrentResult::gather($fetch, $pages),  // ← 本页存在的理由
 * );
 * ```
 *
 * ★ 编排（逐条计时 + `allSettled` + 汇总）与解码都住 {@see ConcurrentResult}：
 *   那部分是纯逻辑，挂树之后只剩反射能测。
 *
 * ★ **并发不在这里发生**：页面只是"一次发三条"，三条真的同时在跑是因为组合根
 *   把池开到了 3（`ProcessTaskHost(..., 3)`）。在默认池（`maxWorkers = 1`）上
 *   同一段代码会**串行**跑完 —— 总耗时是三条相加，而且**不报错**。
 *   ⇒ 这是本页最要紧的一条：**并发度是组合根的事，页面只表达"这些互不依赖"**。
 *   页脚那行把两个数都摆出来（总耗时 / 逐条之和），串行时它们相等，一眼能看出来。
 *
 * ★ **为什么用 `allSettled()` 而不是 `all()`**：拼看板最怕"一个接口挂了整页变错误页"。
 *   `all()` 的语义是"缺一条结论就不成立"（它还会**取消**剩下几条），适合多步依赖
 *   里的前一步；本页要的是"三块数据各自算数" ⇒ `allSettled()`。差别只有这一处，
 *   但选错时的症状是"另外两条白跑了、页面上什么也没有"，看起来像一次普通失败。
 *
 * ## 为什么是"三页数据"而不是三个不同接口
 *
 * mock 后端只有 `/api/rows` 一个取数接口，硬造三个接口只会让示例多出两份与
 * 主题无关的代码。三条任务**互不依赖**这件事，与它们打的是不是同一个 URL 无关 ——
 * 判据在测试里是"三条同时在途"，在界面上是"总耗时 ≈ 最慢那条"。
 *
 * ## 一个诚实的边界
 *
 * 池容量 3 ⇒ 最多三条真并行。**同时等 5 个接口**要么把池开到 5（5 份 AOT 运行时，
 * 每份几十 MB），要么让服务端一次答完（聚合接口 / BFF）。本仓不提供"一个 worker
 * 内并发等 5 条连接"的能力 —— 那条路（`curl_multi`）在本运行时上是死的，见上文。
 */
#[Route(id: 'concurrent', title: '并发取数 Concurrent', icon: IconName::Grid)]
final class ConcurrentFetchPage extends ShowcasePage
{
    /** 同时取哪几页 —— 一条任务的"页码"在这里，不在页面上散着 */
    public const PAGES = [1, 2, 3];

    private AsyncCommand $dash;

    /** @param \Closure(int): Future $fetchRows 同 `AsyncTablePage`：来源由组合根决定 */
    public function __construct(private readonly \Closure $fetchRows)
    {
    }

    public function initState(): void
    {
        // ★ 先把闭包取成局部量：`$task` 会被存起来**稍后**调用，闭包里用 `$this`
        //   就会把整个 State 钉在命令上（卸载之后还活着）。局部量只钉住那一个闭包。
        $fetch = $this->fetchRows;

        // 编排（逐条计时 + allSettled + 汇总）住 `ConcurrentResult::gather()`；
        // 结果在**落地那一刻**就解码成对象 ⇒ 载荷类型从一而终。
        $this->dash = $this->asyncCommand(
            static function (mixed $pages) use ($fetch): Future {
                /** @var list<int> $list */
                $list = \is_array($pages) ? \array_values($pages) : [];

                return ConcurrentResult::gather($fetch, $list)->then(
                    static fn(mixed $payload): ConcurrentResult => ConcurrentResult::from($payload)
                );
            },
        );
    }

    public function onActivate(): void
    {
        // 全常驻容器首帧就 mount 完所有页 ⇒ 并发任务留到首次可见才发。
        $this->dash->run(self::PAGES);
    }

    /**
     * 取数命令 —— 给测试与调试读的（页面自己的 `build()` 只读 `value()`）。
     *
     * ★ 理由同 `LoadMorePage::command()`：`golden-raster.php` 会跳过"构造器
     *   有必填参数"的页，所以本页 `build()` 没有基线覆盖 ⇒ 需要一个外面够得着的
     *   入口去驱动"再发一次"。
     */
    public function command(): AsyncCommand
    {
        return $this->dash;
    }

    public function build(BuildContext $ctx): Widget
    {
        $value = $this->dash->value();

        return Card::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('并发取数 · allSettled')->title(),
                Text::make(
                    '一次发出 ' . \count(self::PAGES) . ' 条后台任务，三条同时在途 ⇒ 总耗时 ≈ 最慢那条；'
                    . '串行才会是三条相加。一条失败不影响另外两条（这是 allSettled 与 all 的唯一差别）。'
                )->caption(),

                $this->toolbar($value),

                Expanded::make($this->body()),
            ])
        )->paddingAll(0.0);
    }

    /**
     * 四态 → 子树。
     *
     * ★ `error` 支**不是**死代码：`allSettled()` 自己不失败，但 `$task` 可以
     *   **同步**抛（`$fetchRows` 抛、`PAGES` 传错）—— `AsyncCommand` 会把那种
     *   异常折成 `error` 态。`AsyncView` 仍走 `fold()` 的穷尽路由。
     */
    private function body(): Widget
    {
        return AsyncView::of($this->dash, fn(AsyncValue $v): Widget => $this->cards($v))
            ->idle(static fn(AsyncValue $v): Widget => Center::make(Text::make('尚未取数')->caption()))
            ->loading(static fn(AsyncValue $v): Widget => Center::make(
                Column::make()->spacing(12.0)->children([
                    Spinner::make()->diameter(32.0),
                    Text::make(\count(self::PAGES) . ' 条同时在途…（UI 线程空闲）')->caption(),
                ])
            ))
            ->retryOn($this->dash->retry(...))
            ->widget();
    }

    /** 三张卡并排 —— 一条就是一格，成败各自算数 */
    private function cards(AsyncValue $value): Widget
    {
        $result = ConcurrentResult::from($value->dataOr(null));

        $children = [];
        foreach (self::PAGES as $page) {
            $children[] = Expanded::make(self::card($page, $result->entryOf($page)));
        }

        return Row::make()->spacing(12.0)->children($children);
    }

    /**
     * 一张卡：成功画"多少行 + 数据来源 + 耗时"，失败画"失败 + 原因"。
     *
     * ★ `$entry === null` 是"没发这条请求"，不是"这条失败了" —— 两句话不一样。
     */
    private static function card(int $page, ?ConcurrentEntry $entry): Widget
    {
        $ok = $entry !== null && $entry->ok();
        $spent = $entry?->spentMs();

        return Card::make(
            Column::make()->spacing(8.0)->padding(EdgeInsets::all(14.0))->children([
                Text::make('第 ' . $page . ' 页')->caption(),
                Text::make($ok ? $entry->rowCount() . ' 行' : '失败')->title(),
                Text::make($ok ? $entry->source() : ($entry?->error() ?? '没有发起'))->caption(),
                // ★ 失败的那条没有耗时（`then` 没跑到）—— 画「—」而不是 0：
                //   0 会让"逐条之和"看起来像真的
                Text::make($spent !== null ? \round($spent) . ' ms' : '—')->caption(),
            ])
        )->paddingAll(0.0);
    }

    /**
     * 工具条：一行汇总 + 两个按钮。
     *
     * ★ 汇总那行是本页的**判据可视化**：`总` 与 `逐条之和` 摆在一起。
     *   在池容量 1 的宿主上两个数会几乎相等 —— 那就是"写了并发但其实串行"的样子。
     */
    private function toolbar(AsyncValue $value): Widget
    {
        $summary = $value->fold([
            'idle' => static fn(AsyncValue $v): string => '尚未取数',
            'loading' => static fn(AsyncValue $v): string => '请求在途…',
            'error' => static fn(AsyncValue $v): string => '发起就失败了：' . $v->message(),
            'data' => fn(AsyncValue $v): string => $this->summaryOf($v),
        ]);

        return Row::make()->spacing(12.0)->children([
            Expanded::make(Text::make($summary)->caption()),

            Button::make('再发一次')
                ->enabled(!$this->dash->isRunning())
                ->onPressed(fn() => $this->dash->run(self::PAGES)),

            Button::make('重试')
                ->enabled(!$this->dash->isRunning() && $value->hasError())
                ->onPressed(fn() => $this->dash->retry()),
        ]);
    }

    /** "总 1250 ms · 3 条逐条之和 3610 ms（并发省了 2360 ms）" */
    private function summaryOf(AsyncValue $value): string
    {
        $result = ConcurrentResult::from($value->dataOr(null));
        $total = $result->totalMs();
        $sum = $result->sumMs();
        $timed = $result->timedCount();

        $label = '总 ' . \round($total) . ' ms · ' . $timed . ' 条逐条之和 ' . \round($sum) . ' ms';

        // ★ 只有"省了"才说"省了"：相等或更慢时（池容量 1 / 机器卡）如实显示差值
        if ($timed > 1) {
            $saved = $sum - $total;
            $label .= $saved > 1.0
                ? '（并发省了 ' . \round($saved) . ' ms）'
                : '（**没有省** —— 并发度可能在宿主那一侧是 1）';
        }

        return $label;
    }
}
