<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\DevTools\Protocol\Channel;
use Drift\DevTools\Protocol\Server;

/**
 * 会"立即返回空"的服务：给自走宿主做测试替身。
 *
 * ## 为什么 `SingleServer` 不够
 *
 * `SingleServer::wait()` 永远把那条通道交回去，于是 `Host::pollOnce()` 的
 * `$ready === []` 分支根本走不到 —— 而自走宿主**只**在那个分支里推帧
 * （`Drive::advance()`）。拿 `SingleServer` 测自走，测到的是"通道被反复读空"。
 *
 * ## 它替的是 `SocketServer` 的哪一半
 *
 * `canPoll()` 为 true，`wait(0)` 只回**当下真可读**的通道。accept 新连接那一半
 * 不在这里：测试手上就一条通道，没有"谁来连"这回事。
 *
 * @internal 测试专用
 */
final class PollingServer implements Server
{
    public function __construct(private readonly Channel $channel)
    {
    }

    /** @param list<Channel> $channels */
    public function wait(array $channels, int $timeoutMs): array
    {
        // 宿主从空数组起步：那时它还没"认识"任何连接，把手上这条交出去让它登记。
        $known = $channels === [] ? [$this->channel] : $channels;

        $ready = [];
        foreach ($known as $channel) {
            if ($channel->readable($timeoutMs)) {
                $ready[] = $channel;
            }
        }

        return $ready;
    }

    public function canPoll(): bool
    {
        return true;
    }

    public function close(): void
    {
        $this->channel->close();
    }
}
