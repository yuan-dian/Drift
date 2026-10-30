<?php
declare(strict_types=1);

namespace Drift\State;

use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Widget\State;

/**
 * **一次异步请求的完整生命周期**（2026-10-30）—— 一个住在 `State` 里的命令。
 *
 * ```php
 * // 页面 State
 * $this->rows = AsyncCommand::make($this, $this->fetchRows);  // 组合根给的取数闭包
 *
 * public function initState(): void { $this->rows->run(1); }        // 进页面就取第一页
 * private function load(int $p): void { $this->rows->run($p); }     // 翻页
 * public function build($ctx) { return $this->view($this->rows->value()); }
 * public function dispose(): void { $this->rows->dispose(); }       // 离开页面放弃在途
 * ```
 *
 * ## 它替掉了什么（`FutureBuilder` 为什么会是错的解）
 *
 * `FutureBuilder` 的入参是一个 `Future` —— 而 `Future` **无主**：谁发起的、
 * 还能不能重跑、要不要取消旧的，控件一概不知道。于是这三件事全摊给页面：
 *
 * | 事 | `FutureBuilder` 下由谁做 | 本类下 |
 * |---|---|---|
 * | 请求从哪来 | 页面自己存 `$this->future`、在 `load()` 里提交 | `run($arg)` |
 * | 旧请求怎么办 | 页面记得换 Future（换错就"看不到转圈"） | `run()` 内部作废 + 取消 |
 * | 迟到的旧结果 | 控件另配一个"订阅代号"挡（它不敢取消共享的 Future） | 真的 `cancel()` —— `Future` 此后**永不结算** |
 * | 重试 | 页面再写一遍同样的提交 | `retry()`（记得上次参数） |
 * | 去重 | 页面自己判"是不是同页且在途" | `run()` 内建 |
 *
 * ★ 关键的结构性差异：**本类是它发起的那条 `Future` 的唯一持有者** ⇒ 它可以
 *   合法地 `cancel()`（`Future` 的注释说"取消是调用方的决定"，那个调用方就是这里）。
 *   `FutureBuilder` 不敢取消，因为传进来的 Future 可能被别人共享。
 *
 * ## 为什么要住在 `State` 里（而不是做成 Widget / Store）
 *
 * ① **生命周期对齐**：命令随页面生灭，`dispose()` 一次就停掉在途任务。
 *   做成 Widget 就要再发明一套"控件卸载 ⇒ 谁去取消"；做成 Store 就要回答
 *   "什么时候清缓存" —— 而这两个问题本仓都已经有答案（`State` / `Subject`）。
 * ② **通知走既有设施**：值一变就 `State::markDirty()`，与 `Cell` 同一条路
 *   ⇒ **UI 侧不需要任何新控件**（`FutureBuilder` 因此没有存在理由）。
 * ③ **跨页共享的数据本来就该住 Store**（§11.5），那种场景用 `Subject` +
 *    `SubjectBuilder`，不是给命令再加一套通知。
 *
 * ★ 构造收 `State` 是**故意的**：标脏不是可选项，把它钉进构造参数 ⇒
 *   "造了命令但没人会重建"这个写法不存在。
 *
 * ## 与成熟 GUI 的对照
 *
 * | 本类 | 对应物 |
 * |---|---|
 * | `run($arg)` | React Query `refetch` / WPF `ICommand.Execute` / Riverpod `AsyncNotifier` 的方法 |
 * | `value()` | `AsyncData` / `AsyncSnapshot` / `AsyncValue`（本仓 `AsyncValue` 四态） |
 * | `retry()` | React Query `refetch()`（用同一组 key） |
 * | `cancel()` | `AbortController.abort()` / `CancellationTokenSource.Cancel()` |
 * | 去重 | React Query 的 `dedupe`（本类只做"同参数在途 ⇒ 忽略"这一条） |
 *
 * ## 不做的事（诚实边界）
 *
 * - **超时**：需要计时端口，而 `Async` 层只认 `Errors`（依赖方向）⇒ 计时端口
 *   属于 `App`（`TimerHost` 在那里）。要超时就让任务侧自己管（`ProcessTaskHost`
 *   的 worker 超时是后端职责），或将来把端口显式注入进来。**不做半个**。
 * - **自动重试 N 次**：重试策略是业务决定（哪些错误值得重试），框架默认重试
 *   会把"幂等性假设"藏进基础设施。给 `retry()`，次数留给调用方。
 * - **缓存 / 跨页共享**：见上面 ③。
 *
 * @api
 */
final class AsyncCommand
{
    /**
     * @var \Closure(mixed): Future 组合根给的"怎么发起一次请求"
     *
     * ★ **统一收一个参数**（`run()` 总是传一个）：混合签名（"有时传、有时不传"）
     *   在解释器下写错不报、只在 AOT 崩 —— 本仓已经这样炸过一次
     *   （`AsyncValue::fold()` 的注释有完整案例）。
     */
    private \Closure $task;

    /** 值的宿主：值一变就标它的脏 */
    private State $owner;

    private AsyncValue $value;

    /** 在途的那条 Future（`null` = 没在跑） */
    private ?Future $pending = null;

    /** 最后一次的参数（`retry()` 用它重跑） */
    private mixed $arg = null;

    /** `run()` 过没有 —— 没跑过就无从重试 */
    private bool $ran = false;

    private bool $disposed = false;

    /**
     * @param \Closure(mixed): Future $task
     *
     * @api
     */
    public static function make(State $owner, \Closure $task): self
    {
        return new self($owner, $task);
    }

