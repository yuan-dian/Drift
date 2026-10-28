<?php
declare(strict_types=1);

namespace Drift\App;

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
     * @var array<int, array{onDone: \Closure, onError: ?\Closure, worker: int}>
     */
    private array $inflight = [];

    /**
     * 还没发出去的任务（没有空闲 worker / worker 没起好）。
     *
     * @var list<array{id: int, line: string, onDone: \Closure, onError: ?\Closure}>
     */
    private array $queue = [];

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

    public function submit(Job $job, callable $onDone, ?callable $onError = null): void
    {
        if (!$job instanceof NamedJob) {
            Errors::report(
                'ProcessTaskHost 只能跑 NamedJob —— ' . get_class($job)
                . ' 没法跨进程重建（要传对象就摊平成数组，见 NamedJob 的注释）'
            );

            return;
        }

        $name = $job->name();
        if (!isset($this->registry[$name])) {
            Errors::report('ProcessTaskHost：任务未注册 —— ' . $name . '（UI 与 worker 的注册表必须一致）');

            return;
        }

        $line = json_encode(['id' => $this->seq + 1, 'job' => $name, 'arg' => $job->args()]);
        if ($line === false) {
            Errors::report('ProcessTaskHost：参数转不了 JSON —— ' . $name . '：' . json_last_error_msg());

            return;
        }

        $this->seq++;
        $this->queue[] = [
            'id' => $this->seq,
            'line' => $line,
            'onDone' => \Closure::fromCallable($onDone),
            'onError' => $onError === null ? null : \Closure::fromCallable($onError),
        ];

        $this->flush();
    }

    public function drain(callable $onEach): void
    {
        $this->readReplies($onEach);
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
        return \count($this->inflight) + \count($this->queue);
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
     * @param array{id: int, line: string, onDone: \Closure, onError: ?\Closure} $item
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
            'onDone' => $item['onDone'],
            'onError' => $item['onError'],
            'worker' => $index,
        ];

        return true;
    }

    /** 非阻塞读**所有** worker 的回复（按 `\n` 切行，余下的留到下一帧） */
    private function readReplies(callable $onEach): void
    {
        foreach (array_keys($this->workers) as $index) {
            $worker = $this->workers[$index] ?? null;
            if ($worker === null) {
                continue;
            }

            // stderr 也要读：不读的话管道满了 worker 会阻塞在 warning 上（真正的死锁来源）
            $errRead = [$worker['err']];
            $noWrite = [];
            $noExcept = [];
            if ((int) @stream_select($errRead, $noWrite, $noExcept, 0) > 0) {
                $chunk = fread($worker['err'], self::READ_CHUNK);
                if (is_string($chunk)) {
                    $this->workers[$index]['stderr'] .= $chunk;
                }
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
            $onEach(new Completion($msg['value'] ?? null, null, $item['onDone'], $item['onError']));

            return;
        }

        $onEach(new Completion(
            null,
            new \RuntimeException((string) ($msg['error'] ?? 'worker 未给出错误信息')),
            $item['onDone'],
            $item['onError'],
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
                $onEach(new Completion(null, new \RuntimeException($message), $item['onDone'], $item['onError']));
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
