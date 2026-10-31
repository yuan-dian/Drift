<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;

use function drift_io_handled;
use function drift_io_poll;
use function drift_io_start;
use function drift_io_stop;
use function drift_io_submit;
use function drift_io_threads;

/**
 * `TaskHost` 后端 C —— **C++ 原生 IO 线程池**（A2 / N5）。
 *
 * ```
 *  UI 线程（PHP）                        C++ 池（core/io_pool.cc）
 *  ┌───────────────────┐  drift_io_submit │  ┌──────────────────────────┐
 *  │ submit(NamedJob)  │ ────────────────►│  │ worker × N（std::thread） │
 *  │ drain()（每帧）    │ ◄────────────────│  │ 只做原生 IO，不碰 Zend VM  │
 *  └───────────────────┘  drift_io_poll   │  └──────────┬───────────────┘
 *         ▲                                          │ 入完成队列 + 叫醒
 *         └────── WakeReason::Woken ◄────────────────┘（win32/io_wake.cc）
 * ```
 *
 * ★★ **它不跑 PHP 代码** —— 这是与 `ProcessTaskHost` 的分界线，也是它能存在的原因
 *   （`probes/RESULTS.md` §11 探针 K：AOT 生成的代码在非 RINIT 线程上访问
 *   类/对象/属性会 SIGSEGV，单个 worker 也一样）。能跨进池里的只有
 *   **"名字 + 两个标量参数"**，处理函数住在 C++ 里。
 *   ⇒ 今天 C++ 只注册了两个（`'sleep'` / `'echo'`），**都只是量具**：真 IO
 *     （HTTP / 文件 / DNS）落地时往 `core/io_pool.cc` 的 `worker()` 里加分支。
 *
 * ★★ **`needsPolling() === false` —— 它是第一个能"真睡着"的后端**：
 *   在途时帧时钟给 `waitEvents(-1)`，worker 干完用 `PostMessageW` 叫醒泵
 *   （`WakeReason::Woken`）。⇒ 一条 600ms 的任务从 **40 次空唤醒** 降到 **0 次**。
 *   ★ 这是"说 `false` 就必须真能叫醒"的那一半：叫不醒 = 挂死，所以
 *     `win32/io_wake.cc` 与它是**同一个契约**，不能分开交付。
 *
 * ★ **取消只有"不交付"一档**：任务已经交给 C++ worker 了，摘不出来（不像
 *   `ProcessTaskHost` 还能摘"没发出去的排队项"）。⇒ 钩子只标记，`drain()` 拿到
 *   结果时看 `isCancelled()` 决定交不交。★ **但记账不减**（`pending()` 仍算它）：
 *   掉到 0 帧时钟就睡死，那条结果会一直躺在完成队列里 —— 取消影响"交付"，
 *   不影响"记账"（与 `ProcessTaskHost` 同一条裁决）。
 *
 * ★ **平台依赖**：本类调的是 `drift_io_*`，它们的实现在 `cpp-src/win32/`（唤醒
 *   通道）。接第二个平台时只写它自己的 `io_wake`，**本类一行不改**。
 *
 * @api
 */
final class ThreadTaskHost implements TaskHost
{
    /**
     * 已发出、还没回来的任务。
     *
     * ★ 值直接存 `Future`（不像 `ProcessTaskHost` 要记 worker 索引）：C++ 池是
     *   一个共享队列，没有"这条记在哪个 worker 名下"这回事。
     *
     * @var array<int, Future>
     */
    private array $inflight = [];

    /**
     * **当场就失败**的任务（不是 `NamedJob` / 参数转不了）。
     *
     * ★ 不在这里直接 `reject()`：`submit()` 返回之后调用方才挂 `catch()`，
     *   当场结算会让"刚提交就 Fatal"。入这个队列，由 `drain()` 在**帧内**结算 ——
     *   与真任务失败走同一条时序（与 `ProcessTaskHost` 同一手法）。
     * ★ 交付算法（至少一条 / 剩余回队头 / 回调抛异常也不丢）在
     *   {@see CompletionQueue} 里，三个后端共用那一份（2026-10-31）。
     */
    private CompletionQueue $failed;