    /**
     * @param \Closure(mixed): Future $task
     */
    private function __construct(State $owner, \Closure $task)
    {
        $this->owner = $owner;
        $this->task = $task;
        $this->value = AsyncValue::idle();
    }

    /**
     * 当前值（四态之一）—— `build()` 里读它，配 `AsyncValue::fold()` 画四态。
     *
     * @api
     */
    public function value(): AsyncValue
    {
        return $this->value;
    }

    /**
     * 有请求在途吗 —— 直接喂给 `Button::enabled()`（转圈时禁用翻页 / 重试）。
     *
     * @api
     */
    public function isRunning(): bool
    {
        return $this->pending !== null;
    }

    /**
     * 发起一次请求（**唯一的提交点**）。
     *
     * ★ **去重**：同参数且在途 ⇒ 直接返回（挡住"转圈时连点同一页"）。
     *   判据是 `$arg === $this->arg`（严格比较）：标量参数（页码 / id / 查询串）
     *   天然有效；数组 / 对象参数判不出相等 ⇒ 不去重、照常起新请求并作废旧的
     *   —— **不做 `==` 或序列化比较**：那两种在 AOT 下没验证过，而"多跑一次"
     *   的代价比"少跑一次"小得多。
     * ★ **换参数 ⇒ 作废旧请求并真的 `cancel()`**（见类注释的所有权论证）。
     * ★ 同步后端（`SyncTaskHost`）下 `$task()` 返回的 Future 已经结算 ⇒
     *   `then()` 当场同步跑 ⇒ 首帧就是 `data`，**不会白闪一帧 loading**。
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

        if ($this->pending !== null && $this->ran && $arg === $this->arg) {
            return; // 同一个请求还在跑 ⇒ 不重复提交
        }

        $this->start($arg);
    }

    /**
     * 用**上一次的参数**重跑（「重试」按钮）。
     *
     * ★ 与 `run($sameArg)` 的差别只在"不去重"：失败之后 `pending` 已是 `null`，
     *   去重挡不住它 —— 但**在途时点重试**必须能打断重来，所以这里直接 `start()`。
     * ★ 没 `run()` 过 ⇒ 无从重试（直接返回，不抛：按钮可能在任何时刻被点到）。
     *
     * @api
     */
    public function retry(): void
    {
        if ($this->disposed || !$this->ran) {
            return;
        }

        $this->start($this->arg);
    }

    /**
     * 放弃在途请求，回到"没在跑"（`loading` ⇒ `idle`；已有数据则保留数据）。
     *
     * @api
     */
    public function cancel(): void
    {
        $this->abandon();

        if ($this->value->isLoading()) {
            $this->emit(AsyncValue::idle());
        }
    }

    /**
     * 页面卸载：停掉在途任务，此后所有结算不再落地。
     *
     * ★ 与 `dispose()` 同名不是巧合 —— 它是 `State::dispose()` 那一半：
     *   页面走了，worker 不该白跑满 1.2s（翻十页留十个空转进程）。
     *
     * @api
     */
    public function dispose(): void
    {
        $this->abandon();
        $this->disposed = true;
    }

    /**
     * 作废旧请求：真的 `cancel()`（代号 +1 那一步**没有**，理由见 `land()`）。
     *
     * ★★ 为什么敢只靠 `cancel()` 挡迟到结果：`Future::resolve()` / `reject()`
     *   的第一句就是 `if ($this->state !== self::PENDING || $this->cancelled)
     *   return;` ⇒ **取消之后那条 Future 再也不会结算**，回调也就不会再跑。
     *   那是 `Future` 的硬契约，不是"尽力而为"的后端行为（后端能不能真的停
     *   下手上的活是另一回事，与本类的正确性无关）。
     *
     * ★ 对照：`FutureBuilder` 必须另配一个"订阅代号"，因为传进来的 `Future`
     *   可能是别人共享的、它不敢取消。本类是唯一持有者 ⇒ 不需要第二道闸，
     *   也就不需要"没被任何测试消费的分支"（本仓纪律：未消费的分支 = 未实现）。
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
     * @param mixed $arg
     */
    private function start(mixed $arg): void
    {
        $this->abandon();

        $this->arg = $arg;
        $this->ran = true;

        $task = $this->task;
        $future = $task($arg);

        $this->pending = $future;
        $this->emit(AsyncValue::loading());

        // ★ 先取到变量再调用（AOT 已验证的形态；`($this->task)($arg)` 没验过）
        $future
            ->then(function (mixed $value): void {
                $this->land(AsyncValue::data($value));
            })
            ->catch(function (\Throwable $error): void {
                // ★ 必须挂 `catch`：任务失败没人接 ⇒ `Future` 走 Fatal 序列（G2）
                $this->land(AsyncValue::error($error->getMessage()));
            });
    }

    /** 结算（能跑到这里的一定是**当前**那条 Future 的结果 —— 见 `abandon()`） */
    private function land(AsyncValue $next): void
    {
        $this->pending = null;
        $this->emit($next);
    }

    /**
     * 换值 + 标脏。
     *
     * ★★ **宿主不在树上就不标脏**：卸载之后迟到的结算若还 `markDirty()`，
     *   那个脏永远没人消费 ⇒ `State::$pendingDirty` 漂移（每帧多扫一遍，
     *   **不报错**，是 `State` 注释里点名的失败形态）。这里先问一句挂载状态，
     *   于是"`dispose()` 忘了调"从"静默的性能退化"降级成"静默丢弃结果"。
     */
    private function emit(AsyncValue $next): void
    {
        $this->value = $next;

        $element = $this->owner->element();
        if ($element === null || !$element->isMounted()) {
            return;
        }

        $this->owner->markDirty();
    }
}
