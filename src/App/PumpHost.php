<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\IoPoller;
use Drift\Async\Future;
use Drift\Errors;

/**
 * **异步泵片**：`frame()` 里 settle **之前**的三个泵 + 定时器。
 * 把「异步结果何时被消费」这个时序契约收进一个类。
 * 不变量：回调里的 `setState()` 由紧随的 settle 同帧消费。
 * @internal
 */
final class PumpHost
{
    /**
     * 后台结果交付每帧的时间预算（毫秒）。
     * 一帧 16.7ms，留 12ms 给布局 / 绘制 / 输入 ⇒ 异步最多吃掉四分之一帧。
     * 必须给（不能传 `null` 沿用无限）：`Future::all()` 聚合几百请求一次回来
     *   几百条回调，每条都改 Store ⇒ 一帧几十毫秒的偶发卡顿。
     */
    private const TASK_BUDGET_MS = 4;

    /**
     * 后台执行端口 —— `attach()` 时由**作用域**给一次并缓存。
     * ★ **必须缓存实例**：后端持有结果队列，每次现建一个会让"这一帧入队、
     *   下一帧 drain"落空（队列被换掉 ⇒ 结果永远等不到）。本端口是**有状态**的。
     * `null` = 本应用不用后台任务（作用域显式给了 `null`）⇒ 那段 drain 整个跳过。
     */
    private ?TaskHost $tasks = null;

    /**
     * 待触发的延时回调 —— `after()` 排进来，`pumpTimers()` 消费。
     * 有定时器待触发期间帧时钟保持上膛（每帧空转一次，直到到期），可接受。
     * ★ `due` 的基准是 `max(上一帧, 上一次输入)`（见 `after()` 与 host 的
     *   `lastInputMs()`）：只用"上一帧"时，`after()` 在帧外回调里被调会取几百毫秒前的
     *   时间戳 ⇒ `due` 落在过去 ⇒ **定时器当场触发**（如远程搜索"正在加载"一帧都没出现）。
     * @var array<int, array{due: float, cb: \Closure}>
     */
    private array $timers = [];

    /** 下一个定时器 id（进程内自增，`cancelAfter()` 用它） */
    private int $nextTimerId = 1;

    /** 非阻塞 I/O 轮询器 —— `null` = 未挂载 */
    private ?IoPoller $io = null;

    public function __construct(private PumpHostHost $host)
    {
    }

    /**
     * 挂载时**取走**端口与轮询器的引用（本片只持引用，不拥有）。
     * `attach()` 不建也不换：`tasks` 持结果队列、`io` 持监听表，中途换掉
     *   就是"入队 / drain 分属两个队列"那类静默 bug 的入口。
     */
    public function attach(?TaskHost $tasks, ?IoPoller $io): void
    {
        $this->tasks = $tasks;
        $this->io = $io;
    }

