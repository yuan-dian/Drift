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
     * @var list<array{id: int, line: string, future: Future}>
     */
    private array $queue = [];

    /**
     * **当场就失败**的任务（不是 `NamedJob` / 名字没注册 / 参数转不了 JSON）。
     *
     * ★ 它们不进 `$queue`（那里是"等着发出去"，这些永远发不出去），也不在这里
     *   直接 `reject()`：`submit()` 返回之后调用方才挂 `catch()`，当场结算会
     *   让"刚提交就 Fatal"。⇒ 入这个表，由 `drain()` 在**帧内**结算 ——
     *   与真任务失败走同一条时序，调用方看不出差别。
     *
     * @var list<Completion>
     */
    private array $failed = [];

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
     * @param list<string> $command worker 的启动命令（如 `[$exe, '--drift-worker']`；
     *   测试里是 `[PHP_BINARY, 'worker.php']`）
     * @param array<string, callable(array<string, mixed>): Job> $registry
     *        名字 → 重建工厂。★ 必须与 worker 侧 `ProcessWorker::serve()` 的注册表**一致**：
     *        UI 侧用它做"名字打错就早失败"，worker 侧用它真正重建任务。
     * @param int $maxWorkers 池上限（默认 1 = 串行）。>1 才可能真并行 —— 满载排队，
     *        不会无界起进程。
     *
     * @api
     */
    public function __construct(
        private readonly array $command,
        array $registry,
        int $maxWorkers = 1,
    ) {
        if ($maxWorkers < 1) {
            Errors::report('ProcessTaskHost：maxWorkers 必须 ≥ 1（给的是 ' . $maxWorkers . '）');
            $maxWorkers = 1;
        }
        $this->maxWorkers = $maxWorkers;

        foreach ($registry as $name => $factory) {
            $this->registry[(string) $name] = \Closure::fromCallable($factory);
        }
    }

    /** @var array<string, \Closure> */
    private array $registry = [];

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
        $this->failed[] = new Completion(null, new \RuntimeException($message), $future);
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
        // ★ 预算的基准点只取**一次**：三段（failed / readReplies / 簿记）共用它，
        //   否则每段各算一次，总耗时就是预算的三倍。
        $deadline = $budgetMs === null || $budgetMs <= 0
            ? 0
            : \hrtime(true) + $budgetMs * 1_000_000;

        // ★ 发不出去的那些**先结算**：它们比在途的更早（`submit()` 时就定了），
        //   而且结算顺序影响"同一帧里谁先看到失败"。
        if ($this->failed !== []) {
            $failed = $this->failed;
            $this->failed = [];

            foreach ($failed as $i => $completion) {
                // 契约①：`$i > 0` 放行首条（否则活锁）。
                if ($deadline > 0 && $i > 0 && \hrtime(true) >= $deadline) {
                    // 契约②：放回 `$this->failed` ⇒ `pending()` 仍数得到 ⇒ 不睡死。
                    $this->failed = \array_merge(\array_slice($failed, $i), $this->failed);

                    break;
                }

                $onEach($completion);
            }
        }

        $this->readReplies($onEach, $deadline);

        // ★★ 契约③：下面两段**不受预算约束**，无条件跑。
        //   `flush()` 是把排队任务真正发给 worker —— 跳过它 ⇒ 任务永远发不出去 ⇒
        //   页面永远转圈；`reap()` 检测 worker 死亡 —— 跳过它 ⇒ 死者名下的在途
        //   任务永不结算 ⇒ 同样是永挂。**预算限的是"交多少条"，不是"干多少活"。**
        $this->reap($onEach);
        $this->flush();
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
        return \count($this->inflight) + \count($this->queue) + \count($this->failed);
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
     * @param array{id: int, line: string, future: Future} $item
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

            @fclose($worker['in']);
            @fclose($worker['out']);
            @fclose($worker['err']);
            @proc_close($worker['proc']);

            unset($this->workers[$index]);
        }
    }
}
