<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;
use Drift\Errors;

/**
 * `TaskHost` 后端 —— **常驻子进程池**（§17.4.5，P3）：真后台、真隔离、跑的是 AOT 代码。
 *
 * ```
 *  UI 进程                                worker 进程（同一份 AOT 产物）
 *  ┌──────────────────┐   {"id":1,...}    ┌────────────────────────┐
 *  │ submit(NamedJob) │ ───────────────►  │ fgets 一行 → 注册表重建 │
 *  │ drain() select(0)│ ◄───────────────  │ → run() → 回一行 JSON  │
 *  └──────────────────┘   {"id":1,ok:…}   └────────────────────────┘
 * ```
 *
 * ★ **为什么是进程而不是线程**（§17.2.5–17.2.6 实测的结论）：
 *   - 线程跑 AOT 代码**当前不可行**（worker 线程缺 Zend 上下文，裸线程 / Swoole + FFI 两枪
 *     都 SIGSEGV；等上游 `php::thread_init()`）；
 *   - 进程**天然隔离**：worker 崩了只丢一个任务，UI 不受影响（线程崩 = 整个进程崩）；
 *   - 进程**跑的是同一份 AOT 代码** —— 不像 Swoole 线程那样只能 `require` 源码解释执行
 *     （慢 55–95×，§12 探针 L）。
 *
 * ★ **不是"每任务一进程"**：实测 `spawn` 单价 **275ms**（`probes/RESULTS.md` §12 ⑧），
 *   按任务起进程根本不可用。所以是**懒启动 + 常驻**：需要时才起，之后所有任务复用它；
 *   `drain()` 每帧非阻塞看一眼有没有回复。
 *   ★ 但那 275ms 会落在**第一条任务**头上（"点了没反应"）⇒ 组合根可以调
 *     {@see self::prewarm()} 把它挪到应用启动期（2026-10-31）。
 *
 * ★★ **并发 = 多个 worker 进程，不是 worker 内多线程**（`maxWorkers`）：
 *   单个 worker 是**串行**的（`fgets` 一行、跑完、回一行 —— 它没有线程，见上）；
 *   要并行只能**多起几个进程**。所以这里是一个池：
 *   - `maxWorkers = 1`（默认）⇒ 行为与第一版**逐字节一致**（串行、满载排队）；
 *   - `maxWorkers = N` ⇒ 空闲的 worker 各接一条，**满载排队**，不会无界起进程
 *     （一个 worker 是一整套 AOT 运行时，不是线程 —— 无界起 = 内存爆炸）。
 *   ⇒ 这是**能力**（可以并行），默认值保守是因为"开并发"是应用的决定：几个 worker、
 *   在什么机器上，只有应用知道（`apps/users` 今天仍是默认 1）。
 *
 * ★ **诚实边界**：单个 worker 内部串行；`stdin` 用阻塞写（每条 worker 在途 ≤ 1 条
 *   ⇒ 管道不会写满）；协议是 JSON Lines（结构化数据合适，字节流不合适）。
 *
 * @api
 */
final class ProcessTaskHost implements TaskHost
{
    /** 一次最多读多少字节（8K ≈ 几十条回复，够一帧消化） */
    private const READ_CHUNK = 8192;

    /**
     * 在跑的 worker 池。
     *
     * ★ **索引自增、不重用**：重用会让"错误归属"错乱 —— 老 worker 的迟到回复会被
     *   算到新 worker 头上（同一个索引既是死者又是继任者）。`$workerSeq` 只增不减。
     *
     * @var array<int, array{proc: mixed, in: mixed, out: mixed, err: mixed, buffer: string, stderr: string}>
     */
    private array $workers = [];

    /**
     * 已发出、等回复的任务（`worker` = 记在哪个 worker 名下，用于挑空闲）。
     *
     * @var array<int, array{future: Future, worker: int}>
     */
    private array $inflight = [];

    /**
     * 还没发出去的任务（没有空闲 worker / worker 没起好）。
     *
     * ★ `name` 不是为了发送（`line` 里已经有了），是为了**超时 / 诊断**：排队中的
     *   任务将来要做"排太久"告警时，只有 `line`（一整行 JSON）就得再解一次。
     *
     * @var list<array{id: int, name: string, line: string, future: Future}>
     */
    private array $queue = [];

