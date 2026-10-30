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
 *   （[`MULTI-WINDOW-2026-10-30.md`](../../docs/MULTI-WINDOW-2026-10-30.md) M1）。
 * ★ **只有三个 case，不是设计册里的四个**：`Woken`（被 `wake()` 打断）要等唤醒通道
 *   落地（N4）才有生产者。本仓纪律：**未被消费的分支 = 未实现** ⇒ 那时再加。
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

    /** 收到退出（`WM_QUIT` / 窗口被销毁）—— 调用方跳出循环 */
    case Quit;
}
