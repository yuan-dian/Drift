<?php
declare(strict_types=1);

namespace Drift\State;

use Drift\Async\AsyncValue;
use Drift\Async\Future;

/**
 * **一次异步请求的完整生命周期**（2026-10-30）—— 一个广播 `AsyncValue` 的命令。
 *
 * ```php
 * // 页面（生命周期 = 页面）
 * $this->rows = $this->asyncCommand($this->fetchRows);   // initState：造 + 绑定
 * $this->rows->run($page);                               // 发起（翻页 / 重试）
 * $this->rows->value()                                   // build：读四态
 *
 * // Store（生命周期 = 应用，跨页共享）—— 同一套，只是不经 `State::asyncCommand()`
 * $this->page = AsyncCommand::make(fn(int $p): Future => $host->submit(new FetchUsersJob($p)));
 * ```
 *
 * ## ★★ 它是 `Subject`：宿主无关，因此**只有一套**
 *
 * 上一版构造收 `State`（只能标某个页面的脏）⇒ 跨页共享那条路用不上它，`apps/users`
 * 的 `UserStore` 于是把同一套状态机**手写第二遍**（`status` + `error` + `rows` +
 * `$inflight` + `load()` 六步 + `applyPage()`）。那是本轮要消灭的东西。
 *
 * 改成 `extends Subject` 之后，"值变了 ⇒ 通知谁"交给**订阅**（本仓既有的
 * `Subject` / `State::watch()` / `SubjectBuilder` / `Subscription`），
 * 命令自己不再认识任何 GUI 宿主：
 *
 * | 谁持有 | 怎么收到通知 | 生命周期谁收 |
 * |---|---|---|
 * | 页面 `State` | `State::asyncCommand()` 内部 `watch()` | 框架 `unmount()` 统一 `dispose()` |
 * | 跨页 `Store` | 直接 `subscribeFor()`，或经 `SubjectBuilder` 订阅**命令本身** | 应用自己 |
 *
 * ⇒ 成熟 GUI 的同一形状：Riverpod `AsyncNotifier`（页面内 `autoDispose` / 全局共享
 *   是**同一个类型**的两种寿命）、React Query `useQuery`（本地与缓存共用一份机制）。
 *   **不是**"页面一套、Store 一套"。
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
 * ## 刷新时保留上一次的值（2026-10-31）
 *
 * 每次 `run()` 都发 `AsyncValue::loading()`，但**带着上一次的载荷**
 * （`carriedFrom()`）⇒ 翻页 / 点刷新时列表不闪空，`fold()` 也因此在
 * "刷新中"走 `data` 支（见 `AsyncValue` 的类注释）。失败时同理：错误说得出来，
 * 旧内容也还在。
 *
 * ★ **`isRunning()` 的每一次变化都伴随一次值变化**（`start()` 里先置 `pending`
 *   再 `emit(loading)`，`land()` 里先清 `pending` 再 `emit(结果)`）⇒ 订阅了值的
 *   地方不需要给 `isRunning()` 单开一条通知。这条不变式是本类**故意**维持的：
 *   否则"按钮的禁用态"会需要第二条订阅通道，而两条通道迟早会不同步。
 *
 * ## 不做的事（诚实边界）
 *
 * - **~~超时~~** —— **2026-10-31 已落地，但落点不在这里**：`ProcessTaskHost`
 *   的 `$requestTerminateMs`（单条任务的**墙钟**上限，默认 30s，形态照抄 PHP-FPM
 *   的 `request_terminate_timeout`）。到点 ⇒ 那条 `Future` 被判失败 ⇒ 本类走到
 *   `error` 态 ⇒ 页面照常画"超时，可重试"。
 *   为什么**不在本类**做：客户端计时（`.NET` 的 `Task.WaitAsync(timeout)` 那种）
 *   只能让 `Future` 提前失败，**停不掉 worker** —— 进程还在跑、坑还占着，而且
 *   不报错。能真停的只有握着进程句柄的那一层。⇒ 本类因此**不需要**计时端口，
 *   `State → Platform` 那条边仍然没人走。
 * - **自动重试 N 次**：重试策略是业务决定（哪些错误值得重试），框架默认重试会把
 *   "幂等性假设"藏进基础设施。给 `retry()`，次数留给调用方。
 * - **缓存**：缓存键策略（`staleTime` / 失效）是 React Query 那一层的课题；
 *   本类只保证"最后一次 `run()` 的结果"。要缓存就在 `$task` 闭包里做。
 *
 * @api
 */
final class AsyncCommand extends Subject
{
    /**
     * @var \Closure(mixed): Future 组合根给的"怎么发起一次请求"
     *
     * ★ **统一收一个参数**（`run()` 总是传一个）：混合签名（"有时传、有时不传"）
     *   在解释器下写错不报、只在 AOT 崩 —— 本仓已经这样炸过一次
     *   （`AsyncValue::fold()` 的注释有完整案例）。
     */
    private \Closure $task;

    private AsyncValue $value;

    /** 在途的那条 Future（`null` = 没在跑） */
    private ?Future $pending = null;

    /** 最后一次的参数（`retry()` 用它重跑） */
    private mixed $arg = null;

    /** `run()` 过没有 —— 没跑过就无从重试 */
    private bool $ran = false;

    private bool $disposed = false;

