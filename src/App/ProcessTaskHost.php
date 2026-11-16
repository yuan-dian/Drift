<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Failure;
use Drift\Async\Future;
use Drift\Errors;

/**
 * `TaskHost` 后端 —— 常驻子进程池：真后台、真隔离、跑的是 AOT 代码。
 *
 * UI 进程通过 JSON Lines 把任务写进 worker 的 stdin，每帧 `drain()` 非阻塞看一眼回复。
 * 多个 worker 进程 = 并发（进程内是串行的）；默认 `maxWorkers = 1` 即串行。
 *
 * @api
 */
final class ProcessTaskHost implements TaskHost
{
    /** 一次最多读多少字节（8K ≈ 几十条回复，够一帧消化） */
    private const int READ_CHUNK = 8192;

    /**
     * 在跑的 worker 池。
     *
     * 索引自增、不重用：否则老 worker 的迟到回复会被算到新 worker 头上（错误归属错乱）。
     *
     * @var array<int, array{proc: mixed, in: mixed, out: mixed, err: mixed, buffer: string, stderr: string}>
     */
    private array $workers = [];

    /**
     * 已发出、等回复的任务（`worker` = 记在哪个 worker 名下，用于挑空闲）。
     *
     * `limitMs` = 这条任务生效的墙钟上限（`0` ⇒ 用 Host 默认；见 {@see TimedJob}）。
     *
     * @var array<int, array{future: Future, worker: int, name: string, limitMs: int, startedAt: int}>
     */
    private array $inflight = [];

    /**
     * 还没发出去的任务（没有空闲 worker / worker 没起好）。
     *
     * `name` 不是为了发送（`line` 里已有），是为了超时 / 诊断时少解一次 JSON。
     *
     * @var list<array{id: int, name: string, line: string, future: Future, limitMs: int}>
     */
    private array $queue = [];

    /**
     * **当场就失败**的任务（不是 `NamedJob` / 名字没注册 / 参数转不了 JSON）。
     *
     * 不进 `$queue`（永远发不出去），也不在 `submit()` 里直接 `reject()`（会让"刚提交就 Fatal"）；
     * 入此队列，由 `drain()` 在帧内结算，与真任务失败时序一致。
     * 交付算法（至少一条 / 剩余回队头 / 回调抛异常也不丢）在 {@see CompletionQueue}。
     */
    private CompletionQueue $failed;

    private int $seq = 0;

    /** 上次 reap() 的时刻（µs，hrtime 单调）—— 空闲期降频用 */
    private int $lastReapUs = 0;

    /** 空闲期 reap 的最小间隔（500ms ≈ 33 帧） */
    private const int REAP_IDLE_INTERVAL_US = 500_000;

    /** worker 索引发号器（只增不减，见 $workers 的注释） */
    private int $workerSeq = 0;

    /** 池上限（1 = 串行） */
    private readonly int $maxWorkers;

    /**
     * **未声明** `TimedJob` 的任务的墙钟上限（毫秒，`0` = 不设上限）。
     *
     * 从**派发给 worker** 那一刻起算（排队等空闲不是"卡住"）；默认开（30s）——
     * 否则一条卡死的任务会让 `Future` 永不结算、UI 永远转圈且没有任何报错。
     * 声明了 `TimedJob::timeoutMs()` 的任务按自己的数走（批量上传 / 下载远超 30s）。
     *
     * @api
     */
    private readonly int $requestTerminateMs;

    /**
     * @param list<string> $command worker 的启动命令（如 [$exe, '--drift-worker']）
     * @param array<string, callable(array<string, mixed>): Job> $registry
     *        名字 → 重建工厂（须与 worker 侧 `ProcessWorker::serve()` 注册表一致）。
     * @param int $maxWorkers 池上限（默认 1 = 串行）。>1 才可能真并行，满载排队，不会无界起进程。
     * @param int $requestTerminateMs **未声明** `TimedJob` 的任务的墙钟上限（毫秒，`0` = 不设上限）。默认 30_000。
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

        // 负值按"不设上限"处理：显式 `0` 与负数在此同义。
        $this->requestTerminateMs = $requestTerminateMs > 0 ? $requestTerminateMs : 0;

        foreach ($registry as $name => $factory) {
            $this->registry[(string) $name] = \Closure::fromCallable($factory);
        }
    }

    /** @var array<string, \Closure> */
    private array $registry = [];

