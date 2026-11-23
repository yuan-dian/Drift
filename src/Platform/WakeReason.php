<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * `Platform::waitEvents()` 的返回原因：必须回答"为什么醒"，不能只返回 bool。
 *
 * 多窗口下"继续"还要按窗口算，bool 表达不了；先有生产者再加 case，
 * 否则会留下一个永远不返回的分支，让人误以为唤醒通道已通。
 *
 * @api platform
 */
enum WakeReason
{
    /** 有 OS 消息（输入 / 尺寸 / 主题 / 系统定时器…）—— 已经取到，等 `pumpEvents()` 派发 */
    case Message;

    /** 等到了 `waitEvents($timeoutMs)` 给的上限 —— 该跑下一帧了 */
    case Timeout;

    /**
     * 跨上下文投递叫醒（IO 线程完成队列）：OS 队列没事件，活在 `drift_io_poll()`。
     * 派发阶段取不到事件，但必须跑帧 —— 取结果发生在 `frame()` 的 `pumpTasks()`。
     * 与 Timeout 不同：Woken 表示"原本还能睡，但别处有活"，要立刻跑。
     */
    case Woken;

    /** 收到退出（`WM_QUIT` / 窗口被销毁）—— 调用方跳出循环 */
    case Quit;
}
