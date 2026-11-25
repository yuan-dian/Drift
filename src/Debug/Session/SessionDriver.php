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
     * ## 为什么时刻要由调用方给
     *
     * 副作用发生在**帧外**，而"帧外"跨着两帧，取哪一端是两个不同的值。原来记录
     * 一律用"上一帧的 `t`"（`lastT`），可脚本里写的是"下一帧的 `t`"——于是同一份
     * 驱动在被录下来之后，脚本指纹就变了，任何"源脚本与录制脚本相同"的判据恒红，
     * 而红的理由是对的：脚本里存了一个执行时从没人读过的时间。
     *
     * 现在由驱动方给：重放时给脚本里的 `t`，与输入同一套口径（都在下一帧被消费，
     * 就都记下一帧的时刻）；调用方不给（测试、直接点击效果）才退回 `lastT`。
     *
     * @param array<string, scalar|null> $args
     * @param float|null $timestampMs 这次副作用算在哪一刻；`null` = 用最近一帧的时刻
     */
    public function effect(string $name, array $args = [], ?float $timestampMs = null): mixed;

    /**
     * 高亮一个节点（`0` = 清除）。**不改结构**：只让下一次绘制多画一层描边。
     *
     * ## 为什么这是"驱动"而不是"查询"
     *
     * 它改的不是记录，是**下一次绘制的样子**。`query('highlight')` 读的是"现在
     * 高亮了谁"；"让它高亮"是一次推进 —— 与 `pump()` / `send()` / `effect()` 同类。
     * 把它放进查询面，等于允许"问一个问题顺手改了画面"。
     *
     * ## 为什么时刻口径与 `effect()` 一致
     *
     * 两者都发生在**帧外**：请求落在第 K 帧与第 K+1 帧之间，高亮真正上屏在 K+1。
     * 由驱动方给时刻（重放时给脚本里的那个），脚本才可能"存盘再读"之后逐值相同。
     *
     * @param int $node 节点号（`Recorder` 发的号）；`0` 清除
     * @param float|null $timestampMs 这次请求算在哪一刻；`null` = 用最近一帧的时刻
     *
     * @throws \InvalidArgumentException 节点号为负
     */
    public function highlight(int $node, ?float $timestampMs = null): void;

    /** 最近一次推进产生的帧号；一帧都没推时为 0。 */
    public function lastFrameId(): int;
}