    /**
     * 预热：先把 worker 起好，让第一条任务不用等进程启动（那 275ms 落在首条任务头上）。
     *
     * 只发起到 `proc_open` 返回（异步），不等就绪；真正能干活在首次 `flush()` 时衔接，
     * `$n` 会被 `maxWorkers` 夹住，已经够数则直接返回（幂等）。
     *
     * @param int $n 想准备几个 worker（≤ 0 ⇒ 什么都不做）
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
        // 先占号：取消钩子要拿它去队列定位"还没发出去的那一条"。
        // 提前自增的代价只是"参数写错也消耗一个号"——号不对外，仅用于回包对账。
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

        // 上限在提交时定：send() 只看得到 queue 项，拿不到 Job 对象。
        $limitMs = $job instanceof TimedJob ? \max(0, $job->timeoutMs()) : 0;

        $this->queue[] = [
            'id' => $id,
            'name' => $name,
            'line' => $line,
            'future' => $future,
            'limitMs' => $limitMs,
        ];

        $this->flush();

        return $future;
    }

    /**
     * 三档里唯一"取消能省 CPU"的：还在排队（没派发）的那条会被真的摘掉。
     *
     * ★ 已发给 worker 的停不了（杀进程要重拉、还丢在途状态）—— 见 `dropQueued()`。
     */
    public function cancelSupport(): CancelSupport
    {
        return CancelSupport::QueuedOnly;
    }

    /**
     * 把**还没发出去**的那一条从队列里摘掉（`Future::cancel()` 的钩子）。
     *
     * 能真停的只有"还在排队"的。已发给 worker 的停不了：杀进程要重拉（起价 275ms）
     * 还会丢在途状态，代价远大于让它跑完；那些走"不交付"，迟到的 `resolve()` 被忽略。
     *
     * ★ 但**不能**提前把 `pending()` 减掉：它一落闸，帧时钟就停止上膛，
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
     * 用 `Failure` 而非裸 `RuntimeException`：这三条全是**装配写错了**，给稳定的
     * `CODE_JOB_REJECTED` 让 UI 能画"配置错误"而不是"重试"（重试还是同一句）。
     * 仍然"可 catch"：没人 catch 时 `Future` 自己走 Fatal 序列。
     */
    private function fail(Future $future, string $message): void
    {
        $this->failed->push(new Completion(
            null,
            new Failure($message, Failure::CODE_JOB_REJECTED),
            $future,
        ));
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
        // 预算基准点只取一次（failed / readReplies / 簿记 共用），否则总耗时是预算的三倍。
        $deadline = CompletionQueue::deadline($budgetMs);

        // 发不出去的先结算（submit() 时就定，顺序影响同帧内谁先看到失败）；
        // 超预算后仍继续：readReplies / 簿记无条件必须跑，预算限"交多少"不限"干多少"。
        $this->failed->drain($onEach, $deadline);

        $this->readReplies($onEach, $deadline);

        // 契约③：下面两段不受预算约束，无条件跑。
        // flush() 跳过 ⇒ 任务永远发不出去 ⇒ 页面永远转圈；
        // reap() 跳过 ⇒ 死者名下在途永不结算 ⇒ 同样永挂。预算限"交多少"不限"干多少"。
        $this->sweepTimeouts($onEach);
        $this->reap($onEach);
        $this->flush();
    }