    /**
     * ★★ **`$task` 每次都必须返回一条新的 `Future`，且它的根归本命令独有**
     *   （2026-10-31 补契约）：本类换参数时会 `cancel()` 上一次那条 —— 若根是
     *   共享 / 缓存的实例（"同页在途就复用上一条"这种优化很自然），取消会**误伤
     *   另一个持有者**，而症状是"别的地方莫名其妙不再更新"，极难归因。要复用就
     *   在 `$task` 里复用**结果**（自己缓存值），不要复用 `Future`。
     *
     * ★ **返回派生出来的那条是允许的**（`$host->submit(...)->then($decode)`）：
     *   取消会沿链回到根（见 `Future::cancel()` / `chain()`），根上的后端钩子照样
     *   被调到。上一版这里写的是"必须返回根"—— 因为当时 `then()` 的取消不回溯
     *   上游，派生 ⇒ 取消静默落空；那条限制随 2026-10-31 的修复一起消失。
     *
     * @param \Closure(mixed): Future $task
     *
     * @api
     */
    public static function make(\Closure $task): self
    {
        return new self($task);
    }

    /**
     * @param \Closure(mixed): Future $task
     */
    private function __construct(\Closure $task)
    {
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
     * ★★ **同步后端也会先过队列**（`SyncTaskHost` 的 `submit()` 只入队，结算发生在
     *   下一帧 `drain()`，见 §17.4.2 的统一语义）⇒ `run()` 之后那一帧仍是
     *   `loading`，**下一帧**才 `data`。真正"首帧就是 data"的只有 `$task` 直接返回
     *   **已结算** Future 的场合（基准 / 整页快照里的 `Future::resolved([...])`）。
     *   ★ 这条以前写成"`SyncTaskHost` 下首帧就是 data"—— 那是把同步后端与
     *     `Future::resolved` 混成一回事，实测不成立（首帧会白闪一格转圈）。
     *
     * ★★ **`$task` 允许同步抛出**（2026-10-31）：同步数据源的 `fetchPage()` 抛
     *   PDO / 网络异常、或 `run()` 传错参数类型的 `TypeError`，都会在**发起阶段**
     *   就炸。不接住的话异常会穿过 `run()` → 命令 handler → 事件循环
     *   （`Application::dispatch()` 那条链上没有 try/catch）⇒ 点一次按钮就走中止
     *   序列；而**同一次失败在异步后端下只会变成 `error` 态**。两条路必须同一出口。
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
     * 放弃在途请求，回到"没在跑"。
     *
     * ★ 有上一次的值 ⇒ 回到 `data`（那一帧的画面立刻恢复），没有 ⇒ `idle`。
     *   上一版无条件回 `idle`：取消一次刷新就把列表清空 —— 取消是"我不等了"，
     *   不是"把已经看到的东西也扔掉"。
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
     * ★ 页面侧**不需要**手写这一句：`State::asyncCommand()` 造的命令由框架在
     *   `unmount()` 时统一 `dispose()`（与 `dropSubscriptions()` 同一个理由 ——
     *   `dispose()` 可覆写，忘了 `parent::dispose()` 正是 A4 列的失败模式，
     *   兜底只能放框架侧）。
     *   Store 侧的长命命令（应用级）由应用自己在收摊时调。
     *
     * @api
     */
    public function dispose(): void
    {
        $this->abandon();
        $this->disposed = true;
    }

    /**
     * 作废旧请求：真的 `cancel()`。
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

    private function start(mixed $arg): void
    {
        $this->abandon();

        $this->arg = $arg;
        $this->ran = true;

        $task = $this->task;

        // ★★ 发起阶段的同步抛出 = "这次请求一开始就失败了"，必须与"任务跑失败"
        //   走**同一个出口**（`error` 态）。否则两条路分叉：异步后端显示"加载失败"，
        //   同步后端把异常扔进事件循环（`Application::dispatch()` 无 try/catch）——
        //   一次点按钮就能让进程走中止序列，且界面停在上一次的样子。
        //   ★ `abandon()` 已把 `pending` 置空 ⇒ `isRunning()` 是 false，用户能再点。
        //   ★ 不额外 `Errors::report()`：异步路径的 `Job::run()` 抛出也只变成
        //     `Completion` 的错误，不喊 Fatal。两条路要一致。
        try {
            $future = $task($arg);
        } catch (\Throwable $error) {
            $this->emit(AsyncValue::error($error->getMessage())->carriedFrom($this->value));

            return;
        }

        $this->pending = $future;

        // ★ 带着上一次的值进 `loading`（2026-10-31）：刷新 / 翻页时列表不闪空。
        //   首屏没有旧值 ⇒ `carriedFrom()` 原样返回，界面照旧画整块转圈。
        $this->emit(AsyncValue::loading()->carriedFrom($this->value));

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

    /**
     * 结算（能跑到这里的一定是**当前**那条 Future 的结果 —— 见 `abandon()`）。
     *
     * ★ 统一在这里 `carriedFrom()`：`data` 自带载荷 ⇒ 不改；`error` ⇒ 继承
     *   `loading` 时带过来的旧值。失败时"旧的还在、错误也说出来了"两件事同时成立。
     */
    private function land(AsyncValue $next): void
    {
        $this->pending = null;
        $this->emit($next->carriedFrom($this->value));
    }

    /**
     * 换值 + 广播（`Subject::notifyChanged()`）。
     *
     * ★ 宿主是谁、收到之后干什么（标脏 / 转发 / 重算派生值）**一概不在这里决定** ——
     *   那是订阅者的事。上一版在这里 `markDirty()`，于是命令被钉死在"只能服务
     *   某一个页面"，跨页共享那条路就只能把状态机再手写一遍。
     */
    private function emit(AsyncValue $next): void
    {
        $this->value = $next;
        $this->notifyChanged();
    }
}
