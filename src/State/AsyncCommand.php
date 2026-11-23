<?php
declare(strict_types=1);

namespace Drift\State;

use Drift\Async\AsyncValue;
use Drift\Async\Failure;
use Drift\Async\Future;
use Drift\Core\Readable;
use Drift\Core\Tracker;

/**
 * 一次异步请求的完整生命周期：一个广播 AsyncValue 的命令。
 *
 * 状态机语义（写错不报错，只是行为错）：
 *  - run() 先 emit(loading) 再 emit(结果) ⇒ 订阅者每发起一次收两趟。
 *  - land()/fail() 必须终结在途 Future（cancel 后永不结算，见 abandon()）。
 *  - cancel() 两档：有旧值回 data，无则 idle（"不等了"≠"扔掉已看的"）。
 *  - pending 记账：duplicated() 靠 ran + 严格 === 判定，数组/对象参数不去重。
 *
 * ★ **"哪一趟是结算"不再由本类广播**（2026-11-04 删掉 `subscribeSettledFor()`）：
 *   `cancel()` 回 data 造的 `AsyncValue` 与 `land()` 造的**形状完全相同**，靠值判不出
 *   —— 那条缝因此在 `AsyncScope` 落地后失去全部消费者（迁移 watermark 时归零）。
 *   要区分"结果到了"与"值变了"就走 `AsyncScope`：`await()` 返回就是结算。
 *
 * ★ **读即订阅**：实现 `Readable` ⇒ `value()` / `get()` /
 *   `isRunning()` 在 build 期读都会登记依赖，与 `Cell::get()` 走同一条采集。
 *   于是页面可以直接 `Bind::make(fn () => …$cmd->value()…)`，不必让 Store 先
 *   把四态"转发"成一次广播 —— 那层转发是每一处都要手写、漏一处就静默陈旧的活。
 *   ★ 三个读法**都得记**：`Subject` 自身不采集，漏一个就出现"这条读法不重建"
 *     的分裂语义 —— 那比没有采集更难查。
 *
 * @api
 */
final class AsyncCommand extends Subject implements Readable
{
    /**
     * @var \Closure(mixed): Future 组合根给的"怎么发起一次请求"
     *
     * 统一收一个参数（run() 总是传一个）：混合签名在解释器下写错不报、只在 AOT 崩。
     */
    private \Closure $task;

    /**
     * 追加时的合并规则：`(上一次载荷, 这一次载荷) => 新载荷`。
     *
     * 只在 append() 用（run() 永远替代）；null ⇒ 只能 run()，不能 append()。
     * 两个位置参数：合并需要两边，少一个就分不清"接在后面"还是"盖掉"。
     */
    private ?\Closure $combine;

    private AsyncValue $value;

    /** 在途的那条 Future（`null` = 没在跑） */
    private ?Future $pending = null;

    /** 最后一次的参数（`retry()` 用它重跑） */
    private mixed $arg = null;

    /** `run()` 过没有 —— 没跑过就无从重试 */
    private bool $ran = false;

    /**
     * 上一次发起是**追加**还是**替代**（`retry()` 用它复现同一个动作）。
     *
     * ★ 必须记：重试要复现同一动作。上次若是 append(5) 而重试走了 run(5)，
     *   症状是"前 4 页全没了，只剩第 5 页"，且不报错（看起来像翻了一次页）。
     *   只在发起时写（start()），落地时读 —— 落地那条必是当前 Future（旧的已
     *   cancel() 永不结算，见 abandon()），所以不会串。
     */
    private bool $appending = false;

    /** 应用侧的 code 映射（`\Throwable => string`）；不给就走 `Failure::codeOf()` 的标准通道 */
    private ?\Closure $errorMapper = null;

    private bool $disposed = false;

