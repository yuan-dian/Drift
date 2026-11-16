<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Failure;
use Drift\Async\Future;

use function drift_io_handled;
use function drift_io_poll;
use function drift_io_start;
use function drift_io_stop;
use function drift_io_submit;
use function drift_io_threads;

/**
 * `TaskHost` 后端 C —— C++ 原生 IO 线程池。
 * 只做原生 IO，不跑 PHP 代码（与 `ProcessTaskHost` 的分界）：能跨进池的只有"名字 + 两个标量参数"。
 * `needsPolling()` 返回 false：有活在途也能真睡着，靠 worker 的 `PostMessageW` 叫醒。
 * ★ 叫不醒 = 挂死：本类与 `win32/io_wake.cc` 是同一份契约，不能分开交付。
 * ★ 取消只有"不交付"一档：任务已交 C++ worker 摘不回来，钩子只标记；`drain()` 按 `isCancelled()` 决定交不交，但记账不减（`pending()` 仍算它），否则帧时钟掉到 0 睡死、结果永远躺在完成队列。
 * @api
 */
final class ThreadTaskHost implements TaskHost
{
    /**
     * 已发出、还没回来的任务。
     * 值直接存 `Future`：C++ 池是共享队列，没有"这条记在哪个 worker 名下"这回事。
     * @var array<int, Future>
     */
    private array $inflight = [];

    /**
     * **当场就失败**的任务（不是 `NamedJob` / 参数转不了）。
     * 不入这里直接 `reject()`：`submit()` 返回后调用方才挂 `catch()`，当场结算会"刚提交就 Fatal"。
     * 入队由 `drain()` 在帧内结算，与真任务失败同一条时序。
     * 交付算法（首条放行 / 剩余回队头 / 回调抛异常也不丢）在 {@see CompletionQueue}，三后端共用。
     */
    private CompletionQueue $failed;

    /**
     * 上一帧已从原生池取出、但没来得及交付的完成事件。
     * `drift_io_poll()` 是**取走**语义：它把 IO 池完成队列搬空交给 PHP，原生侧无"放回去"接口。
     * 剩余只能由本类保管，下一帧先交它、再问池子要新的（顺序不能反：积压完成更早）。
     * 记账：从原生池取出即 `unset($this->inflight[$id])` 并压入本队列 ⇒ `pending()` 把两者相加，总数不变。
     */
    private CompletionQueue $backlog;

    /** 任务号（只增不减；结果行里的 `id` 原样来回，靠它对账） */
    private int $seq = 0;

    /** 池起了没（懒启动：第一条任务才建线程 + 唤醒窗） */
    private bool $started = false;

    /**
     * @param int $threads 池上限（1–16；IO 密集用 2–4 就够，再多只是抢 CPU）
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

        // 钩子只标记（`Future::isCancelled()` 即标记），不从 `$inflight` 摘 —— 见类注释"记账不减"。
        $future = Future::pending();
        $this->inflight[$id] = $future;

        if (!$job instanceof NamedJob) {
            $this->fail($id, $future, 'ThreadTaskHost 只能跑 NamedJob —— ' . get_class($job)
                . '（跨线程只传"名字 + 两个标量"，对象过不去）', Failure::CODE_JOB_REJECTED);

            return $future;
        }

        if (!$this->ensureStarted()) {
            $this->fail($id, $future, 'ThreadTaskHost：IO 池起不来（建唤醒窗失败）', Failure::CODE_HOST_UNAVAILABLE);

            return $future;
        }

        // `intArg` / `strArg` 是 C ABI 仅有的两个标量槽，语义由处理函数自己解释（`sleep` 读 `ms`、`echo` 读 `text`）。
        $args = $job->args();

        $ok = drift_io_submit(
            $id,
            $job->name(),
            (int) ($args['ms'] ?? 0),
            (string) ($args['text'] ?? ''),
        );

        if (!$ok) {
            $this->fail($id, $future, 'ThreadTaskHost：投递失败 —— ' . $job->name(), Failure::CODE_HOST_UNAVAILABLE);

            return $future;
        }

        return $future;
    }

    /**
     * 只能"不交付"：任务交给 C++ worker 之后就摘不回来，取消省不到 CPU。
     *
     * ★ 与类注释那条"取消只有不交付一档"是同一句话，现在它是**可查询**的。
     */
    public function cancelSupport(): CancelSupport
    {
        return CancelSupport::DetachedOnly;
    }