    /**
     * 排一个**一次性延时回调**。
     *
     * **交付时刻**（三个边界都说清，别只写"早一帧"）：
     * - 基准 = `max(上一帧时间戳, 上一次输入时刻)`，**不是**环境时钟 ⇒ 在帧外调用时
     *   基准最多早一帧，于是"现在 + N"可能比请求的 N 早（≤一帧）。
     * - 到期**就唤醒消息泵**，不必等到帧边界对齐 —— `after(3)` 等 3ms，不是等 16.667ms
     *   （实测误差 = 常数唤醒开销 ~1–2ms，不随 N 放大；判据见 `AfterSchedulerTest`）。
     * - 回调在 `frame()` 内、`settle()` 之前跑 ⇒ 回调里 `setState()` 同帧生效。
     *
     * 有定时器在等 ⇒ `wantsFrame()` 为真 ⇒ 帧时钟保持上膛（消息泵阻塞，没人叫醒就永远不响）。
     * `after(0, $cb)` 即"帧边界执行"；它替代不了"必须在两帧之间跑"的需求
     *   （如 `WindowSet::open()` 那种会重入的）。
     * ★ **不要拿它做动画计时**：动画走帧时钟，那里有按绝对时刻排程的 `nextFrameAt`。
     * @param int $milliseconds 延时（毫秒；负数按 0 处理 ⇒ 下一帧就触发）
     * @return int 定时器 id（`cancelAfter()` 用）
     * @api
     */
    public function after(int $milliseconds, \Closure $callback): int
    {
        $id = $this->nextTimerId++;
        $this->timers[$id] = [
            // 基准取"上一帧"与"上一次输入"的**较大者**（见 `PumpHostHost::lastInputMs()`）：
            //   输入回调里排的定时器必须以**输入那一刻**起算，否则空闲应用上的延时变"立刻"。
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

    /**
     * 最早的那个**到点时刻**（绝对毫秒，与帧时间戳同一时基）；`null` = 没有定时器。
     * 存在的理由：让等待层能回答"**等多久**"—— 否则 `after(400)` 也要按帧节奏
     *   反复空唤醒（一个 400ms 停留 = 26 次空唤醒）。
     * 只算 `after()`：后台任务与 socket 可读没有到点时刻（它们是轮询，见 `hasPolledAsync()`）。
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

    /**
     * 在途的活里，**只能靠轮询**的那部分（⇒ 帧节奏必须跳，不能无限等）。
     * 分界是"后端能不能叫醒我"：`IoPoller` 拿不到 fd 去等 ⇒ 只能每帧问；
     *   后台任务看 {@see TaskHost::needsPolling()}（`ProcessTaskHost` 拉、
     *   `ThreadTaskHost` `PostMessageW` 推 ⇒ 后者在途时帧时钟给 `-1`）。
     * 它回答"**要不要空转着等**"，与"有没有活"是两件事：混成一件会让能推的后端
     *   也被按帧节奏空转。
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
     * **先摘再调**：回调里可以安全地 `after()` 新定时器 / `cancelAfter()` 别的
     *   —— 否则遍历中改 `$this->timers` 会漏掉或重复触发。
     * 回调抛异常**不吞**，走与另两个泵同一个出口（{@see self::guarded()}）⇒ Fatal 序列。
     */
    public function pumpTimers(float $timestampMs): void
    {
        if ($this->timers === []) {
            return;
        }

        // 先把到期的挑出来（不在遍历里改数组）
        $due = [];
        foreach ($this->timers as $id => $timer) {
            if ($timer['due'] <= $timestampMs) {
                $due[$id] = $timer['cb'];
            }
        }

        $this->guarded('定时器回调未捕获异常', function () use ($due): void {
            foreach ($due as $id => $callback) {
                // 先摘再调：回调里可以安全地 `after()` / `cancelAfter()` 别的
                unset($this->timers[$id]);
                $callback();
            }
        });
    }

    /**
     * 三个泵的**唯一异常出口**：跑"会调用户回调"的活，异常不吞、不让裸栈冒到组合根
     *   （`Errors::report()` ⇒ Fatal 序列）。
     * ★ 不许改成"吞掉 + 记一条 Diagnostic"：那是半静默
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
     * ```php
     * $sock = stream_socket_client("tcp://127.0.0.1:9000", $errno, $errstr, 0, STREAM_ASYNC_CONNECT);
     * // 必须：poll 只保证"问一下就走"
     * stream_set_blocking($sock, false);
     * $app->io()->watch($sock, true, function ($s) use ($app): void { ... });
     * @api
     */
    public function io(): ?IoPoller
    {
        return $this->io;
    }

    /**
     * 取后台结果并分派。
     * 队列只能由 UI 线程消费 ⇒ 断言在这里。
     * 整体包一层 try/catch：用户回调抛异常**不许**穿过帧循环的剩余阶段；
     *   策略与 `Frame::run()` 的 settle 段一致 ⇒ Fatal 序列 —— **不吞**。
     */
    public function pumpTasks(): void
    {
        $tasks = $this->tasks;
        if ($tasks === null) {
            return;
        }

        $this->host->assertUiThread('drain');

        $this->guarded('后台结果回调未捕获异常', function () use ($tasks): void {
            // 传预算（传 `null` = 一次取空）。超出的留在后端，下一帧继续 ——
            //   `pending()` 仍 > 0 是这件事成立的前提，否则帧时钟判"没活了"就睡死。
            $tasks->drain(
                function (Completion $completion): void {
                    $this->complete($completion);
                },
                self::TASK_BUDGET_MS,
            );
        });
    }

    /**
     * 问一轮非阻塞 I/O —— 每帧一次，在 `settle()` 之前。
     * 与 `pumpTasks()` 同策略：回调异常不吞（⇒ Fatal 序列），
     *   但用 try/catch 把它变成"指明来源的错误"，而非裸栈冒到组合根。
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
     * **没挂 `catch()` ⇒ Fatal 序列**（`Future::reject()` 自己判）：任务失败被
     *   静默吞掉就是"界面永远转圈"，那是禁止的半静默。
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