    /**
     * 上一帧**已从原生池取出、但没来得及交付**的完成事件（P1 预算的副产物）。
     *
     * ★ 为什么必须自己存，而不像 `SyncTaskHost` 那样"放回队列"：`drift_io_poll()`
     *   是**取走**语义 —— 它把 IO 池的完成队列搬空后交给 PHP，原生侧没有"放回去"
     *   的接口（再投一条完成事件也没有意义：任务已经跑完了）。
     *   ⇒ 剩余只能由本类保管，下一帧**先交它、再问池子要新的**（顺序不能反：
     *   积压里的结果完成的更早）。
     *
     * ★★ **2026-10-31：从 `list<array>` 换成 {@see CompletionQueue}**。原先这里
     *   是一个 `array` + 一个私有 `deliverRows()`，而那个方法**逐字重写了
     *   `CompletionQueue::drain()` 的三条契约**（首条无条件放行 / 剩余回队头 /
     *   回调抛异常也不丢）—— 第七轮收掉三份交付循环时漏下的第四份。
     *   换过来之后：积压与"新批次"进**同一个队列**，顺序由 `push()` 的位置保证
     *   （旧的在前），预算与 `finally` 只有一份实现。
     *
     * ★ 记账：行从原生池取出时就 `unset($this->inflight[$id])` 并压进本队列 ⇒
     *   `pending()` 把两者相加，**总数不变**（"记账晚一步"变成"记账搬一步"）。
     */
    private CompletionQueue $backlog;

    /** 任务号（只增不减；结果行里的 `id` 原样来回，靠它对账） */
    private int $seq = 0;

    /** 池起了没（懒启动：第一条任务才建线程 + 唤醒窗） */
    private bool $started = false;

    /**
     * @param int $threads 池上限（1–16；IO 密集用 2–4 就够，再多只是抢 CPU）
     *
     * @api
     */
    public function __construct(private readonly int $threads = 4)
    {
        $this->failed = new CompletionQueue();
        $this->backlog = new CompletionQueue();
    }

    public function submit(Job $job): Future
    {
        $id = ++$this->seq;

        // ★ 钩子只标记（`Future::isCancelled()` 自己就是那个标记），
        //   **不从 `$inflight` 摘** —— 见类注释"记账不减"。
        $future = Future::pending();
        $this->inflight[$id] = $future;

        if (!$job instanceof NamedJob) {
            $this->fail($id, $future, 'ThreadTaskHost 只能跑 NamedJob —— ' . get_class($job)
                . '（跨线程只传"名字 + 两个标量"，对象过不去）');

            return $future;
        }

        if (!$this->ensureStarted()) {
            $this->fail($id, $future, 'ThreadTaskHost：IO 池起不来（建唤醒窗失败）');

            return $future;
        }

        // ★ `intArg` / `strArg` 是 C ABI 只有的两个标量槽，**由处理函数自己解释**。
        //   今天两个 handler 的约定：`sleep` 读 `ms`，`echo` 读 `text`。
        $args = $job->args();

        $ok = drift_io_submit(
            $id,
            $job->name(),
            (int) ($args['ms'] ?? 0),
            (string) ($args['text'] ?? ''),
        );

        if (!$ok) {
            $this->fail($id, $future, 'ThreadTaskHost：投递失败 —— ' . $job->name());

            return $future;
        }

        return $future;
    }

    /**
     * 在途 + 排队（帧时钟的判据）。
     *
     * ★ 与 `needsPolling()` 的分工（2026-10-30 P1 修订，别按旧注释理解）：
     *   - `pending()` 回答"**还有没有活**" ⇒ `wantsFrame()` 用它，决定帧循环要不要继续；
     *   - `needsPolling()` 回答"**要不要空转着等**" ⇒ 决定帧时钟给 `-1` 还是帧间隔。
     *   本后端正常态是"有活也能睡"（worker 会 `PostMessageW` 叫醒）；只有**有积压**时
     *   才需要以帧节奏回来续交 —— 见 `needsPolling()`。
     *
     * ★ 积压（`$this->backlog`）**必须**在这里加上（2026-10-31）：行从原生池取出时
     *   就出了 `$inflight`（结果已经在手上，不再"在途"）⇒ 不加这一项，交不完的那批
     *   会让 `pending()` 掉到 0 ⇒ `hasAsync()` 假 ⇒ 帧循环停 ⇒ **积压永远交不出去**。
     *   加完之后总数与改造前一致（`inflight` 减几，`backlog` 就加几）。
     */
    public function pending(): int
    {
        return \count($this->inflight) + $this->failed->count() + $this->backlog->count();
    }

