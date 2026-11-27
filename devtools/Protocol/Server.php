<?php
declare(strict_types=1);

namespace Drift\DevTools\Protocol;

/**
 * 连接来源：能"等一会儿，看谁有话要说"。
 *
 * ## 为什么是 `wait()` 而不是 `accept()`
 *
 * 单看名字，`accept()` 是对的——它只能回答"有没有新连接"。但宿主循环真正要问的是
 * 合起来的那件事：**有没有新连接，或者老连接上有没有新消息**。分成两个调用，要么
 * 多一次阻塞（先 accept 再 read，accept 等不到就永远轮不到 read），要么调用方自己
 * 去碰文件描述符做 `select`——而"怎么等"是实现细节，不该漏给 `Host`。
 *
 * ## `$timeoutMs` 的三种取值
 *
 * - `-1`：一直等到有事。**没有 tick 的宿主用这个**，行为与"阻塞读一条"等价。
 * - `0`：立即返回，没有就返回空数组。**有 tick 的宿主用这个**，每帧之间扫一眼有没有
 *   待办消息，没有就继续跑帧。
 * - `>0`：最多等这么久。留给将来的"帧末续跑"。
 *
 * ## 为什么返回 `list<Channel>` 而不是"新连接"与"可读连接"两拨
 *
 * 因为调用方对两者的处理只差一件事：新连接要先校验 token。它靠"这个 channel 不在
 * 我手上"就能判断，不需要第二个返回值。分成两拨反而要求实现记住"上次给了谁"。
 *
 * @api
 */
interface Server
{
    /**
     * 等"有事可做"的通道：新连接进来，或已有连接上来了数据。
     *
     * **`read()` 的语义不受本方法影响**：`wait()` 只回答"哪条可读"，被返回的通道
     * 仍然是"读一条、必要时阻塞"的 {@see Transport}。让 `wait()` 去改阻塞属性会
     * 逼 `read()` 用"空串"表达"暂无数据"，而空串不是一条合法消息——那等于为了
     * 一个实现细节去破一个已经说清楚的契约。
     *
     * @param list<Channel> $channels 已知连接（实现不得改动这个数组）
     * @param int           $timeoutMs `-1` 一直等 / `0` 立即返回 / `>0` 最多等这么久
     *
     * @return list<Channel> 可读的通道，**可能含新接受的连接**
     */
    public function wait(array $channels, int $timeoutMs): array;

    /**
     * 支不支持 `$timeoutMs = 0` 的立即返回。有 tick（自走）的宿主必须有它，
     * 否则"每帧扫一眼待办消息"会退化成"卡在等消息上，帧再也不推进"。
     */
    public function canPoll(): bool;

    /** 停止接受新连接。已接受的通道不受影响（它们有自己的 `close()`）。 */
    public function close(): void;
}
