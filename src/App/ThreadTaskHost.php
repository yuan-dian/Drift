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
     *   当场结算会让"刚提交就 Fatal"。入这个表，由 `drain()` 在**帧内**结算 ——
     *   与真任务失败走同一条时序（与 `ProcessTaskHost` 同一手法）。
     *
     * @var list<Completion>
     */
    private array $failed = [];

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
     * ★ 与 `needsPolling() === false` 配合之后，这个数字**不再决定"醒不醒"**
     *   （后端自己会叫醒），只决定 `wantsFrame()` 的真假 —— 后者是"帧循环还该不该
     *   继续"的判据，两者不是一回事。
     */
    public function pending(): int
    {
        return \count($this->inflight) + \count($this->failed);
    }

    /**
     * `false` = **有活在途也无限期阻塞**，等 worker `PostMessageW` 叫醒。
     *
     * ★ 这是本后端存在的意义（零轮询）。★ 前提是 `win32/io_wake.cc` 真的能叫醒 ——
     *   两者是同一个契约（见类注释）。
     */
    public function needsPolling(): bool
    {
        return false;
    }

    public function drain(callable $onEach): void
    {
        // ★ 发不出去的那些**先结算**：它们比在途的更早（`submit()` 时就定了）。
        if ($this->failed !== []) {
            $failed = $this->failed;
            $this->failed = [];

            foreach ($failed as $completion) {
                $onEach($completion);
            }
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

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $future = $this->inflight[$id] ?? null;

            if ($future === null) {
                continue;   // 不该发生（号只增不减）；防御，不抛
            }

            // ★ 无论交不交，**记账要清** —— 这一条已经有结果了。
            unset($this->inflight[$id]);

            // ★ 取消 = "不交付"，不是"没发生"。清完账再判，顺序不能反。
            if ($future->isCancelled()) {
                continue;
            }

            if (!empty($row['ok'])) {
                $onEach(new Completion($row['value'] ?? null, null, $future));

                continue;
            }

            $onEach(new Completion(
                null,
                new \RuntimeException((string) ($row['error'] ?? 'IO 处理函数未给出错误信息')),
                $future,
            ));
        }
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

        $this->failed[] = new Completion(null, new \RuntimeException($message), $future);
    }
}
