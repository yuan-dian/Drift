<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\IoPoller;
use Drift\Async\Future;
use Drift\Errors;

/**
 * **异步泵片**（S1 第 9 步）：`frame()` 里 settle **之前**的三个泵 + 定时器。
 * 把「异步结果何时被消费」这个时序契约收进一个类。
 *
 * ```text
 * frame() 的 A 组（三个泵，必须都在 settle 之前）：
 *   pumpIo      → 问一轮非阻塞 IO（外部数据先到）
 *   pumpTasks   → 取后台结果（TaskHost::drain）
 *   pumpTimers  → 触发到期的延时回调（after() 排的）
 * 它们共同满足同一条不变量：**回调里的 setState() 由紧随的 settle 同帧消费**。
 * ```
 *
 * ★ **原来是四个**（2026-10-31 删掉 `pumpSliced`）：`SlicedJob` 那条链的入口只在
 *   `Application::submitSliced()` 上，而页面（`State`）在架构上**拿不到
 *   `Application`**（本仓没有 `InheritedWidget`，依赖一律构造注入）⇒ 除了冒烟
 *   没有任何消费者。裁决与替代方案见 `docs/ASYNC-ARCH-2026-10-31.md` §12。
 *
 * ★ **为什么单独拆这三个泵**（不是整个 frame 九段）：它们只依赖自持的
 *   `tasks` / `io` / `timers` 三个状态 + 宿主的 UI 线程断言与
 *   时间基准，**不读 root / focus / overlays**（真正零耦合）。而帧末的
 *   语义 / 动画 / 焦点要读宿主六个状态 ⇒ 耦合面大，留壳里
 *   （见 arch-v2 `12` §4i 的三簇拆法与「为什么不是九段表驱动」）。
 * ★ 与前 5/6/8 步同构：`ScrollInputRouter ← InputRouter ← App 壳`、
 *   `OverlayLayout ← OverlayManager ← App 壳`，这里是 `PumpHost ← App 壳`
 *   —— 被依赖多的晚拆，「拆一层、上游改指向」口径一致。
 *
 * @internal S1 第 9 步从 Application 拆出（public 方法的委托壳留在壳上）
 */
final class PumpHost
{
    /**
     * 后台结果交付每帧的时间预算（毫秒，2026-10-30 P1）。
     *
     * ★ 4 的来历：一帧 16.7ms，留 12ms 给布局 / 绘制 / 输入（§13.3 全树重排）
     *   ⇒ 异步最多吃掉四分之一帧。
     *
     * ★ 为什么由**这里**给而不是让后端用自己的默认值：预算是帧的属性，不是
     *   后端的属性 —— 后端只回答"给我多少我就干多少"。把数字留在帧循环里，
     *   将来要改成"整帧共享预算"（Flutter 的 frame budget）时只动这一处。
     *
     * ★ 为什么必须给（不能传 `null` 沿用无限）：`Future::all()` 聚合几百个请求时
     *   一次回来几百条回调，每条都改 Store ⇒ 一帧几十毫秒。那是**偶发卡顿**，
     *   且掉帧的那一帧和提交点隔着几百条回调，几乎不可能归因（见
     *   `docs/UI-THREAD-MODEL-2026-10-30.md` §4.1）。
     */
    private const TASK_BUDGET_MS = 4;

    /**
     * 后台执行端口（§17.2）—— `attach()` 时由**作用域**给一次并缓存。
     *
     * ★ **必须缓存实例**：后端持有结果队列，每次现建一个会让"这一帧入队、
     *   下一帧 drain"落空（队列被换掉 ⇒ 结果永远等不到）。
     *   ⇒ 与 `accelerators()`（每次现建也行）不同，本端口是**有状态**的。
     *
     * ★ `null` = 本应用不用后台任务（作用域里显式给了 `null`）⇒ 那段
     *   drain 整个跳过，零开销。
     */
    private ?TaskHost $tasks = null;

