<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * `cancel()` 到底能停到多深 —— 后端的**能力声明**（不是实现细节）。
 *
 * ## 为什么要有它
 *
 * `Future::cancel()` 的文档写的是"尽力而为"，而"尽力"在各后端差得很远：
 * 进程池能把还在排队的那条摘掉，线程池一步都停不了，同步后端根本没有在跑的活。
 * 在这份声明出现之前，这个差异只活在一份 2026-10-30 的分析稿里 —— 应用侧想知道
 * "按了停止之后后台还在不在烧 CPU"，只能去读三个后端的实现。
 *
 * ★ 与渲染侧 `CanvasCapabilities` 是**同一条设计语言**：能力位只回答"能不能"，
 *   不回答"做得怎么样"；上层据此决定要不要给用户看这个按钮、要不要说"仍在后台运行"。
 * ★ 三档**按"能省多少活"排序**（`value` 递增），所以可以直接比较：判据用
 *   `rank()` 断言"文档表与实现一致"，不必逐个 `===`。
 *
 * @api
 */
enum CancelSupport: int
{
    /**
     * 没有后台活在跑。
     *
     * ★ 同步后端（`SyncTaskHost`）：`submit()` 返回时 `Job::run()` 已经跑完，
     *   结果是"已就绪、等交付"。取消只是"别交这个结果"，CPU 上本来就没活。
     *   这不是"不支持取消"，是**没有对象可取消**。
     */
    case AlreadyDone = 0;

    /**
     * 活已经在别处跑，取消只能"结果不要了"。
     *
     * ★ 线程后端（`ThreadTaskHost`）：任务交给 C++ worker 之后就摘不回来。
     *   取消省不到 CPU，买到的是"迟到的结果不会打到界面上"。
     */
    case DetachedOnly = 1;

    /**
     * **排队中**的那部分能真的撤掉，已发给 worker 的只能"不交付"。
     *
     * ★ 进程后端（`ProcessTaskHost`）：任务还在队列里没派发 ⇒ 直接从队列摘掉，
     *   那条永远不会开始。这是三档里唯一"取消能省 CPU"的情况。
     */
    case QueuedOnly = 2;

    /** 取消能不能让**还没开始**的那部分真的不跑（= 能不能省 CPU） */
    public function revokesQueued(): bool
    {
        return $this === self::QueuedOnly;
    }

    /**
     * 给用户 / 日志看的一句话。
     *
     * ★ 界面想显示"停止后任务仍在后台运行"时，直接用这句，不要自己按档位
     *   再拼一遍文案 —— 那样加一档就要改两处。
     */
    public function describe(): string
    {
        if ($this === self::AlreadyDone) {
            return '任务已执行完，取消只是不再显示结果';
        }
        if ($this === self::DetachedOnly) {
            return '任务已在后台开始，取消只是不再交付结果（仍会跑完）';
        }

        return '排队中的任务会被取消，已开始的会跑完但不再交付结果';
    }
}