    /**
     * `$task` 每次都必须返回一条新的 `Future`，且它的根归本命令独有。
     *
     * ★ 换参数时会 cancel() 上一次那条；若根是共享/缓存实例，取消会误伤另一个
     *   持有者，症状是"别处莫名不再更新"，极难归因。要复用就在 $task 里复用结果，
     *   不要复用 Future。
     *   返回派生出来的那条（submit()->then($decode)）是允许的：取消沿链回根，
     *   后端钩子照样被调到。
     *
     * @param \Closure(mixed): Future $task
     * @param null|\Closure(mixed, mixed): mixed $combine 追加时的合并规则；不给 ⇒ 只能 `run()`，不能 `append()`
     *
     * @api
     */
    public static function make(\Closure $task, ?\Closure $combine = null): self
    {
        return new self($task, $combine);
    }

    /**
     * @param \Closure(mixed): Future $task
     * @param null|\Closure(mixed, mixed): mixed $combine
     */
    private function __construct(\Closure $task, ?\Closure $combine)
    {
        $this->task = $task;
        $this->combine = $combine;
        $this->value = AsyncValue::idle();
    }

    /**
     * 当前值（四态之一）—— `build()` 里读它，配 `AsyncValue::fold()` 画四态。
     *
     * @api
     */
    public function value(): AsyncValue
    {
        return $this->get();
    }

    /**
     * `Readable` 入口：读 = 订阅（语义与 `Cell::get()` 相同）。
     *
     * ★ 读点在 `Bind` / 页面 `build()` 里就自动订阅本命令；事件处理器里读不订阅
     *   （帧外无采集帧），正好是"先看当前值再决定"要的。
     *
     * @api
     */
    public function get(): AsyncValue
    {
        Tracker::record($this);

        return $this->value;
    }

    /**
     * 配一条**错误分类**策略：`\Throwable => string code`。
     *
     * 不配就走 `Failure::codeOf()`（普通异常取 `getCode()`，跨进程还原的取载荷里的
     * code）。框架不认识 404 / 鉴权 / 超时这些业务语义 —— 该叫什么由应用决定
     * （同 RTK Query 的 `transformErrorResponse`）。返回 `$this` 便于链式接线。
     *
     * @param null|callable(\Throwable): string $mapper `null` 恢复默认通道
     *
     * @api
     */
    public function mapError(?callable $mapper): self
    {
        $this->errorMapper = $mapper === null ? null : \Closure::fromCallable($mapper);

        return $this;
    }

    /**
     * 有请求在途吗 —— 直接喂给 `Button::enabled()`（转圈时禁用翻页 / 重试）。
     *
     * ★ 同样登记依赖：只读这一个布尔的段（例如刷新按钮）也必须跟着变。它在
     *   `emit()` 之前就已换值，而每次 `pending` 变化都伴随一次 `emit()` ⇒
     *   版本一定跟着动，不会漏帧。
     *
     * @api
     */
    public function isRunning(): bool
    {
        Tracker::record($this);

        return $this->pending !== null;
    }

    /**
     * 发起一次请求 —— **替代**语义（结果盖掉上一次的载荷）。
     *
     * 分几次拿一份数据（无限滚动/加载更多）不是本方法，是 append()：用本方法做
     * 追加会吃掉前面的行。
     * **去重**：同参数且在途 ⇒ 直接返回（挡住"转圈时连点同一页"）。
     * **换参数 ⇒ 作废并真的 cancel()** 上一条。
     * **同步后端也过队列**：run() 后那一帧仍是 loading，下一帧才 data（首帧即 data
     * 仅当 $task 直接返回已结算 Future）。
     * **同步抛出**与异步失败走同一出口（error 态）：否则同步异常会穿过 run() 进
     * 事件循环（dispatch() 无 try/catch），一次点击就走中止序列。
     *
     * @param mixed $arg 传给 `$task` 的参数（页码 / id / 查询条件…）
     *
     * @api
     */
    public function run(mixed $arg = null): void
    {
        if ($this->disposed) {
            return;
        }

        if ($this->duplicated($arg)) {
            return; // 同一个请求还在跑 ⇒ 不重复提交
        }

        $this->start($arg, false);
    }

