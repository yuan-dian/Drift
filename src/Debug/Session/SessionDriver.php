<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use Drift\Input\InputEvent;

/**
 * 会话的**驱动**面：推一帧、喂一条输入、跑一个具名副作用。
 *
 * ## 为什么 {@see \Drift\App\Replay} 要按这个接口写
 *
 * 重放的全部内容是"按脚本顺序推帧、派发输入、执行效果"。这三件事本地能做，
 * 远端也能做 —— 只要它们被收在同一个接口后面，重放循环就只有一份。
 * 否则"本地重放"和"远端重放"会变成两段代码，而两段代码迟早对不上：
 * 一边给 `--at` 判了上限，另一边忘了，报告就会多跑几帧。
 *
 * ## 为什么 `lastFrameId()` 也在这里
 *
 * 重放要给每帧回调"这是第几帧"。帧号是**被驱动方**产生的（本地是录制器、
 * 远端是宿主进程），驱动方不能自己数 —— 它会和"帧被容量裁掉"、"某帧被跳过"
 * 这些事实错开。
 *
 * @api
 */
interface SessionDriver
{
    /** 推进一帧，返回整帧墙钟毫秒（与 `FrameClock` 同口径）。 */
    public function pump(float $timestampMs): float;

    /** 走真实派发链喂一条输入（加速键 → IME → 焦点 → 手势）。 */
    public function send(InputEvent $event, ?float $timestampMs = null): void;

    /**
     * 执行并记下一个具名副作用。
     *
     * 返回值只能是标量或数组：跨进程时它要过一遍 JSON。对象在这里是**编码错误**，
     * 不是"悄悄变成 null"。
     *
     * @param array<string, scalar|null> $args
     */
    public function effect(string $name, array $args = []): mixed;

    /** 最近一次推进产生的帧号；一帧都没推时为 0。 */
    public function lastFrameId(): int;
}