    /**
     * **当场就失败**的任务（不是 `NamedJob` / 名字没注册 / 参数转不了 JSON）。
     *
     * ★ 它们不进 `$queue`（那里是"等着发出去"，这些永远发不出去），也不在这里
     *   直接 `reject()`：`submit()` 返回之后调用方才挂 `catch()`，当场结算会
     *   让"刚提交就 Fatal"。⇒ 入这个队列，由 `drain()` 在**帧内**结算 ——
     *   与真任务失败走同一条时序，调用方看不出差别。
     * ★ 交付算法（至少一条 / 剩余回队头 / 回调抛异常也不丢）在
     *   {@see CompletionQueue} 里，三个后端共用那一份（2026-10-31）。
     */
    private CompletionQueue $failed;

    private int $seq = 0;

    /** 上次 `reap()` 的时刻（µs，`hrtime` 单调）—— 空闲期降频用 */
    private int $lastReapUs = 0;

    /** 空闲期 `reap` 的最小间隔（500ms ≈ 33 帧） */
    private const REAP_IDLE_INTERVAL_US = 500_000;

    /** worker 索引发号器（只增不减，见 `$workers` 的注释） */
    private int $workerSeq = 0;

    /** 池上限（1 = 串行，与第一版一致） */
    private readonly int $maxWorkers;

    /**
     * ★★ **单条任务的墙钟上限**（毫秒）—— 形态照抄 PHP-FPM 的
     * `request_terminate_timeout`（进程池 + 墙钟 + 杀 worker + 补一个）。
     *
     * ## 为什么是"墙钟"而不是"CPU 钟"
     *
     * FPM 的文档把这件事讲得很透：PHP 的 `max_execution_time` 只对**CPU 时间**
     * 计时（Linux 上是 `setitimer(ITIMER_PROF)`），于是 `sleep()`、慢查询、
     * 卡住的 curl 一律不计 —— 一条"等数据库等到死"的请求可以永远烧着 worker。
     * `request_terminate_timeout` 数的是**从派发给 worker 那一刻起的墙钟**，
     * 所以它是唯一能兜住"卡住"的那一层。本类的任务同样可能卡在 IO 上，
     * ⇒ 这里也只能是墙钟（`hrtime`）。
     *
     * ## 为什么默认**开**（30s）而不是默认关
     *
     * FPM 的默认值是 `0`（关），运维界把它叫作 *the dangerous default*：一条
     * 死锁 / 死循环的请求会永久占住一个 worker，且**没有任何报错** —— 池看起来
     * 还在跑（活跃数正常），实际吞吐在悄悄掉。
     * 本仓同一形状更糟：worker 卡住 ⇒ `Future` 永不结算 ⇒ `AsyncCommand`
     * 永远 `isRunning()` ⇒ 翻页 / 重试按钮**全部禁用** ⇒ 用户**没有任何出路**，
     * 只能杀掉整个程序。⇒ 默认值必须是一个正数，把"永久转圈"这个洞堵上。
     *
     * ## 计时从**派发**开始，不从提交开始
     *
     * 与 FPM 一致（"从 master 把请求交给 worker 那一刻起算"）：排队等空闲
     * worker 的那段时间不是"卡住"，是正常等待。排队会饿死吗 —— 不会，卡住的
     * worker 会被杀、池会补新的，队列因此一定会往前走。
     *
     * @api
     */
    private readonly int $requestTerminateMs;

    /**
     * @param list<string> $command worker 的启动命令（如 `[$exe, '--drift-worker']`；
     *   测试里是 `[PHP_BINARY, 'worker.php']`）
     * @param array<string, callable(array<string, mixed>): Job> $registry
     *        名字 → 重建工厂。★ 必须与 worker 侧 `ProcessWorker::serve()` 的注册表**一致**：
     *        UI 侧用它做"名字打错就早失败"，worker 侧用它真正重建任务。
     * @param int $maxWorkers 池上限（默认 1 = 串行）。>1 才可能真并行 —— 满载排队，
     *        不会无界起进程。
     * @param int $requestTerminateMs 单条任务的墙钟上限（毫秒，`0` = 不设上限）。
     *        默认 30_000 —— 见 {@see self::$requestTerminateMs}：默认关等于保留
     *        "一条卡死的任务让用户永远没有出路"这个洞。
     *
     * @api
     */
    public function __construct(
        private readonly array $command,
        array $registry,
        int $maxWorkers = 1,
        int $requestTerminateMs = 30_000,
    ) {
        if ($maxWorkers < 1) {
            Errors::report('ProcessTaskHost：maxWorkers 必须 ≥ 1（给的是 ' . $maxWorkers . '）');
            $maxWorkers = 1;
        }
        $this->maxWorkers = $maxWorkers;
        $this->failed = new CompletionQueue();

        // ★ 负值按"不设上限"处理：它不是"写错了"，就是"这个池不要这道闸"。
        //   显式给 `0` 与给负数在本类里是同一个意思，不必再喊一次诊断。
        $this->requestTerminateMs = $requestTerminateMs > 0 ? $requestTerminateMs : 0;

        foreach ($registry as $name => $factory) {
            $this->registry[(string) $name] = \Closure::fromCallable($factory);
        }
    }