    /**
     * **追加**一次请求：结果接在上一次载荷**后面**，不是替代它。
     *
     * 与 run() 差别只在落地时用 $combine 合并新旧载荷（land()）；去重/作废/真
     * cancel/loading 带旧值/error 带旧值/重试/卸载兜底全部相同。
     * 在途时界面看到"旧行 + 刷新中"（`isRefreshing()`）：已在看的行不该消失。
     * **没给 `$combine` ⇒ 当场抛**，不静默退化成替代（退化症状是"前面的行被吃掉"，
     * 看起来像翻页，没人会查）。
     *
     * @param mixed $arg 传给 `$task` 的参数（下一页的页码 / 游标…）
     *
     * @throws \LogicException 构造时没给合并规则
     *
     * @api
     */
    public function append(mixed $arg = null): void
    {
        if ($this->disposed) {
            return;
        }

        if ($this->combine === null) {
            throw new \LogicException(
                'AsyncCommand::append() 需要一条合并规则 —— 造命令时给第二个参数：'
                . 'AsyncCommand::make($task, fn(mixed $old, mixed $new) => …)；'
                . '没有它就只能 run()（替代语义），追加会退化成"吃掉前面的行"且不报错'
            );
        }

        // 与 run() 同一条去重：连点「加载更多」不该起第二条请求
        if ($this->duplicated($arg)) {
            return;
        }

        $this->start($arg, true);
    }

    /**
     * 用**上一次的参数**重跑（「重试」按钮）—— 连同"上一次是哪种动作"一起复现。
     *
     * 与 run($sameArg) 差别只在"不去重"：失败后 pending 已 null，去重挡不住；
     * 但在途时点重试必须能打断重来，所以直接 start()。
     * 没 run() 过 ⇒ 无从重试（直接返回，不抛：按钮可能在任何时刻被点到）。
     *
     * @api
     */
    public function retry(): void
    {
        if ($this->disposed || !$this->ran) {
            return;
        }

        $this->start($this->arg, $this->appending);
    }

    /**
     * 放弃在途请求，回到"没在跑"。
     *
     * 有上一次的值 ⇒ 回到 `data`（画面立刻恢复），没有 ⇒ `idle`：
     * 取消是"我不等了"，不是"把已经看到的东西也扔掉"。
     *
     * @api
     */
    public function cancel(): void
    {
        $this->abandon();

        if (!$this->value->isLoading()) {
            return;
        }

        $this->emit($this->value->hasValue()
            ? AsyncValue::data($this->value->value())
            : AsyncValue::idle());
    }

    /**
     * 停掉在途任务，此后所有结算不再落地。
     *
     * 页面侧不需手写：框架在 unmount() 统一 dispose()（dispose() 可覆写，
     * 兜底只能放框架侧）；Store 侧的长命命令由应用自己收摊时调。
     *
     * @api
     */
    public function dispose(): void
    {
        $this->abandon();
        $this->disposed = true;
    }

    /**
     * 「同一个请求还在跑」⇒ run / append 都不该再提交一次。
     *
     * 判据 `$arg === $this->arg`（严格比较）：标量参数天然有效；数组/对象判不出
     * 相等 ⇒ 不去重、照常起新请求并作废旧的（不做 == 或序列化比较：AOT 未验证，
     * 且"多跑一次"比"少跑一次"代价小）。
     * `$this->ran` 不能省：首屏 run(null) 前 $arg 与 pending 都是 null，光看
     * pending 会把"第一次点"当成重复。
     */
    private function duplicated(mixed $arg): bool
    {
        return $this->pending !== null && $this->ran && $arg === $this->arg;
    }

    /**
     * 作废旧请求：真的 `cancel()`。
     *
     * 敢只靠 cancel() 挡迟到结果：`Future::resolve()/reject()` 首句即
     * `if (state !== PENDING || cancelled) return;` ⇒ 取消后那条 Future 永不再
     * 结算，回调也不再跑。这是 Future 的硬契约，与本类正确性相关（后端能否真停活另说）。
     */
    private function abandon(): void
    {
        $pending = $this->pending;
        $this->pending = null;

        if ($pending !== null) {
            $pending->cancel();
        }
    }

