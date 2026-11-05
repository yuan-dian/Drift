<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;
use Drift\Errors;

/**
 * `TaskHost` 后端 B —— **同步执行 + 队列回贴**（§17.2.3，M1–M3 默认，永远可用）。
 *
 * ★ **它是"没有后台线程的后端"**：`run()` 就在**调用者线程**上跑完，结果**入队**；
 *   回调由 `drain()` 在 UI 线程的下一帧取出执行 —— "回调在 UI 线程"因此
 *   **平凡成立**（两个动作都在同一个线程上）。
 *
 * ★★ **`run()` 仍同步**（这就是它的诚实边界）：大响应时会**短暂停顿**，
 *   `loading` 那一态**对用户不可见**。这是"同步直跑"的结构后果，**不要**靠
 *   "先广播一次 `Loading`"来假装异步 —— 那只会让状态机撒谎（§11.5 同结论）。
 *
 * ★ **为什么仍然要队列**（2026-10-18，§17.4.2）：同步直调 `$onDone` 会让"回调在
 *   `submit()` 的调用栈上"成为**后端私有事实**；一旦换成真异步后端，`submit()`
 *   之后到回调之间就多出了"别的代码可能插进来"的窗口 —— 业务代码若依赖"提交完
 *   立刻拿到结果"就会在换后端那天静默出错。队列把这条**语义**统一掉：所有后端
 *   都是"提交 → 某一帧拿到结果"，差别只剩**快慢**。
 *   ⇒ **不许**把它改回直调（那是 2026-10-18 之前的旧语义，理由见 §17.4.2）。
 *
 * ★ **换后端是组合根一行的事**（§17.2.3）：
 *   ```php
 *   $src = new HttpUserSource(new SyncTaskHost());       // M1–M3：同步
 *   $src = new HttpUserSource(new ProcessTaskHost(...)); // M4+：常驻子进程池（§17.4.5）
 *   ```
 *   ⇒ 本类存在的意义是**把端口定下来**（页面 / Store / `Job` 零改动），不是"够用了"。
 *
 * @api
 */
final class SyncTaskHost implements TaskHost
{
    /**
     * 同步执行超过这个时长就报一条诊断（毫秒；约三帧，明显掉帧的量级）。
     *
     * ★ 为什么是"用时才报"而不是"启动时警告"（2026-10-30 P3）：启动期警告会对
     *   **每个**没装后端的应用白报一次，而其中大多数根本没跑过慢任务 —— 噪音多了
     *   就被无视（狼来了）。Android 的 `NetworkOnMainThreadException` 也是"用的时候
     *   才炸"，不是"装的时候说"。这里的判据是**事实**（真跑了 50ms），不是**猜测**。
     *
     * ★ 阈值 50ms 的来历：一帧 16.7ms，50ms ≈ 连掉三帧 —— 低于这个量的同步任务
     *   用户看不出来，报它只是噪音。
     */
    private const SLOW_MS = 50;

    /**
     * 已完成、等 UI 线程取走的结果。
     *
     * ★ 纯 PHP 对象即可（无锁）：`submit()` 与 `drain()` 都在 UI 线程上（本后端
     *   没有第二个线程可竞争）。线程后端才需要 `mutex + deque`（§17.4.2.1 的表）。
     * ★ **交付算法（至少一条 / 剩余回队头 / 不丢）在 `CompletionQueue` 里** ——
     *   三个后端共用那一份，不在本类里再写一遍（2026-10-31）。
     */
    private CompletionQueue $queue;

    /** 慢任务诊断只报一次（否则每个慢请求一条，日志被刷满就没人看） */
    private bool $slowReported = false;

    public function __construct()
    {
        $this->queue = new CompletionQueue();
    }

    public function submit(Job $job): Future
    {
        $future = Future::pending();

        // ★ `run()` 的异常**在这里就被接住**，变成 `Completion` 的 error 通道 ——
        //   不是"吞掉"：它有去处（`Future::catch()`，或结算时的 Fatal 兜底）。
        //   若让它冒出去，`submit()` 的调用点（往往是 Store 的一个方法）就得处理
        //   后台错误，端口的意义就没了。
        $startedAt = \hrtime(true);

        try {
            $this->queue->push(new Completion($job->run(), null, $future));
        } catch (\Throwable $e) {
            $this->queue->push(new Completion(null, $e, $future));
        }

        $this->reportIfSlow($job, (\hrtime(true) - $startedAt) / 1_000_000);

        return $future;
    }

    /**
     * 同步后端跑了慢任务 ⇒ 报一条诊断（**只报一次**）。
     *
     * ★ 这条诊断存在的理由：`SyncTaskHost` 的诚实边界是"大响应时会短暂停顿"
     *   （见类注释），而停顿在真机上就是**窗口拖不动**。但它是默认后端 ⇒
     *   谁都不会主动换掉它。日志里没有痕迹，问题就永远归因到"这软件慢"。
     *   ★ DIAGNOSTIC 不是错误：`Errors` 只记一笔，不改变执行流（框架不能让
     *   "没装配后端"直接崩 —— 端口承诺永远可用）。
     */
    private function reportIfSlow(Job $job, float $costMs): void
    {
        if ($this->slowReported || $costMs < self::SLOW_MS) {
            return;
        }

        $this->slowReported = true;

        Errors::report(sprintf(
            'SyncTaskHost：任务在 **UI 线程**跑了 %dms（%s）—— 这一帧被它卡住了。'
            . '组合根用 AppScope::setTaskHost(new ProcessTaskHost(...)) 换掉同步后端；'
            . '本诊断只报一次。',
            (int) round($costMs),
            \get_class($job),
        ), Errors::DIAGNOSTIC);
    }

    /**
     * 已完成、但还没被 `drain()` 取走的结果数。
     *
     * ★ 本后端 `submit()` **同步跑完** ⇒ 这里永远只有"已就绪未回贴"，没有在途。
     *   于是帧时钟只在"有结果等着回贴"时短暂跳一帧，其余时间 UI 线程照样阻塞休眠
     *   —— 同步后端不会让应用变成空转。
     */
    public function pending(): int
    {
        return $this->queue->count();
    }

    /**
     * 本后端**必须**靠每帧 `drain()` 拉 —— 它没有第二个线程可以叫醒 UI。
     *
     * ★ 但它并不因此空转：`submit()` 是同步跑完的 ⇒ `pending()` 只在"结果已就绪、
     *   还没回贴"的那一两帧里大于 0（见上），其余时间帧时钟照样无限期睡。
     *   ⇒ `true` 在这里的代价是**有界的一次**，不是每帧空转的常态。
     */
    public function needsPolling(): bool
    {
        return true;
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
        if ($this->queue->isEmpty()) {
            return;
        }

        // ★ 预算基准点只取一次（三段共用，否则是预算的三倍）；换算式见
        //   `CompletionQueue::deadline()`（三个后端同一处）。
        $deadline = CompletionQueue::deadline($budgetMs);

        // ★ 三条规则（至少交一条 / 剩余回队头 / 回调抛异常也不丢）全在
        //   `CompletionQueue::drain()` —— 三个后端共用**同一份**算法。
        $this->queue->drain($onEach, $deadline);
    }
}