    /** @var array<string, \Closure> */
    private array $registry = [];

    /**
     * **预热**：先把 worker 起好，让第一条任务不用等进程启动（2026-10-31）。
     *
     * ```php
     * $host = new ProcessTaskHost([$exe, '--drift-worker'], JobRegistry::make());
     * $host->prewarm();          // 组合根：建窗之前/之后都行，越早越好
     * $scope->setTaskHost($host);
     * ```
     *
     * ## 它修的是哪一段等待
     *
     * 本类注释写着"懒启动 + 常驻：需要时才起，之后所有任务复用它"—— 但**第一次**
     * 总要付那 275ms（`proc_open` + 一整套 AOT 运行时启动）。于是"进页面就取数"
     * 的路径上，用户看到的是：转圈出现 → **275ms 什么都没有** → 任务才开始跑。
     * 那 275ms 不是任务慢，是**执行体还没出生**。
     *
     * 预热把这 275ms 挪到应用启动期 —— 那一小段本来就没人看（窗口还在建、
     * 首帧还没画），而它是**另一个进程**在启动，不占 UI 线程。
     *
     * ## 为什么不在端口（`TaskHost`）上
     *
     * `TaskHost` 说的是"怎么提交一个任务"；预热说的是"**这个池**的启动成本"。
     * 三个后端里只有本类有 275ms 的启动账（`SyncTaskHost` 零启动、
     * `ThreadTaskHost` 的线程在 C++ 侧按需起且是微秒级）。把它放进接口，
     * 另外两个就只能实现成空方法 —— 那是"未被消费的分支"（本仓纪律）。
     * ⇒ 留在具体类上，组合根本来就握着具体类型（它刚 `new` 完）。
     *
     * ## 它**不**等 worker 就绪
     *
     * 等就绪 = 在 UI 线程上阻塞 275ms，正好是这整个机制在躲的事。
     * 所以这里只把进程**发起**（`proc_open` 是异步的，立即返回），真正"它能干活"
     * 由第一次 `flush()` 派发时自然衔接：那时它多半已经起来了；万一还没完全就绪，
     * 任务就多等一会儿（与没有预热时的起点相同，不会更差）。
     * ★ 诚实边界：所以预热**不保证**首条任务 0 等待，只把期望值从 275ms 压到接近 0。
     *
     * ## 一次起几个
     *
     * `$n` 会被 `maxWorkers` 夹住 —— 一个 worker 是一整套 AOT 运行时（几十 MB），
     * 预热不能成为"无界起进程"的入口（同 `flush()` 的理由）。
     * 幂等：已经够数就直接返回。
     *
     * @param int $n 想准备几个 worker（`≤ 0` ⇒ 什么都不做）
     *
     * @api
     */
    public function prewarm(int $n = 1): void
    {
        $target = \min($n, $this->maxWorkers);

        while (\count($this->workers) < $target) {
            if ($this->spawnWorker() === null) {
                return; // 起不来：Errors 已报过；剩下的留给 `flush()` 按需重试
            }
        }
    }

