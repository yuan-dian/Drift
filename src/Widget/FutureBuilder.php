<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Async\AsyncValue;
use Drift\Async\Future;

/**
 * **把 `Future` 的四态映射成 UI**（N6，2026-10-30）—— 对标 Flutter `FutureBuilder`。
 *
 * ```php
 * FutureBuilder::make(
 *     $app->tasks()->submit(new FetchRowsJob()),          // 一处提交
 *     function (AsyncValue $v): Widget {                   // 一个 builder 画四态
 *         if ($v->isLoading()) { return Center::make(Spinner::make()); }
 *         if ($v->hasError())  { return ErrorPlaceholder::make($v->message()); }
 *         return Table::make($columns, $v->dataOr([]));
 *     },
 * )
 * ```
 *
 * ## 它解决的问题（替换掉"每页手写三态"）
 *
 * 接入之前，一个"取数 → loading → 失败可重试 → 成功重绘"的页面要写：
 * 三个字段（status / error / rows）+ `load()` 里六步（取消旧的 → 置 loading +
 * 通知 → submit → then → catch → 写回），而且**每一步都可能漏**（漏通知=白屏、
 * 漏 catch=Fatal、漏取消=陈旧覆盖）。`apps/users` 的 `UserStore` 是这条路的样板。
 *
 * 有了本控件，同一件事是：**提交一次 + 写一个四态分支**。三态字段、订阅、
 * 退订、陈旧回调全部收在这里，页面不再有第二份。
 *
 * ## 一条时序纪律（与 `Future::then` 完全同一口径）
 *
 * `then()` / `catch()` **只在 UI 线程的帧内被调用**（`PumpHost::pumpTasks()`，
 * 在 `settle()` 之前）⇒ 回调里的 `setState()` **同帧生效**。所以"数据到了"
 * 与"这一帧画出来"之间没有空帧。
 *
 * ## 三个必须守住的点（都是踩过的坑）
 *
 * ① **卸载后的迟到回调**：`dispose()` 里 `++$generation`，回调先比代号 ——
 *   不等就 `return`。否则 `setState()` 会落到一个已经不在树上的 State 上，
 *   把 `pendingDirty` 抬到没人消费（收敛循环空转）。
 * ② **`didUpdateWidget` 每次都要换 builder**：页面每帧 `build()` 都会 `make()`
 *   一个新实例，闭包可能捕获了新的局部变量。只比 `future` 不换 builder ⇒
 *   builder 永远停在第一帧（"点了筛选，结果没变"的经典静默 bug）。
 * ③ **换 `future` 要重订阅 + 重置快照**：新请求起手，界面必须先回到 `loading`，
 *   否则旧数据会一直挂到新结果回来（"刷新时看不到转圈"）。
 *   ★ 旧 Future **不在这里 `cancel()`**：同一个 Future 可能被别人共享（`Future::all`
 *     的成员），取消它是**调用方**的决定，不是渲染器的。
 *
 * ## 分层
 *
 * 本类住在 `Drift\Widget`，只依赖 `Drift\Async` 的两个**纯值类型**（`Future` /
 * `AsyncValue`）。`Async` 只依赖 `Errors`，比 `Widget` 更低 ⇒ 方向向下、无环
 * （`DependencyDirection` 对应放行）。
 *
 * @api
 */
final class FutureBuilder extends StatefulWidget
{
    /**
     * @param ?Future $future 要跟踪的异步值；**`null` = 还没开始**（builder 收到
     *        `AsyncValue::idle()`）—— 对应 Flutter `FutureBuilder(future: null)`
     *        的 `ConnectionState.none`，也让「点按钮才加载」这种页不必自己
     *        再写一层 `$future === null ? 占位 : FutureBuilder(...)`
     * @param \Closure(AsyncValue): Widget $builder 四态 → 子树
     *
     * ★ 闭包参数是 `AsyncValue`（不可变值），不是 `Future` —— builder 只该回答
     *   "现在画什么"，不该再碰异步机制。
     */
    public function __construct(
        private ?Future $future,
        private \Closure $builder,
    ) {
        parent::__construct();
    }

    /**
     * @param \Closure(AsyncValue): Widget $builder
     */
    public static function make(?Future $future, \Closure $builder): self
    {
        return new self($future, $builder);
    }

    /** @internal State 在 `didUpdateWidget` 里比它判断"要不要重订阅" */
    public function future(): ?Future
    {
        return $this->future;
    }

