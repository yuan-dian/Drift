<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * `TaskHost` 后端 —— **常驻子进程**（§17.4.5，P3）：真后台、真隔离、跑的是 AOT 代码。
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
 *   按任务起进程根本不可用。所以是**懒启动 + 常驻**：第一次 `submit()` 起一个，
 *   之后所有任务复用它；`drain()` 每帧非阻塞看一眼有没有回复。
 *
 * ★ **串行（在途最多 1 条）**：不是偷懒，是刻意的第一版形态 —— 并行需要"多 worker +
 *   空闲挑选 + 任务重派"，那是并发调度器。**先有正确的机制，再有并发**。
 *   一个常驻 worker 已经解决"UI 不被阻塞"，HTTP 一个接一个也够用；
 *   要并发就把 `$worker` 变成数组（结构不用改）。
 *
 * ★ **诚实边界**：worker 是**串行**的；`stdin` 用阻塞写（在途 1 条 ⇒ 管道不会写满）；
 *   协议是 JSON Lines（结构化数据合适，字节流不合适）。
 *
 * @api
 */
final class ProcessTaskHost implements TaskHost
{
    /** 一次最多读多少字节（8K ≈ 几十条回复，够一帧消化） */
    private const READ_CHUNK = 8192;

    /**
     * 在跑的 worker（`null` = 没起 / 已死）。
     *
     * @var null|array{proc: mixed, in: mixed, out: mixed, err: mixed}
     */
    private ?array $worker = null;

    /**
     * 已发出、等回复的任务。
     *
     * @var array<int, array{onDone: \Closure, onError: ?\Closure}>
     */
    private array $inflight = [];

    /**
     * 还没发出去的任务（worker 没起好 / 正在等上一条）。
     *
     * @var list<array{id: int, line: string, onDone: \Closure, onError: ?\Closure}>
     */
    private array $queue = [];

    /** stdout 的未完整行 */
    private string $buffer = '';

    /** stderr 攒着 —— worker 崩了要把它拼进错误消息（否则只剩 exit code） */
    private string $stderr = '';

    private int $seq = 0;

    /**
     * @param list<string> $command worker 的启动命令（如 `[$exe, '--drift-worker']`；
     *   测试里是 `[PHP_BINARY, 'worker.php']`）
     * @param array<string, callable(array<string, mixed>): Job> $registry
     *        名字 → 重建工厂。★ 必须与 worker 侧 `ProcessWorker::serve()` 的注册表**一致**：
     *        UI 侧用它做"名字打错就早失败"，worker 侧用它真正重建任务。
     *
     * @api
     */
    public function __construct(
        private readonly array $command,
        array $registry,
    ) {
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

    /** worker 是否在跑（测试用；崩溃后会变 false） */
    public function isRunning(): bool
    {
        if ($this->worker === null) {
            return false;
        }

        $status = proc_get_status($this->worker['proc']);

        return (bool) $status['running'];
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
        if ($this->worker === null) {
            return;
        }

        @fclose($this->worker['in']);
        @fclose($this->worker['out']);
        @fclose($this->worker['err']);
        @proc_close($this->worker['proc']);

        $this->worker = null;
        $this->buffer = '';
        $this->inflight = [];
    }

    // ── 内部实现 ────────────────────────────────────────────────────────────

    /** 起 worker（只在需要时；失败就是配置错误 ⇒ Fatal） */
    private function spawn(): bool
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

            return false;
        }

        // ★ stdout / stderr 必须非阻塞：`drain()` 每帧只看一眼，绝不等待。
        //   stdin 保持阻塞写（在途最多 1 条 ⇒ 管道永远不会写满，见类注释的"串行"）。
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->worker = ['proc' => $proc, 'in' => $pipes[0], 'out' => $pipes[1], 'err' => $pipes[2]];
        $this->buffer = '';
        $this->stderr = '';

        return true;
    }

    /** 把队首的任务写出去（串行：在途非空就不发） */
    private function flush(): void
    {
        if ($this->queue === [] || $this->inflight !== []) {
            return;
        }

        if ($this->worker === null && !$this->spawn()) {
            return; // 起不来：留在队列里，下帧再试（Errors 已经报过）
        }

        $worker = $this->worker;
        if ($worker === null) {
            return;
        }

        $item = array_shift($this->queue);
        $written = @fwrite($worker['in'], $item['line'] . "\n");
        if ($written === false || $written === 0) {
            // 管道断了：放回队首，让 `reap()` 在下一帧把它转成错误并重启
            array_unshift($this->queue, $item);

            return;
        }
        @fflush($worker['in']);

        $this->inflight[$item['id']] = ['onDone' => $item['onDone'], 'onError' => $item['onError']];
    }

    /** 非阻塞读回复（按 `\n` 切行，余下的留到下一帧） */
    private function readReplies(callable $onEach): void
    {
        $worker = $this->worker;
        if ($worker === null) {
            return;
        }

        // stderr 也要读：不读的话管道满了 worker 会阻塞在 warning 上（真正的死锁来源）
        $errRead = [$worker['err']];
        $noWrite = [];
        $noExcept = [];
        if ((int) @stream_select($errRead, $noWrite, $noExcept, 0) > 0) {
            $chunk = fread($worker['err'], self::READ_CHUNK);
            if (is_string($chunk)) {
                $this->stderr .= $chunk;
            }
        }

        $read = [$worker['out']];
        $write = [];
        $except = [];
        if ((int) @stream_select($read, $write, $except, 0) === 0) {
            return;
        }

        $chunk = fread($worker['out'], self::READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            return;
        }

        $this->buffer .= $chunk;

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);
            $this->deliver($line, $onEach);
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
     * 检查 worker 是否还活着；死了就把在途任务转成错误并准备重启。
     *
     * ★ **崩溃隔离就体现在这里**：worker 崩了，UI 只是收到一个 `onError`，
     *   下一帧 `flush()` 会自动起一个新的（任务本身不重试 —— 重试策略归业务）。
     */
    private function reap(callable $onEach): void
    {
        $worker = $this->worker;
        if ($worker === null) {
            return;
        }

        $status = proc_get_status($worker['proc']);
        if ($status['running']) {
            return;
        }

        $message = '后台进程退出（exit=' . (string) $status['exitcode'] . '）';
        if (trim($this->stderr) !== '') {
            $message .= '：' . trim($this->stderr);
        }

        foreach ($this->inflight as $item) {
            $onEach(new Completion(null, new \RuntimeException($message), $item['onDone'], $item['onError']));
        }
        $this->inflight = [];

        // 未发出去的任务不受影响（它们会由下一次 flush 起新 worker 后执行）
        @fclose($worker['in']);
        @fclose($worker['out']);
        @fclose($worker['err']);
        @proc_close($worker['proc']);

        $this->worker = null;
        $this->buffer = '';
        $this->stderr = '';
    }
}
