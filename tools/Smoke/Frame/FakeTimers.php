<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Frame;

use Drift\Platform\TimerHost;

/**
 * @internal 冒烟夹具：**不碰系统**的定时器。
 *
 * `FrameClock` 要一个 `TimerHost` 才能跑（`pace()` 里 `start`/`stop`），而真实现
 * 在 cpp-src 里（`SetTimer`）。这个夹具记下调用序列，于是"帧时钟什么时候上膛、
 * 什么时候停"从外面看得见 —— 那正是 `FrameClock` 唯一的对外行为。
 */
final class FakeTimers implements TimerHost
{
    /** @var list<array{0: string, 1: int, 2: int}> `[start|stop, id, ms]` */
    public array $calls = [];

    /** 下一次 `start()` 是否被"平台"拒绝（验 `FrameClock` 的响亮失败） */
    public bool $reject = false;

    public function start(int $id, int $milliseconds): bool
    {
        $this->calls[] = ['start', $id, $milliseconds];

        return !$this->reject;
    }

    public function stop(int $id): void
    {
        $this->calls[] = ['stop', $id, 0];
    }
}