    public function submit(Job $job): Future
    {
        // ★ 先占号：取消钩子要拿它去队列里定位"还没发出去的那一条"。
        //   ★ 提前自增的代价只是"参数写错也会消耗一个号" —— 号本身不对外，
        //     除了 worker 回包对账（`id` 原样来回）。
        $id = ++$this->seq;

        $future = Future::pending(function () use ($id): void {
            $this->dropQueued($id);
        });

        if (!$job instanceof NamedJob) {
            $this->fail($future, 'ProcessTaskHost 只能跑 NamedJob —— ' . get_class($job)
                . ' 没法跨进程重建（要传对象就摊平成数组，见 NamedJob 的注释）');

            return $future;
        }

        $name = $job->name();
        if (!isset($this->registry[$name])) {
            $this->fail($future, 'ProcessTaskHost：任务未注册 —— ' . $name . '（UI 与 worker 的注册表必须一致）');

            return $future;
        }

        $line = json_encode(['id' => $id, 'job' => $name, 'arg' => $job->args()]);
        if ($line === false) {
            $this->fail($future, 'ProcessTaskHost：参数转不了 JSON —— ' . $name . '：' . json_last_error_msg());

            return $future;
        }

        $this->queue[] = [
            'id' => $id,
            'name' => $name,
            'line' => $line,
            'future' => $future,
        ];

        $this->flush();

        return $future;
    }

    /**
     * 把**还没发出去**的那一条从队列里摘掉（`Future::cancel()` 的钩子）。
     *
     * ★ 能真停的只有"还在排队"的。已发给 worker 的**停不了**：杀进程要重拉
     *   （一个 worker = 一整套 AOT 运行时，起价 275ms），还会丢在途状态 ——
     *   代价远大于让它跑完。那些走"**不交付**"：`Future` 被取消后迟到的
     *   `resolve()` 自己会被忽略（见 `Future::resolve()`）。
     *
     * ★★ 但**不能**提前把 `pending()` 减掉：它一落闸，帧时钟就停止上膛，
     *   管道里的回包永远读不到 ⇒ worker 写满管道后阻塞（僵尸进程）。
     *   ⇒ 在途的取消只影响"交付"，不影响"记账"。
     */
    private function dropQueued(int $id): void
    {
        foreach ($this->queue as $k => $item) {
            if ($item['id'] === $id) {
                unset($this->queue[$k]);
                $this->queue = array_values($this->queue);

                return;
            }
        }
    }

    /**
     * 记一条"发不出去"的失败（帧内结算，见 `$failed` 的注释）。
     *
     * ★ 用 `RuntimeException` 而不是 `Errors::report`：这些是**调用方写错了**，
     *   它有权 `catch()` 兜住并显示；直接喊 Fatal 会把"可恢复的配置错误"变成
     *   崩溃。而没人 `catch()` 时 `Future` 自己会走 Fatal 序列（G2 仍然成立）。
     */
    private function fail(Future $future, string $message): void
    {
        $this->failed->push(new Completion(null, new \RuntimeException($message), $future));
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
        // ★ 预算的基准点只取**一次**：三段（failed / readReplies / 簿记）共用它，
        //   否则每段各算一次，总耗时就是预算的三倍。换算式见
        //   `CompletionQueue::deadline()`（三个后端同一处）。
        $deadline = CompletionQueue::deadline($budgetMs);

        // ★ 发不出去的那些**先结算**：它们比在途的更早（`submit()` 时就定了），
        //   而且结算顺序影响"同一帧里谁先看到失败"。
        // ★ 超预算之后仍然往下走（`readReplies` / 簿记），不是 `return` ——
        //   那两段是"交付"，簿记则是无条件必须跑的（契约③）。
        $this->failed->drain($onEach, $deadline);

        $this->readReplies($onEach, $deadline);

        // ★★ 契约③：下面两段**不受预算约束**，无条件跑。
        //   `flush()` 是把排队任务真正发给 worker —— 跳过它 ⇒ 任务永远发不出去 ⇒
        //   页面永远转圈；`reap()` 检测 worker 死亡 —— 跳过它 ⇒ 死者名下的在途
        //   任务永不结算 ⇒ 同样是永挂。**预算限的是"交多少条"，不是"干多少活"。**
        $this->sweepTimeouts($onEach);
        $this->reap($onEach);
        $this->flush();
    }