    /**
     * 待触发的延时回调（W8-2，2026-10-23）—— `after()` 排进来，`pumpTimers()` 消费。
     *
     * ★ **为什么不做成"平台多开几个 `TimerHost` id"**：`TimerHost` 是**周期**定时器
     *   （`SetTimer` 会一直响），而这里要的是**一次性**延时；再者在平台层做 id 分配
     *   就要在 `pumpEvents()` 里把 `timer` 事件按 id 分发回框架 —— 一圈下来只换来
     *   15.6ms → 0ms 的精度提升，而 Tooltip / Snackbar 的 500ms 停留对 15ms 无感。
     *   ★ 代价必须说清楚：**有定时器待触发期间帧时钟保持上膛**（每 15ms 一帧空转，
     *   直到到期）。这是 `wantsFrame()` 必须加那条判据的另一面 —— 不加就永远
     *   不响，加了就有这段空转。Tooltip 停留期最多 500ms（约 33 帧），可接受。
     *
     * ★★ `due` 的基准是 `max(上一帧, 上一次输入)` —— 见 `after()` 与 host 的
     *   `lastInputMs()`。2026-10-24 之前只用"上一帧"：`after()` 常被**帧外**的
     *   回调调用（指针点击 → `onOpen`），而空闲应用的"上一帧"可能是几百毫秒前
     *   ⇒ `due` 落在过去 ⇒ **定时器当场触发**。现象（真机实测）：远程搜索的
     *   "正在加载…"一帧都没出现过。
     *
     * @var array<int, array{due: float, cb: \Closure}>
     */
    private array $timers = [];

    /** 下一个定时器 id（进程内自增，`cancelAfter()` 用它） */
    private int $nextTimerId = 1;

    /** 非阻塞 I/O 轮询器（§17.4.4，P2）—— `null` = 未挂载 */
    private ?IoPoller $io = null;

    public function __construct(private PumpHostHost $host)
    {
    }

    /**
     * 挂载时**取走**端口与轮询器的引用（M2：它们归 `AppScope`，本片只持引用）。
     *
     * ★ 为什么参数是"取"而不是"建"：多窗口下这两个必须是**同一个实例**
     *   （一个池、一张监听表）。谁建谁就是这个作用域的主人 —— 那件事归装配处
     *   （`AppScope`），不该由"第几个窗口先挂载"决定。
     * ★ `attach()` 不建也不换：`tasks` 持结果队列、`io` 持监听表，中途换掉
     *   就是"入队 / drain 分属两个队列"那类静默 bug 的入口。
     */
    public function attach(?TaskHost $tasks, ?IoPoller $io): void
    {
        $this->tasks = $tasks;
        $this->io = $io;
    }

    /**
     * 排一个**一次性延时回调**（W8-2，2026-10-23）。
     *
     * ```php
     * $id = $this->application()->after(500, function (): void { $this->setState(...); });
     * // 想撤销就 cancelAfter($id)（已触发过 ⇒ 幂等无事发生）
     * ```
     *
     * ★ **时间基准是"上一帧的时间戳"**，不是环境时钟（G3：框架不自己读钟）。
     *   在 `frame()` **之外**调用（例如指针回调里）时，基准会早一个帧间隔
     *   （≤ `FrameClock::FRAME_MS` = 15ms）—— 对 Tooltip / Snackbar 这种"人的
     *   反应时间"尺度无感，但**不要拿它做动画计时**（那是 `AnimationController`
     *   的活，它收的是精确帧时间戳）。
     * ★ **回调在 `frame()` 内、`settle()` 之前跑**（`pumpTimers()` 的位置与
     *   `pumpTasks()` 同级）⇒ 回调里 `setState()` 同帧生效。
     * ★ **有定时器在等 ⇒ `wantsFrame()` 为真** ⇒ 帧时钟保持上膛。这是"定时器
     *   能不能响"的**结构前提**：消息泵是阻塞的，没人叫醒就永远不响。
     * ★★ **`after(0, $cb)` 就是"帧边界执行"**（2026-10-30）：设计册 §3.6 曾计划一个
     *   `Inbox` 类来做"把这件事挪到帧边界"，但那个位置这里已经有了 —— 回调在
     *   `frame()` 内、`settle()` **之前**跑 ⇒ 里面 `setState()` 同帧生效。
     *   ⇒ 别再造第二个入口（§11 ③ 的裁决）。★ 它**不能**替代的是"必须在两帧
     *   **之间**跑"的需求（`WindowSet::open()` 那种会重入的），那个位置在
     *   `EventLoop` 里，不在本类里。
     *
     * @param int $milliseconds 延时（毫秒；负数按 0 处理 ⇒ 下一帧就触发）
     * @return int 定时器 id（`cancelAfter()` 用）
     *
     * @api
     */
    public function after(int $milliseconds, \Closure $callback): int
    {
        $id = $this->nextTimerId++;
        $this->timers[$id] = [
            // ★ 基准取"上一帧"与"上一次输入"的**较大者**（见 `PumpHostHost::lastInputMs()`）：
            //   在输入回调里排的定时器必须以**输入那一刻**起算 —— 用"上一帧"会让
            //   空闲应用上的 400ms 变成"立刻"（真机实测：加载态一帧都没出现）。
            'due' => \max($this->host->lastTimestampMs(), $this->host->lastInputMs())
                + (float) ($milliseconds < 0 ? 0 : $milliseconds),
            'cb' => $callback,
        ];

        return $id;
    }