    /**
     * 唯一的发起实现 —— 两个提交点（run / append）在这里才分开。
     *
     * @param bool $append `true` ⇒ 落地时用 `$combine` 合并（追加）；`false` ⇒ 替代
     */
    private function start(mixed $arg, bool $append): void
    {
        $this->abandon();

        $this->arg = $arg;
        $this->ran = true;
        $this->appending = $append;

        $task = $this->task;

        // 同步抛出与异步失败走同一出口（error 态）；abandon() 已置空 pending ⇒ 可再点。
        try {
            $future = $task($arg);
        } catch (\Throwable $error) {
            $this->fail($error);

            return;
        }

        $this->pending = $future;

        // 带着上一次的值进 loading：刷新/翻页时列表不闪空；首屏无旧值则整块转圈。
        $this->emit(AsyncValue::loading()->carriedFrom($this->value));

        // 订阅根任务本身：`Future::onProgress()` 不沿 chain() 传递，必须挂 $future。
        // 同步/线程后端不喂进度 ⇒ hasProgress() 恒 false ⇒ 画不定长转圈（非错误）。
        //
        // ★ 派生链**不挂**：`make()` 明确允许 `$task` 返回 `submit()->then(…)`，而那种
        //   Future 上的进度订阅永远收不到（进度只喂根任务）。以前这里照挂不误 ⇒ 每个
        //   走派生链的命令都白挂一次，还留一条 `progress/derived-chain` 诊断 ——
        //   框架自己制造噪音，把真正的"应用挂错链"淹掉。收不到就不挂；要让进度真的
        //   动，用 `AsyncScope::await($root, $onProgress)`。
        if (!$future->isDerived()) {
            $future->onProgress(function (float $fraction): void {
                $this->emit($this->value->withProgress($fraction));
            });
        }

        // 先取到变量再调用（AOT 已验证的形态）。
        $future
            ->then(function (mixed $value): void {
                $this->land($value);
            })
            ->catch(function (\Throwable $error): void {
                // 必须挂 catch：任务失败没人接 ⇒ Future 走 Fatal 序列。
                $this->fail($error);
            });
    }

    /**
     * 成功落地（跑到这里的一定是**当前**那条 Future 的结果，见 abandon()）。
     *
     * ★ 替代还是追加只在这一处分岔，三条件缺一不可，漏任一都会静默错：
     *   - 漏 `appending` ⇒ 翻页变成追加（越翻越长）；
     *   - 漏 `hasValue` ⇒ 首屏把 null 交给合并函数（`$old['items']` 立刻炸）；
     *   - 漏 `combine` 不可能（append() 已挡），仍判一次 = "run() 永远替代"的实现。
     *   条件不成立 ⇒ 走替代（AsyncValue::data($value)），不调 $combine。
     */
    private function land(mixed $value): void
    {
        $this->pending = null;

        $previous = $this->value;
        $combine = $this->combine;

        if ($this->appending && $combine !== null && $previous->hasValue()) {
            $this->emit(AsyncValue::data($combine($previous->value(), $value)));

            return;
        }

        $this->emit(AsyncValue::data($value));
    }

    /**
     * 失败落地 —— 同步抛出与异步失败共用这一个出口。
     *
     * `carriedFrom()`：error 自身无载荷 ⇒ 继承上一次 ⇒ 旧的还在、错误也说出来了；
     * 首屏失败无旧值 ⇒ 整块错误页。追加失败旧行也还在（carriedFrom 不看 run/append）：
     * 已经看到的行不该因下一页失败而消失；判 hasValue() 即可继续画列表 + footer 报错。
     */
    private function fail(\Throwable $error): void
    {
        $this->pending = null;

        $mapper = $this->errorMapper;
        $code = $mapper !== null ? (string) $mapper($error) : Failure::codeOf($error);

        $this->emit(AsyncValue::error($error->getMessage(), $code)->carriedFrom($this->value));
    }

    /**
     * 换值 + 广播（`Subject::notifyChanged()`）。
     *
     * 宿主是谁、收到后干什么（标脏/转发/重算）一概不在这里决定 —— 那是订阅者的事。
     */
    private function emit(AsyncValue $next): void
    {
        $this->value = $next;
        $this->notifyChanged();
    }
}