    /**
     * **超时清扫**：把跑过墙钟上限的在途任务判失败，并**杀掉**它那个 worker。
     *
     * ★★ 与 {@see self::reap()} 同属"簿记" ⇒ 放在不受预算约束的那一段
     *   （`drain()` 契约③）。跳过它的症状与跳过 `reap()` **完全一致**：
     *   那条任务永不结算 ⇒ 页面永远转圈 ⇒ 且 `pending()` 不为 0 ⇒ 帧时钟一直空转。
     *
     * ★ 为什么**必须杀进程**，而不是"只把 Future 判失败"：
     *   只判失败 = 客户端的 `Task.WaitAsync(timeout)`（.NET 6）—— 原任务**还在跑**，
     *   而且它的回包迟早会到达、被丢弃。进程池里那更糟：worker 永远占着一个坑，
     *   池的有效容量悄悄掉一格，且**没有任何报错**（FPM 对默认关的描述原样适用）。
     *   ⇒ 杀掉，让 {@see self::flush()} 下一帧自动补一个 —— 池容量回到原值。
     *
     * ★ 为什么**一个 worker 只会连坐一条**：`pickIdle()` 只挑 `busyOf() === 0`
     *   的 worker ⇒ 一个 worker 名下在途恒为 1 ⇒ 杀它不会误伤别人的任务。
     *
     * ★ 失败形态是 `RuntimeException`（不是 `Errors::report`）：与 `reap()` /
     *   `fail()` 同一条原则 —— 这是**调用方可以兜住**的失败（页面画"超时，可重试"），
     *   喊 Fatal 会把可恢复的错误变成崩溃；没人 `catch()` 时 `Future` 自己会走
     *   Fatal 序列，G2 仍然成立。
     *
     * ★★ **不做"慢日志"（FPM 的 `request_slowlog_timeout`）**：那需要一条独立的
     *   诊断通道与采样策略，本轮没有这个需求；超时消息里带上任务名已经够定位。
     *   **不做 per-job 覆盖**：两条取数任务都在 1s 量级，池级一个上限足够
     *   （FPM 也只有池级）。真出现"有的任务就是慢"再按需求加，不提前铺。
     */
    private function sweepTimeouts(callable $onEach): void
    {
        if ($this->requestTerminateMs === 0 || $this->inflight === []) {
            return;
        }

        $now = \hrtime(true);
        $limit = $this->requestTerminateMs * 1_000_000;

        foreach ($this->inflight as $id => $item) {
            if ($now - $item['startedAt'] < $limit) {
                continue;
            }

            unset($this->inflight[$id]);

            $onEach(new Completion(
                null,
                new \RuntimeException(
                    '后台任务超时：' . $item['name'] . ' 超过 ' . $this->requestTerminateMs
                    . 'ms 没回来（worker 已终止，池会自动补一个）'
                ),
                $item['future'],
            ));

            $this->dropWorker((int) $item['worker'], true);
        }
    }

    /**
     * 还有多少条没回贴（在途 + 排队）—— 帧时钟据此决定要不要定时唤醒（§17.4.9）。
     *
     * ★ **必须把排队算进来**：`submit()` 只是入队，真正发给 worker 是在 `flush()`
     *   （`drain()` 里）。只算在途的话，"提交后、首帧前"这一段是 0 ⇒ 定时器不起 ⇒
     *   第一次 `pump()` 阻塞到用户动鼠标，任务才会被发出去。
     */
    public function pending(): int
    {
        return \count($this->inflight) + \count($this->queue) + $this->failed->count();
    }

    /**
     * 本后端**必须**靠每帧 `drain()` 拉 —— 子进程的管道是"读才知道有没有"，
     * 没有任何东西能在数据到达时叫醒 UI 线程。
     *
     * ★ 这里的 `true` 是**常态代价**（与 `SyncTaskHost` 的"有界一次"不同）：
     *   一条 600ms 的任务 = 40 次空唤醒。要消掉它得给子进程加一条唤醒通道
     *   （读端 `PeekNamedPipe` 仍是被动问）—— 真要做，路在 C++ 侧
     *   `ThreadTaskHost` 那条（`PostMessageW`），而不是在这里加定时器。
     */
    public function needsPolling(): bool
    {
        return true;
    }

    /**
     * 单条任务的墙钟上限（毫秒，`0` = 不设上限）—— devtools / 测试读它。
     *
     * ★ 与 `workerCount()` / `inFlightCount()` / `queuedCount()` 同类：这个类的
     *   "池现在什么状态"是可观测的，超时上限是其中一项。
     * ★★ 它也是"**默认开**"这条决定的**唯一判据**：默认值看不见，那"默认开"就
     *   只是注释里的一句话（`JobTimeoutTest` 钉着它 `> 0`）。
     */
    public function requestTerminateMs(): int
    {
        return $this->requestTerminateMs;
    }

