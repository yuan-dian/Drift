<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 「**为什么醒**」—— `Platform::waitEvents()` 的返回值（§3.4 轴 B1）。
 *
 * 这是本轮把 `pump(): bool` 拆开的关键：老接口把「**等**」「**派发**」「**是否退出**」
 * 三件事揉在一个返回值里，于是它无法表达「等到超时了」和「被后台唤醒了」的区别 ——
 * 而那正是自适应帧时钟、定时器、以及将来的 C++ IO 线程都要靠的信息。
 *
 * ★ **不返回 `bool`**：`true`/`false` 只能回答"继续吗"，回答不了"为什么继续"。
 *   多窗口之后「继续」还要按窗口算（关一个窗 ≠ 退出应用），那时 `bool` 更不够用
 *   （[`MULTI-WINDOW-2026-10-30.md`](../../docs/guides/platform-ports.md) M1）。
 * ★ **四个 case**（2026-10-30 补齐第四个）：`Woken`（被跨上下文投递打断）原本
 *   只写在设计册里、没有生产者 —— 按"未被消费的分支 = 未实现"的纪律当时没加。
 *   A2 的 IO 线程（N5）落地后它有了生产者（`drift_io_*` 的完成队列），才补上。
 *   ★ 这条顺序很重要：**先有生产者，再有分支**。反过来就是留一个永远不会被
 *     返回的 case，下一个人会以为"唤醒通道已经通了"。
 *
 * ```php
 * $why = $platform->waitEvents($app->nextWaitMs());   // -1 = 无限等
 * if ($why === WakeReason::Quit) { break; }
 * $platform->pumpEvents();                            // 把这个唤醒带来的事件投出去
 * ```
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
     * 被**跨上下文的投递**叫醒（A2 的 IO 线程，N5）—— OS 队列里没有事件，
     * 但完成队列里有活要取。
     *
     * ★ 与 `Message` 的差别是"载荷在哪"：`Message` 的事件已经取到、等
     *   `pumpEvents()` 派发；`Woken` **没有事件**，活在后端自己的队列里
     *   （`drift_io_poll()`），派发阶段会一条都取不到 —— 但**帧必须跑**，
     *   因为取结果就发生在 `frame()` 的 `pumpTasks()` 里。
     * ★ 与 `Timeout` 的差别是"要不要立刻"：`Timeout` 说明帧时钟本来就该醒了；
     *   `Woken` 说明"原本可以继续睡，但别处有活" ⇒ 立刻跑。
     */
    case Woken;

    /** 收到退出（`WM_QUIT` / 窗口被销毁）—— 调用方跳出循环 */
    case Quit;
}
