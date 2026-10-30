<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;

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
     * 已完成、等 UI 线程取走的结果。
     *
     * ★ 纯 PHP 数组即可：`submit()` 与 `drain()` 都在 UI 线程上（本后端没有第二
     *   个线程可竞争）。线程后端才需要 `mutex + deque`（§17.4.2.1 的表）。
     *
     * @var list<Completion>
     */
    private array $queue = [];

    public function submit(Job $job): Future
    {
        $future = Future::pending();

        // ★ `run()` 的异常**在这里就被接住**，变成 `Completion` 的 error 通道 ——
        //   不是"吞掉"：它有去处（`Future::catch()`，或结算时的 Fatal 兜底）。
        //   若让它冒出去，`submit()` 的调用点（往往是 Store 的一个方法）就得处理
        //   后台错误，端口的意义就没了。
        try {
            $this->queue[] = new Completion($job->run(), null, $future);
        } catch (\Throwable $e) {
            $this->queue[] = new Completion(null, $e, $future);
        }

        return $future;
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
        return \count($this->queue);
    }

    public function drain(callable $onEach): void
    {
        if ($this->queue === []) {
            return;
        }

        // ★ swap：取快照再清空。`$onEach` 里再 `submit()` 的结果留到下一帧，
        //   否则"回调里提交新任务"会让 drain 永不返回（帧预算失效）。
        $ready = $this->queue;
        $this->queue = [];

        foreach ($ready as $completion) {
            $onEach($completion);
        }
    }
}
