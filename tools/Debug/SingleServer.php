<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\DevTools\Protocol\Channel;
use Drift\DevTools\Protocol\Server;

/**
 * 只有一条连接的服务：`--remote` 走的那条 stdio。
 *
 * `wait()` 不做任何检测，直接把那条通道交回去——因为 stdio 的两端是一条管道，
 * 管道上**没有"可读"这个概念可问**（Windows 的匿名管道连 `stream_select` 都不支持）。
 * 交给调用方之后 `read()` 自己会阻塞，这正是"从属宿主"需要的行为。
 *
 * 代价是不能自走：{@see self::canPoll()} 返回 false，有 tick 的宿主会当场拒绝，
 * 而不是卡在等消息上、看起来像"帧不动了"。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class SingleServer implements Server
{
    public function __construct(private readonly Channel $channel)
    {
    }

    /** @param list<Channel> $channels */
    public function wait(array $channels, int $timeoutMs): array
    {
        // 空的时候把这条交出去（宿主从空数组起步）；之后一直返回它自己。
        return $channels === [] ? [$this->channel] : $channels;
    }

    public function canPoll(): bool
    {
        return false;
    }

    public function close(): void
    {
        $this->channel->close();
    }
}