    /**
     * 超时清扫：把跑过**各自上限**的在途任务判失败，并**杀掉**它那个 worker。
     *
     * 每条的上限 = `TimedJob::timeoutMs()`（声明优先），没声明才用 `requestTerminateMs` 兜底。
     *
     * 必须杀进程，不能只把 Future 判失败：否则 worker 永远占着坑，池容量悄悄掉一格
     * 且没有任何报错；杀掉后 `flush()` 下一帧自动补一个。
     * 一个 worker 只会连坐一条（`pickIdle()` 只挑在途为 0 的）；失败形态是 `RuntimeException`，
     * 让调用方可以兜住（页面画"超时，可重试"）。
     */
    private function sweepTimeouts(callable $onEach): void
    {
        if ($this->inflight === []) {
            return;
        }

        $now = \hrtime(true);

        foreach ($this->inflight as $id => $item) {
            // 声明优先于兜底：`requestTerminateMs = 0` 关的是"Host 兜底"，不是"任务自己的声明"。
            $limitMs = $item['limitMs'] > 0 ? $item['limitMs'] : $this->requestTerminateMs;
            if ($limitMs <= 0 || $now - $item['startedAt'] < $limitMs * 1_000_000) {
                continue;
            }

            unset($this->inflight[$id]);

            $onEach(new Completion(
                null,
                new Failure(
                    '后台任务超时：' . $item['name'] . ' 超过 ' . $limitMs . 'ms 没回来（'
                    . ($item['limitMs'] > 0 ? '任务声明上限' : 'Host 默认上限')
                    . '；worker 已终止，池会自动补一个）',
                    Failure::CODE_TIMEOUT,
                ),
                $item['future'],
            ));

            $this->dropWorker((int) $item['worker'], true);
        }
    }

    /**
     * 还有多少条没回贴（在途 + 排队）—— 帧时钟据此决定要不要定时唤醒。
     *
     * 必须把排队算进来：`submit()` 只是入队，真正发在 `flush()`（drain 里）。
     * 只算在途的话，"提交后、首帧前"是 0 ⇒ 定时器不起 ⇒ 任务要等用户动鼠标才发出。
     */
    public function pending(): int
    {
        return \count($this->inflight) + \count($this->queue) + $this->failed->count();
    }

    /**
     * 本后端必须靠每帧 drain() 拉 —— 子进程管道是"读才知道有没有"，
     * 没有东西能在数据到达时叫醒 UI 线程。
     *
     * 这里的 true 是常态代价（一条 600ms 任务 ≈ 40 次空唤醒）；要消掉得靠 C++ 侧 `ThreadTaskHost` 的 `PostMessageW`，不在这里加定时器。
     */
    public function needsPolling(): bool
    {
        return true;
    }