    /** 撤销一个延时回调（已触发 / id 不存在 ⇒ 无事发生，幂等） */
    public function cancelAfter(int $id): void
    {
        unset($this->timers[$id]);
    }

    /** 已挂载的后台端口（`null` = 未挂载或应用声明不用）—— Application::tasks() 壳的落点 */
    public function tasks(): ?TaskHost
    {
        return $this->tasks;
    }

    /** 定时器是否还在等（`wantsFrame()` 的一条判据） */
    public function hasTimers(): bool
    {
        return $this->timers !== [];
    }

    /**
     * 最早的那个**到点时刻**（绝对毫秒，与帧时间戳同一时基）；`null` = 没有定时器。
     *
     * ★ 它存在的理由是"**等多久**"这个问题有了答案：改动之前，框架只能回答
     *   "要不要再跑一帧"，于是 `after(400)` 也要按 15ms 帧节奏反复醒 ——
     *   一个 400ms 的 Tooltip 停留 = **26 次空唤醒**。有了这个数字，等待层可以直接
     *   `waitEvents(400)`：一觉睡到点。
     * ★ 只算 `after()`：后台任务与 socket 可读**没有到点时刻**（它们是轮询，
     *   见 `hasAsync()` 的注释与 `EVENT-LOOP-DESIGN` §3.5 的诚实边界）。
     */
    public function nextDeadlineMs(): ?float
    {
        $earliest = null;
        foreach ($this->timers as $timer) {
            if ($earliest === null || $timer['due'] < $earliest) {
                $earliest = $timer['due'];
            }
        }

        return $earliest;
    }

    /** 后台任务是否在途 / 监听是否开着（`wantsFrame()` 的两条判据） */
    public function hasAsync(): bool
    {
        return ($this->io !== null && $this->io->watchCount() > 0)
            || ($this->tasks !== null && $this->tasks->pending() > 0);
    }

    /**
     * 其中**只能靠轮询**的那部分（⇒ 帧节奏必须跳，不能无限等）。
     *
     * ★★ 与 `hasAsync()` 的差别就是"后端能不能叫醒我"（2026-10-30，N5）：
     *   - `IoPoller` 的 watcher：本仓拿不到 SOCKET 去等 fd ⇒ 只能每帧问；
     *   - 后台任务：看后端自己声明的 {@see TaskHost::needsPolling()} ——
     *     `ProcessTaskHost` 读管道（只能拉），`ThreadTaskHost` 会 `PostMessageW`
     *     （能推）⇒ 后者在途时帧时钟给 `-1`，一觉睡到 worker 叫醒为止。
     *
     * ★ 为什么要有这个"第二谓词"而不是改 `hasAsync()`：`hasAsync()` 回答的是
     *   "**有没有活**"（`wantsFrame()` 用它），这里回答的是"**要不要空转着等**"。
     *   混成一个就是"能叫醒的后端也被按 15ms 空转"—— 那正是 N5 要消掉的东西。
     */
    public function hasPolledAsync(): bool
    {
        if ($this->io !== null && $this->io->watchCount() > 0) {
            return true;
        }

        return $this->tasks !== null
            && $this->tasks->pending() > 0
            && $this->tasks->needsPolling();
    }

    /**
     * 推进到期的延时回调（每帧一次，在 `settle()` 之前）。
     *
     * ★ **先摘再调**：回调里可以安全地 `after()` 新定时器 / `cancelAfter()` 别的
     *   —— 否则遍历中改 `$this->timers` 会漏掉或重复触发。
     * ★ 回调抛异常**不吞**，且与另外两个泵走**同一个出口**（{@see self::guarded()}）：
     *   S3 无边界 ⇒ Fatal 序列。
     *   ★★ 2026-10-31 之前这里**一处 try/catch 都没有**，而注释写着"与
     *   `pumpTasks()` 同策略" —— 那句话是假的：`pumpTasks` 会把异常转成一条
     *   带来源的 Fatal，这里让它带着裸栈穿过 `frame()` 冒到事件循环。同一个
     *   失败，一个说得出"哪个泵"，一个说不出。
     */
    public function pumpTimers(float $timestampMs): void
    {
        if ($this->timers === []) {
            return;
        }

        // ★ 先把到期的挑出来（不在遍历里改数组）
        $due = [];
        foreach ($this->timers as $id => $timer) {
            if ($timer['due'] <= $timestampMs) {
                $due[$id] = $timer['cb'];
            }
        }

        $this->guarded('定时器回调未捕获异常', function () use ($due): void {
            foreach ($due as $id => $callback) {
                // ★ 先摘再调：回调里可以安全地 `after()` / `cancelAfter()` 别的
                unset($this->timers[$id]);
                $callback();
            }
        });
    }