    /** 在途任务数（测试与 devtools 用） */
    public function inFlightCount(): int
    {
        return count($this->inflight);
    }

    /** 还有多少没发出去（测试用） */
    public function queuedCount(): int
    {
        return count($this->queue);
    }

    /** 池里有几个 worker（测试与 devtools 用；只数活着的） */
    public function workerCount(): int
    {
        return count($this->workers);
    }

    /** worker 是否在跑（测试用；崩溃后会变 false） */
    public function isRunning(): bool
    {
        foreach ($this->workers as $worker) {
            if ((bool) proc_get_status($worker['proc'])['running']) {
                return true;
            }
        }

        return false;
    }

    /**
     * 收工：关管道 + 收进程（组合根在退出时调；不调也不会泄漏 —— worker 会因 stdin 关闭退出）。
     *
     * ★ 先 `fclose` stdin 再 `proc_close`：worker 的 `fgets` 返回 false ⇒ 正常收工，
     *   不会变成"被杀的僵尸进程"。
     *
     * @api
     */
    public function shutdown(): void
    {
        foreach ($this->workers as $worker) {
            @fclose($worker['in']);
            @fclose($worker['out']);
            @fclose($worker['err']);
            @proc_close($worker['proc']);
        }

        $this->workers = [];
        $this->inflight = [];
    }

    // ── 内部实现 ────────────────────────────────────────────────────────────

    /** 起一个 worker，返回它的索引（失败 ⇒ `null`，Errors 已报过） */
    private function spawnWorker(): ?int
    {
        // ★★ **必须是 `socket` 而不是 `pipe`**（2026-10-18 实测，Windows）：
        //   - `pipe + stream_select(0)` = **13.6ms/次**（Windows 的 `select()` 只支持 socket，
        //     PHP 对 pipe 走的是"逐句柄猜"的退化路径）；
        //   - `pipe + 非阻塞 fread` = **411ms/次**（非阻塞标记在 pipe 上等于没用）；
        //   - `socket + stream_select(0)` = **0.008ms**、`socket + 非阻塞 fread` = **0.001ms**。
        //   ⇒ 用 pipe 的版本每帧 drain 要 29ms（`drain()` 里两次 select）—— 界面上就是
        //   稳定的 30fps 掉帧，而且**看不出是谁的错**（帧耗时里只有"慢"）。
        $descriptors = [
            0 => ['socket'], // worker 的 stdin
            1 => ['socket'], // worker 的 stdout
            2 => ['socket'], // worker 的 stderr（PHP 的 warning / fatal 走这里）
        ];

        $proc = @proc_open($this->command, $descriptors, $pipes);
        if (!is_resource($proc)) {
            $error = error_get_last();

            Errors::report(
                'ProcessTaskHost：worker 起不来 —— ' . implode(' ', $this->command)
                . '（' . ($error['message'] ?? '未知原因') . '）'
            );

            return null;
        }

        // ★ stdout / stderr 必须非阻塞：`drain()` 每帧只看一眼，绝不等待。
        //   stdin 保持阻塞写（每个 worker 在途 ≤ 1 条 ⇒ 管道永远不会写满）。
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $index = $this->workerSeq++;
        $this->workers[$index] = [
            'proc' => $proc,
            'in' => $pipes[0],
            'out' => $pipes[1],
            'err' => $pipes[2],
            'buffer' => '',
            'stderr' => '',
        ];

        return $index;
    }

    /** 这个 worker 名下有几条在途 */
    private function busyOf(int $index): int
    {
        $busy = 0;
        foreach ($this->inflight as $item) {
            if ($item['worker'] === $index) {
                ++$busy;
            }
        }

        return $busy;
    }

