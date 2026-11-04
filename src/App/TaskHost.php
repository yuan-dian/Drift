<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;

/**
 * 后台执行端口 —— 把"别占 UI 线程"收进一个可替换的端口。
 * 端口的存在让 UI 代码零改动：Store/页面只调 `$src->fetchPage()`，换后端是组合根一行。
 *
 * ★ 两条不变量：后端不许直接调 `$onDone`（一律把 Completion 入队，由 drain() 取出）；
 *   drain() 只在 UI 线程的帧内被调用（Application::frame() 的第一段）。
 *
 * @api
 */
interface TaskHost
{
    /**
     * 提交一个后台任务，拿到它的 `Future`。
     * `then()` / `catch()` 一定在 UI 线程的帧内被调用，且不在 submit() 的调用栈上，
     *   所以回调里可以安全地改 Store（settle() 之前同帧生效）。
     * `Job::run()` 可能在 submit() 的调用栈上跑完（SyncTaskHost 是默认后端）；
     *   ⇒ submit() 的耗时取决于后端，同步后端 = 任务完整耗时（慢网络会卡住这一帧）。
     * ★ 结算前必须挂上 `catch()`：没有 catch ⇒ 框架走 Errors::report() 的 Fatal 序列
     *   （任务失败被吞 = 界面永远转圈）。提交与挂回调在同一同步块里，天然满足。
     */
    public function submit(Job $job): Future;

    /**
     * 还有多少条任务没回贴（在途 + 排队）。
     * 返回 0 ⇒ 平台可无限期阻塞（不空转）；>0 ⇒ 帧时钟按 15ms 跳。
     * 这就是"自适应"的全部机制：有活才跳，没活就睡。
     */
    public function pending(): int;

    /**
     * 后端是否必须靠每帧轮询才能拿到结果。
     * ★ 这是"能不能真的睡着"的唯一开关：needsPolling()===false ⇒ 有活在途也无限期阻塞，
     *   等后端自己叫醒；说 false 却叫不醒 = 挂死。所以"我能不能叫醒你"是后端的能力声明。
     */
    public function needsPolling(): bool;

    /**
     * 把已完成的结果逐个交给 `$onEach`（仅 UI 线程，每帧调一次）。
     * swap 语义：取走调用瞬间的快照，drain() 期间新入队的留队列、下一帧处理
     *   ⇒ 回调里再 submit() 不会让本帧无限循环。
     * `$budgetMs` = 这一帧最多花多少毫秒在交付回调上，超了收手、剩余留后端下帧继续。
     *
     * @param callable(Completion): void $onEach 处理单个完成事件（UI 线程执行）
     * @param int|null $budgetMs 本帧交付回调的时间上限（毫秒）；
     *                           `null` = 不限，一次取空（框架路径永远传正数）
     */
    public function drain(callable $onEach, ?int $budgetMs = null): void;
}