    /** @internal State 每次换新（见类注释 ②） */
    public function builder(): \Closure
    {
        return $this->builder;
    }

    public function createState(): State
    {
        return new FutureBuilderState($this->future, $this->builder);
    }
}

final class FutureBuilderState extends State
{
    /**
     * 当前快照（`future` 为 `null` 时是 `idle`，否则从 `loading` 起）。
     *
     * ★ 若 `Future` 在 `initState()` 时**已经结算**，`then()` 会当场同步跑
     *   （`Future::chain()` 的已结算分支）⇒ 首帧就是 `data` / `error`，
     *   不会白闪一帧 `loading`。
     */
    private AsyncValue $snapshot;

    private ?Future $future;

    /** @var \Closure(AsyncValue): Widget */
    private \Closure $builder;

    /**
     * 订阅代号（只增不减）。
     *
     * ★ 换 Future / 卸载都 +1 ⇒ 在此之前挂上的回调全部作废。这是"迟到回调"
     *   唯一的判据，比 `mounted` 更硬（`mounted` 只看"还在不在"，看不了
     *   "是不是**这一轮**的回调"）。
     */
    private int $generation = 0;

    /**
     * @param \Closure(AsyncValue): Widget $builder
     */
    public function __construct(?Future $future, \Closure $builder)
    {
        $this->future = $future;
        $this->builder = $builder;
        // ★ `null` = 请求还没发出 ⇒ `idle`（不是 `loading`：画转圈会骗用户）
        $this->snapshot = $future === null ? AsyncValue::idle() : AsyncValue::loading();
    }

    /**
     * 订阅只在这里做（`State` 的纪律：build 里订阅会随重建次数增长）。
     */
    public function initState(): void
    {
        $this->subscribe();
    }

    public function didUpdateWidget(Widget $widget): void
    {
        // 新描述已由 `StatefulElement` 用 `adoptWidget()` 落好；这一句是幂等兜底。
        parent::didUpdateWidget($widget);

        if (!$widget instanceof FutureBuilder) {
            return; // 不可能发生（框架按 createState() 配对）；防御，不改行为
        }

        // ★ ② 每次换新 builder（页面每帧都 make 一个新闭包）
        $this->builder = $widget->builder();

        $next = $widget->future();
        if ($next === $this->future) {
            return; // 同一个 Future ⇒ 订阅仍然有效，别白白重订
        }

        $this->future = $next;

        // ★ ③ 回到"还没开始"：撤销订阅 + 下次 build 画 idle
        if ($next === null) {
            ++$this->generation;   // 旧订阅的回调全部作废
            $this->snapshot = AsyncValue::idle();

            return;
        }

        // ★ ④ 新请求：先回到 loading，再订阅（否则旧数据一直挂着）
        $this->snapshot = AsyncValue::loading();
        $this->subscribe();
    }

    /**
     * 卸载：让所有在途回调失效（见类注释 ①）。
     *
     * ★ 只 `++$generation`，**不 cancel** Future —— 取消是调用方的事。
     */
    public function dispose(): void
    {
        ++$this->generation;
    }

    public function build(BuildContext $ctx): Widget
    {
        return ($this->builder)($this->snapshot);
    }

    /**
     * 挂 `then` / `catch`，并按代号过滤迟到回调。
     *
     * ★ 链形是 `then()->catch()`：`then` 的回调自己抛异常时，异常会落到下游
     *   `catch`（`Future::apply()` 的 `rejectCore($e, true)`）⇒ **不会**变成
     *   "任务失败没人接"的 Fatal。这一点与"提交后立刻挂 catch"同一纪律。
     */
    private function subscribe(): void
    {
        $gen = ++$this->generation;

        $future = $this->future;
        if ($future === null) {
            return; // 还没开始：无可订阅（快照已由调用处置成 `idle`）
        }

        $future
            ->then(function (mixed $value) use ($gen): void {
                if ($gen !== $this->generation) {
                    return; // 这一轮已作废（换了 Future / 卸载了）
                }

                $this->setState(function () use ($value): void {
                    $this->snapshot = AsyncValue::data($value);
                });
            })
            ->catch(function (\Throwable $error) use ($gen): void {
                if ($gen !== $this->generation) {
                    return;
                }

                $this->setState(function () use ($error): void {
                    $this->snapshot = AsyncValue::error($error->getMessage());
                });
            });
    }
}