    /** 挑一个空闲且活着的 worker；没有 ⇒ `null` */
    private function pickIdle(): ?int
    {
        foreach ($this->workers as $index => $worker) {
            if ($this->busyOf($index) === 0 && (bool) proc_get_status($worker['proc'])['running']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * 把队列里的任务发出去：**每个空闲 worker 接一条**，满载就排队。
     *
     * ★ 满载时**返回而不是继续起进程**：一个 worker 是一整套 AOT 运行时（几十 MB），
     *   无界起进程等于把"并发"变成"内存炸弹"。上限由构造参数给。
     */
    private function flush(): void
    {
        while ($this->queue !== []) {
            $index = $this->pickIdle();
            if ($index === null) {
                if (count($this->workers) >= $this->maxWorkers) {
                    return;
                }
                $index = $this->spawnWorker();
                if ($index === null) {
                    return; // 起不来：留在队列里，下帧再试（Errors 已经报过）
                }
            }

            $item = array_shift($this->queue);
            if (!$this->send($index, $item)) {
                // 管道断了：放回队首，让 `reap()` 在下一帧把它转成错误并重启
                array_unshift($this->queue, $item);

                return;
            }
        }
    }

    /**
     * 往指定 worker 写一行；成功则记进在途表。
     *
     * @param array{id: int, name: string, line: string, future: Future} $item
     */
    private function send(int $index, array $item): bool
    {
        $worker = $this->workers[$index] ?? null;
        if ($worker === null) {
            return false;
        }

        $written = @fwrite($worker['in'], $item['line'] . "\n");
        if ($written === false || $written === 0) {
            return false;
        }
        @fflush($worker['in']);

        $this->inflight[$item['id']] = [
            'future' => $item['future'],
            'worker' => $index,
            'name' => $item['name'],
            // ★ 超时计时从**这一刻**（派发给 worker）开始 —— 见
            //   {@see self::$requestTerminateMs}：排队等空闲不是"卡住"。
            // ★ 诚实边界：对**刚 spawn 的 worker**，这一刻早于"它能干活"（它还在
            //   启动）⇒ 它的第一条任务，预算里含着启动时间（几百毫秒量级）。
            //   默认 30s 无感；把上限调到几百毫秒时它会被算进去（`JobTimeoutTest`
            //   的"补一个 worker"用例因此把上限放宽到 2s）。不加"启动宽限"，是因为
            //   宽限值只能猜 —— 猜出来的数字没有判据能证伪（本仓：不可证的代码不加）。
            'startedAt' => \hrtime(true),
        ];

        return true;
    }

    /**
     * 非阻塞读**所有** worker 的回复（按 `\n` 切行，余下的留到下一帧）。
     *
     * @param int $deadline `hrtime` 纳秒截止点；`0` = 不限
     */
    private function readReplies(callable $onEach, int $deadline): void
    {
        foreach (array_keys($this->workers) as $index) {
            $worker = $this->workers[$index] ?? null;
            if ($worker === null) {
                continue;
            }

            // ★★ stderr **永远读，不受预算影响**（这一段在预算检查之前，顺序不能换）：
            //   不读的话管道满了 worker 会阻塞在 warning 上 —— 那是**真正的死锁**，
            //   代价远大于"这一帧少交付几条结果"。宁可多花几十微秒也不能跳过。
            $errRead = [$worker['err']];
            $noWrite = [];
            $noExcept = [];
            if ((int) @stream_select($errRead, $noWrite, $noExcept, 0) > 0) {
                $chunk = fread($worker['err'], self::READ_CHUNK);
                if (is_string($chunk)) {
                    $this->workers[$index]['stderr'] .= $chunk;
                }
            }

            // ★ 超预算 ⇒ 这一帧**完全不碰 out**：不读、不切行、不交付。
            //   剩余全部留在管道 / `buffer` 里，下一帧原样继续 —— 行是按 `\n` 切的，
            //   停在行边界之外也不会切坏多字节字符（`buffer` 拼接而非覆盖）。
            //   ★ 这里用 `continue` 而不是 `return`：后面的 worker 还有其他要读的
            //     stderr（上面那段已经读过当前这个），而 `return` 会把它们一起跳过。
            if ($deadline > 0 && \hrtime(true) >= $deadline) {
                continue;
            }

            $read = [$worker['out']];
            $write = [];
            $except = [];
            if ((int) @stream_select($read, $write, $except, 0) === 0) {
                continue;
            }

            $chunk = fread($worker['out'], self::READ_CHUNK);
            if ($chunk === false || $chunk === '') {
                continue;
            }

            $this->workers[$index]['buffer'] .= $chunk;

            while (($pos = strpos($this->workers[$index]['buffer'], "\n")) !== false) {
                // ★ 行与行之间也要检查：一个 8KB 块可能含几十行，块内就把预算烧完。
                //   停在行边界 ⇒ 剩余是完整的行，下一帧直接切。
                if ($deadline > 0 && \hrtime(true) >= $deadline) {
                    break;
                }

                $line = substr($this->workers[$index]['buffer'], 0, $pos);
                $this->workers[$index]['buffer'] = substr($this->workers[$index]['buffer'], $pos + 1);
                $this->deliver($line, $onEach);
            }
        }
    }

    /** 把一行回复变成 `Completion`（与 `SyncTaskHost` 的出口完全同构） */
    private function deliver(string $line, callable $onEach): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $msg = json_decode($line, true);
        if (!is_array($msg) || !isset($msg['id'])) {
            Errors::report('ProcessTaskHost：worker 回了非协议数据 —— ' . $line);

            return;
        }

        $id = (int) $msg['id'];
        $item = $this->inflight[$id] ?? null;
        if ($item === null) {
            Errors::report('ProcessTaskHost：收到未知 id=' . (string) $id . ' 的回复（在途表与管道不同步）');

            return;
        }
        unset($this->inflight[$id]);

        if (!empty($msg['ok'])) {
            $onEach(new Completion($msg['value'] ?? null, null, $item['future']));

            return;
        }

        $onEach(new Completion(
            null,
            new \RuntimeException((string) ($msg['error'] ?? 'worker 未给出错误信息')),
            $item['future'],
        ));
    }

    /**
     * 检查每个 worker 是否还活着；死了就把**它名下**的在途任务转成错误并移出池。
     *
     * ★ **崩溃隔离就体现在这里**：worker 崩了，UI 只是收到一个 `onError`，
     *   下一帧 `flush()` 会自动补一个新的（任务本身不重试 —— 重试策略归业务）。
     * ★ 只结算**死者名下**的在途：别的 worker 还在正常跑，不能被连坐。
     */
    private function reap(callable $onEach): void
    {
        // ★ **空闲才降频**：有在途任务时立即查（崩溃要尽快变成 `onError`），
        //   空闲时每 500ms 查一次就够 —— 那时只是回收僵尸进程，没人等结果。
        //   每次 `proc_get_status` 是**一个系统调用 × worker 数**，每帧付不划算
        //   （`analysis/16` §2 / `20` P0-3）。
        //   ★ 实时性没丢：有任务在跑时管道 EOF 已被 `readReplies()` 当场发现
        //     （见 `readReplies()` 里"管道断了"那条），这里只是补"空闲期间崩"的回收。
        $now = intdiv(\hrtime(true), 1000);

        if ($this->inflight === [] && $now - $this->lastReapUs < self::REAP_IDLE_INTERVAL_US) {
            return;
        }

        $this->lastReapUs = $now;

        foreach ($this->workers as $index => $worker) {
            $status = proc_get_status($worker['proc']);
            if ($status['running']) {
                continue;
            }

            $parts = ['后台进程退出（exit=' . (string) $status['exitcode'] . '）'];
            if (trim($worker['stderr']) !== '') {
                $parts[] = trim($worker['stderr']);
            }
            $message = implode('：', $parts);

            foreach ($this->inflight as $id => $item) {
                if ($item['worker'] !== $index) {
                    continue;
                }
                $onEach(new Completion(null, new \RuntimeException($message), $item['future']));
                unset($this->inflight[$id]);
            }

            // 已经**不 running** 了 ⇒ 不需要再 terminate
            $this->dropWorker($index, false);
        }
    }

    /**
     * 把一个 worker 从池里摘掉并收掉它的三条管道。
     *
     * ★ `reap()` 与 `sweepTimeouts()` 唯一差别就是 `$terminate` —— 死者不必再杀，
     *   卡住者必须杀（不杀它就永远占着那个坑，见 `sweepTimeouts()` 的注释）。
     *   两处共用这一段，免得"关几条管道"这种簿记出现两份。
     *
     * ★ `proc_close()` 放在后：它等子进程真正退出并回收句柄；先 `fclose()` 三条
     *   管道是给对端一个干净 EOF，免得它卡在写 stderr 上。
     */
    private function dropWorker(int $index, bool $terminate): void
    {
        $worker = $this->workers[$index] ?? null;
        if ($worker === null) {
            return;
        }

        if ($terminate) {
            @proc_terminate($worker['proc']);
        }

        @fclose($worker['in']);
        @fclose($worker['out']);
        @fclose($worker['err']);
        @proc_close($worker['proc']);

        unset($this->workers[$index]);
    }
}