    /**
     * **未声明** `TimedJob` 的任务的墙钟上限（毫秒，`0` = 不设上限）—— devtools / 测试读它。
     *
     * 与 `workerCount()` / `inFlightCount()` / `queuedCount()` 同类：池的可观测状态之一。
     * 它也是"默认开"这条决定的唯一判据（`JobTimeoutTest` 钉着它 > 0）。
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
     * 先 fclose stdin 再 proc_close：worker 的 fgets 返回 false ⇒ 正常收工，不变成被杀的僵尸。
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

    /** 起一个 worker，返回它的索引（失败 ⇒ null，Errors 已报过） */
    private function spawnWorker(): ?int
    {
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

        // stdout / stderr 必须非阻塞：drain() 每帧只看一眼，绝不等待。
        // stdin 保持阻塞写（每个 worker 在途 ≤ 1 条 ⇒ 管道永不写满）。
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

    /** 挑一个空闲且活着的 worker；没有 ⇒ null */
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
     * 把队列里的任务发出去：每个空闲 worker 接一条，满载就排队。
     *
     * 满载时返回而不是继续起进程：一个 worker 是一整套 AOT 运行时（几十 MB），
     * 无界起进程 = 内存炸弹。上限由构造参数给。
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
                // 管道断了：放回队首，让 reap() 在下一帧把它转成错误并重启
                array_unshift($this->queue, $item);

                return;
            }
        }
    }

    /**
     * 往指定 worker 写一行；成功则记进在途表。
     *
     * @param array{id: int, name: string, line: string, future: Future, limitMs: int} $item
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
            'limitMs' => $item['limitMs'],
            // 超时计时从这一刻（派发给 worker）开始：排队等空闲不是"卡住"。
            // 刚 spawn 的 worker 此刻早于它能干活，首条任务预算含启动时间；默认 30s 无感，故不加启动宽限。
            'startedAt' => \hrtime(true),
        ];

        return true;
    }

    /**
     * 非阻塞读所有 worker 的回复（按 \n 切行，余下的留到下一帧）。
     *
     * @param int $deadline hrtime 纳秒截止点；0 = 不限
     */
    private function readReplies(callable $onEach, int $deadline): void
    {
        foreach (array_keys($this->workers) as $index) {
            $worker = $this->workers[$index] ?? null;
            if ($worker === null) {
                continue;
            }

            // ★ stderr 永远读，不受预算影响（此段在预算检查之前，顺序不能换）：
            //   不读 ⇒ 管道满了 worker 阻塞在 warning 上 —— 真正的死锁，
            //   代价远大于少交付几条。宁多花几十微秒也不能跳过。
            $errRead = [$worker['err']];
            $noWrite = [];
            $noExcept = [];
            if ((int) @stream_select($errRead, $noWrite, $noExcept, 0) > 0) {
                $chunk = fread($worker['err'], self::READ_CHUNK);
                if (is_string($chunk)) {
                    $this->workers[$index]['stderr'] .= $chunk;
                }
            }

            // 超预算 ⇒ 这一帧完全不碰 out：不读、不切行、不交付，剩余留管道 / buffer 下一帧继续。
            //   这里用 continue 而非 return：后面 worker 还有 stderr 要读，return 会一起跳过。
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
                // EOF 不在这里结算：进程退出由同一帧的 reap() 用 proc_get_status 看到
                // （实测两者同一轮询内到达，见 docs/DESIGN-JOB-TIMEOUT-2026-11-08.md §8）。
                continue;
            }

            $this->workers[$index]['buffer'] .= $chunk;

            while (($pos = strpos($this->workers[$index]['buffer'], "\n")) !== false) {
                // 行与行之间也要检查：一个 8KB 块可能含几十行，块内就把预算烧完，停在行边界 ⇒ 下一帧直接切。
                if ($deadline > 0 && \hrtime(true) >= $deadline) {
                    break;
                }

                $line = substr($this->workers[$index]['buffer'], 0, $pos);
                $this->workers[$index]['buffer'] = substr($this->workers[$index]['buffer'], $pos + 1);
                $this->deliver($line, $onEach);
            }
        }
    }

    /** 把一行回复变成 Completion（与 SyncTaskHost 的出口完全同构） */
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

        // ★ 进度行必须先判：进度行只有 {"id":N,"progress":f}，没有 ok 键。
        //   落到下面 !empty($msg['ok']) 那支会被当成"失败且无错误信息" ——
        //   一次上传在途中就变成"任务失败"，错误消息还是框架编的，归因跑偏。
        if (isset($msg['progress']) && !isset($msg['ok'])) {
            $item = $this->inflight[$id] ?? null;
            // 迟到 / 已超时 / 已取消的进度静默丢弃：进度本就允许晚于结果到（中间隔着一次 fflush），报出来会把真诊断淹掉。
            if ($item !== null) {
                $item['future']->reportProgress((float) $msg['progress']);
            }

            return;
        }

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
            new Failure(
                (string) ($msg['error'] ?? 'worker 未给出错误信息'),
                (string) ($msg['code'] ?? ''),
            ),
            $item['future'],
        ));
    }

    /**
     * 检查每个 worker 是否还活着；死了就把它名下的在途任务转成错误并移出池。
     *
     * 崩溃隔离就体现在这里：worker 崩了，UI 只收到一个 onError，
     * 下一帧 flush() 自动补一个新的（任务本身不重试 —— 重试策略归业务）。
     * 只结算死者名下的在途：别的 worker 还在正常跑，不能被连坐。
     */
    private function reap(callable $onEach): void
    {
        // 空闲才降频：有在途时立即查（崩溃尽快变 onError），空闲时每 500ms 查一次够（只回收僵尸，没人等结果）。
        // 实时性没丢：有任务在跑时进程退出由同一帧的 reap() 用 proc_get_status 看到
        // （实测管道 EOF 与 running=false 同一轮询内到达 —— 所以没有单独的 EOF 分支，
        // 见 docs/DESIGN-JOB-TIMEOUT-2026-11-08.md §8）；这里只补"空闲期间崩"的回收。
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
                $onEach(new Completion(null, new Failure($message, Failure::CODE_WORKER_DIED), $item['future']));
                unset($this->inflight[$id]);
            }

            // 已经不 running 了 ⇒ 不需要再 terminate
            $this->dropWorker($index, false);
        }
    }

    /**
     * 把一个 worker 从池里摘掉并收掉它的三条管道。
     *
     * reap() 与 sweepTimeouts() 唯一差别是 $terminate：死者不必再杀，卡住者必须杀。
     * proc_close() 放在后：先 fclose 三条管道给对端干净 EOF，免得它卡在写 stderr 上。
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