    /**
     * `false` = **有活在途也无限期阻塞**，等 worker `PostMessageW` 叫醒。
     *
     * ★ 这是本后端存在的意义（零轮询）。★ 前提是 `win32/io_wake.cc` 真的能叫醒 ——
     *   两者是同一个契约（见类注释）。
     *
     * ★★ **有积压时返回 `true`**（2026-10-30，P1 配套）：`drain()` 按预算交付时，
     *   一批结果可能交不完 ⇒ 剩下的进 `$this->backlog`。而**原生池不会再叫醒我们**
     *   （它认为自己已经把这一批交付完了）。此时若仍返回 `false` ⇒ `hasPolledAsync()`
     *   ⇒ `false` ⇒ 帧时钟给 `-1` ⇒ 无限期阻塞等一个不会来的唤醒 ⇒ **积压永远交不出去**
     *   （死锁，现象是"界面停在半截"）。⇒ 有积压时必须以帧节奏回来续交 ——
     *   这不是"能力变了"，是"这一批还没完"。
     */
    public function needsPolling(): bool
    {
        return !$this->backlog->isEmpty();
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
        // ★ 预算基准点只取一次：failed / 积压 / 新批次三段共用（否则是预算的三倍）。
        $deadline = CompletionQueue::deadline($budgetMs);

        // ★ 发不出去的那些**先结算**：它们比在途的更早（`submit()` 时就定了）。
        // ★ 超预算之后仍然往下走（积压 / 新批次），不是 `return` —— 那两段用的是
        //   另一套残留载体（原生池取走即无、只能由本类记账），契约①在那边同样适用。
        $this->failed->drain($onEach, $deadline);

        // ① 先交**上一帧的积压** —— 它们完成的更早，顺序必须优先于新批次。
        //   ★ 交不完 ⇒ 这一帧到此为止（原生池那边不用问，也不该问：这一批还没完）。
        if (!$this->backlog->drain($onEach, $deadline)) {
            return;
        }

        if (!$this->started) {
            return;
        }

        $raw = drift_io_poll();
        if ($raw === '[]') {
            return;   // 常见路径：不解析
        }

        $rows = json_decode($raw, true);
        if (!\is_array($rows)) {
            // ★ 解析失败**不能静默**：完成队列已被 `poll()` 取走，这批结果没了。
            //   走 Fatal 序列（S3 无边界），与"后台回调抛异常"同一条路。
            throw new \RuntimeException('ThreadTaskHost：IO 池回包不是 JSON —— ' . $raw);
        }

        // ② 新批次压到**队尾**（积压可能还有，但上面那一句已经保证它是空的）⇒
        //   同一个 `drain()` 交付，顺序 = 完成顺序。
        foreach ($rows as $row) {
            $completion = $this->completionFor($row);

            if ($completion !== null) {
                $this->backlog->push($completion);
            }
        }

        $this->backlog->drain($onEach, $deadline);
    }

    /**
     * 一行原生回包 → 一条待交付的完成事件（`null` = 不必交）。
     *
     * ★ **记账与"交不交"是两件事，顺序不能反**：先 `unset($this->inflight[$id])`
     *   （这一条已经有结果了），再看 `isCancelled()`。反过来的话，被取消的任务会
     *   永远留在 `$inflight` 里 ⇒ `pending()` 永不为 0 ⇒ 帧时钟永远空转。
     *
     * ★ 交付本身不在这里做 —— 预算与"剩余怎么办"全交给
     *   {@see CompletionQueue::drain()}（2026-10-31 之前本类自己抄了一份，
     *   见 `$backlog` 的注释）。
     *
     * @param array<string, mixed> $row
     */
    private function completionFor(array $row): ?Completion
    {
        $id = (int) ($row['id'] ?? 0);
        $future = $this->inflight[$id] ?? null;

        if ($future === null) {
            return null;   // 不该发生（号只增不减）；防御，不抛
        }

        unset($this->inflight[$id]);

        // ★ 取消 = "不交付"，不是"没发生"。清完账再判，顺序不能反。
        if ($future->isCancelled()) {
            return null;
        }

        if (!empty($row['ok'])) {
            return new Completion($row['value'] ?? null, null, $future);
        }

        return new Completion(
            null,
            new \RuntimeException((string) ($row['error'] ?? 'IO 处理函数未给出错误信息')),
            $future,
        );
    }

    /**
     * 关池：join 全部 worker、销毁唤醒窗。**幂等**。
     *
     * ★ 不调也不会崩（`core/io_pool.cc` 注册了 `atexit` 兜底），但那是"退出时才收"；
     *   多窗口下"所有窗关完"是一个明确的时刻，早收早干净。
     *
     * @api
     */
    public function shutdown(): void
    {
        if (!$this->started) {
            return;
        }

        $this->started = false;
        drift_io_stop();
    }

    /** 池里有几个 worker（测试与 devtools 用；关池后必须是 0） */
    public function workerCount(): int
    {
        return drift_io_threads();
    }

    /** C++ 侧累计执行条数（"0 丢失"判据的分子：它必须等于提交数） */
    public function handledCount(): int
    {
        return drift_io_handled();
    }

    /** 懒启动（第一条任务才建窗 + 起线程；失败只试一次，不每帧重试） */
    private function ensureStarted(): bool
    {
        if ($this->started) {
            return true;
        }

        $this->started = drift_io_start($this->threads) > 0;

        return $this->started;
    }

    private function fail(int $id, Future $future, string $message): void
    {
        // ★ 从在途表里摘掉：它永远不会有结果行回来（`pending()` 不能一直算它）。
        unset($this->inflight[$id]);

        $this->failed->push(new Completion(null, new \RuntimeException($message), $future));
    }
}