    /**
     * 在途 + 排队。`PumpHost::hasPolledAsync()` 读它，帧末 `continueFrame()` 据此
     * `scheduleNextFrame()`；`needsPolling()` 回答"要不要空转着等"，决定帧时钟给
     * `-1` 还是帧间隔；本态正常可睡，只有有积压才以帧节奏回来续交。
     * 积压必须计入：从原生池取出即出 `$inflight`（结果已在手上），不加这一项则
     * 交不完的批次让 `pending()` 掉 0 ⇒ 帧循环停 ⇒ 积压永远交不出去。
     */
    public function pending(): int
    {
        return \count($this->inflight) + $this->failed->count() + $this->backlog->count();
    }

    /**
     * `false` = 有活在途也无限期阻塞，等 worker `PostMessageW` 叫醒。
     * 有积压时返回 `true`：一批结果可能交不完进 `$this->backlog`，而原生池认为自己已交付完不会再叫醒；此时仍返回 `false` 会无限期阻塞 ⇒ 积压永远交不出去。
     */
    public function needsPolling(): bool
    {
        return !$this->backlog->isEmpty();
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
        // 预算基准点只取一次：failed / 积压 / 新批次三段共用，否则是预算的三倍。
        $deadline = CompletionQueue::deadline($budgetMs);

        // 发不出去的先结算：它们比在途的更早（`submit()` 时就定）。超预算后仍往下走（积压 / 新批次），不是 `return`。
        $this->failed->drain($onEach, $deadline);

        // ① 先交上一帧的积压：它们完成更早，顺序必须优先于新批次；交不完 ⇒ 这一帧到此为止。
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
            // 解析失败不能静默：完成队列已被 `poll()` 取走，这批结果没了。走 Fatal 序列，与"后台回调抛异常"同路。
            throw new \RuntimeException('ThreadTaskHost：IO 池回包不是 JSON —— ' . $raw);
        }

        // ② 新批次压到队尾：同一个 `drain()` 交付，顺序 = 完成顺序。
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
     * ★ 记账与"交不交"是两件事，顺序不能反：先 `unset($this->inflight[$id])`（这条已有结果），再看 `isCancelled()`。反过来被取消的任务永远留在 `$inflight` ⇒ `pending()` 永不为 0 ⇒ 帧时钟永远空转。
     * 交付本身交给 {@see CompletionQueue::drain()}，预算与"剩余怎么办"都复用那一份。
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

        // 取消 = "不交付"，不是"没发生"。清完账再判，顺序不能反。
        if ($future->isCancelled()) {
            return null;
        }

        if (!empty($row['ok'])) {
            return new Completion($row['value'] ?? null, null, $future);
        }

        return new Completion(
            null,
            new Failure(
                (string) ($row['error'] ?? 'IO 处理函数未给出错误信息'),
                (string) ($row['code'] ?? ''),
            ),
            $future,
        );
    }

    /**
     * 关池：join 全部 worker、销毁唤醒窗。**幂等**。
     * 不调也不会崩（`core/io_pool.cc` 注册 `atexit` 兜底），但那是退出时才收；多窗口"所有窗关完"是明确时刻，早收早干净。
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

    private function fail(int $id, Future $future, string $message, string $code): void
    {
        // 从在途表摘掉：它永远不会有结果行回来，`pending()` 不能一直算它。
        unset($this->inflight[$id]);

        $this->failed->push(new Completion(null, new Failure($message, $code), $future));
    }
}