    /**
     * 三个泵的**唯一异常出口**：跑一段"会调用户回调"的活，异常不吞、但也不让
     * 它带着裸栈冒到组合根（`Errors::report()` ⇒ Fatal 序列，S3 无边界）。
     *
     * ★ 为什么要收（2026-10-31）：`pumpTasks` / `pumpIo` 各写了一遍 try/catch +
     *   **同一句格式化**（只差中段那几个字），而 `pumpTimers` 一处都没写 ——
     *   三条泵、两种行为。收成一处之后，"异常走哪个出口"不再取决于谁记得写。
     *
     * ★ 不许改成"吞掉 + 记一条 Diagnostic"：那正是 G2 明令禁止的半静默
     *   （界面停在上一次的样子，且没人知道）。这里只是把**同一条 Fatal**
     *   补上一句"是哪个泵抛的"。
     */
    private function guarded(string $what, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Errors::report(sprintf(
                '帧循环：%s —— %s（%s:%d）',
                $what,
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
        }
    }

    /**
     * 非阻塞 I/O 轮询器（`null` = 未挂载）。
     *
     * ```php
     * $sock = stream_socket_client("tcp://127.0.0.1:9000", $errno, $errstr, 0, STREAM_ASYNC_CONNECT);
     * stream_set_blocking($sock, false);            // ★ 必须：poll 只保证"问一下就走"
     * $app->io()->watch($sock, true, function ($s) use ($app): void { ... });
     * ```
     *
     * @api
     */
    public function io(): ?IoPoller
    {
        return $this->io;
    }

    /**
     * 取后台结果并分派（§17.4.2 的第一步）。
     *
     * ★ 队列只能由 UI 线程消费 ⇒ 断言在这里（§17.4.2.1 的第二条断言位）。
     * ★ 整体包一层 try/catch：用户回调抛异常**不许**穿过帧循环的剩余阶段。
     *   处理策略与 `Frame::run()` 的 settle 段一致（S3：无边界 ⇒ Fatal 序列）——
     *   **不吞**，吞掉就是"界面停在上一次的样子且不报错"（G2 明令禁止）。
     */
    public function pumpTasks(): void
    {
        $tasks = $this->tasks;
        if ($tasks === null) {
            return;
        }

        $this->host->assertUiThread('drain');

        $this->guarded('后台结果回调未捕获异常', function () use ($tasks): void {
            // ★ 传预算（`null` = 一次取空）。超出的留在后端，下一帧继续 ——
            //   `pending()` 仍 > 0 是这件事成立的前提（三个后端各自的实现契约
            //   见 {@see TaskHost::drain()}），否则帧时钟判"没活了"就睡死。
            $tasks->drain(
                function (Completion $completion): void {
                    $this->complete($completion);
                },
                self::TASK_BUDGET_MS,
            );
        });
    }

    /**
     * 问一轮非阻塞 I/O（§17.4.4）—— 每帧一次，在 `settle()` 之前。
     *
     * ★ 与 `pumpTasks()` 同策略：回调异常不吞（S3 ⇒ Fatal 序列），
     *   但要用 try/catch 把它变成"指明来源的错误"，而不是裸栈冒到组合根。
     */
    public function pumpIo(): void
    {
        $io = $this->io;
        if ($io === null) {
            return;
        }

        $this->host->assertUiThread('poll');

        $this->guarded('IO 就绪回调未捕获异常', static function () use ($io): void {
            $io->poll();
        });
    }

    /**
     * 结算一条 `Completion`（成功 → `resolve()`，失败 → `reject()`）。
     *
     * ★ **没挂 `catch()` ⇒ Fatal 序列**（`Future::reject()` 自己判）：任务失败被
     *   静默吞掉就是"界面永远转圈"，与三态的 `Error` 分支（§A.2.9）矛盾 ——
     *   那是 G2 禁止的半静默。判据从"这里查 `$onError` 是否为 null"搬到
     *   "`Future` 结算时有没有 err 处理器"，**语义一模一样，位置只有一处**。
     */
    private function complete(Completion $completion): void
    {
        $error = $completion->error;
        if ($error !== null) {
            $completion->future->reject($error);

            return;
        }

        $completion->future->resolve($completion->value);
    }
}
