<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 主循环里的**协作式调试钩子**（A57）。
 *
 * ## 为什么住 `App`
 *
 * 它要被 `EventLoop` 每轮调用，而 `App` 已获准依赖 `Debug`；反过来让 `Debug` 持有
 * `EventLoop` 会造出环。接口只说主循环需要什么，不认识 socket / 协议 / 发现文件 ——
 * 实现全在 `tools/`（dev-only），AOT 组合根一行不改，产物里没有调试传输。
 *
 * ## 为什么是"协作式"
 *
 * dev 壳是单 ZendVM，没有第二条线程能替我们读记录。真要 attach，只能让帧循环自己
 * 在等待时留一个上界、每轮回头看一眼消息。有桥时等待有界（默认 25ms），没桥时仍是
 * `-1` 无限等 —— 空闲 CPU 归零这条老契约不能因为加功能而丢。
 *
 * @api dev-only 扩展点：由 `dev-entry` 安装实现，只由 `EventLoop` 消费
 */
interface DebugTicker
{
    /**
     * 本轮 `waitEvents()` 最多等多久（毫秒）。
     * `null` = 不改主循环的等待（保持无限等）；调用方取更急的那个值。
     */
    public function waitHintMs(): ?float;

    /** 循环开始前拿到窗口表：桥在这一刻认主、开会话、登记发现文件。 */
    public function bind(array $apps): void;

    /** 等完、派发原生的**之前**：处理一轮调试消息。必须非阻塞。 */
    public function poll(): void;

    /** 这一轮能不能跑帧。`false` = 暂停：消息照收，帧不推。 */
    public function canAdvance(): bool;

    /** 一帧跑完之后：给单步预算记账。 */
    public function afterFrame(): void;

    /** 循环退出时收尾：关会话、销发现文件、关监听。必须幂等。 */
    public function close(): void;
}
