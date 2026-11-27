<?php
declare(strict_types=1);

namespace Drift\DevTools\Host;

use Drift\DevTools\Protocol\Channel;
use Drift\DevTools\Protocol\Server;

/**
 * loopback TCP 监听：绑定 `127.0.0.1`，端口由系统分配（`port = 0`）。
 *
 * ## 为什么不用固定端口
 *
 * 固定端口撞车时，报错会是 `Address already in use` —— 它不会说"另有一个 drift
 * 会话在跑"，于是排查方向完全跑偏。随机的代价是"客户端怎么知道端口"，而那个问题
 * 由发现文件回答（`.drift/session.json`），这也正是 Jupyter 的做法。
 *
 * ## 为什么绑 127.0.0.1 而不是 0.0.0.0
 *
 * 绑 `0.0.0.0` 等于把调试面开到局域网。token 能挡住"乱连"，但"能不能连上"这件事
 * 本身就不该给外面。两道门比一道好。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class SocketServer implements Server
{
    /** @var resource */
    private $listener;

    private readonly int $port;

    private bool $closed = false;

    /**
     * @param int $port `0` = 让系统分配（默认）
     *
     * @throws \RuntimeException 端口占不上
     */
    public function __construct(int $port = 0)
    {
        $context = \stream_context_create(['socket' => ['backlog' => 8]]);
        $errno = 0;
        $errstr = '';
        $listener = @\stream_socket_server(
            'tcp://127.0.0.1:' . $port,
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            $context,
        );
        if ($listener === false) {
            throw new \RuntimeException("开不了监听端口（{$port}）：{$errstr}");
        }

        $this->listener = $listener;
        $this->port = self::portOf($listener);
    }

    /** 实际端口（构造时传 0 也能拿到系统分配的那个）。 */
    public function port(): int
    {
        return $this->port;
    }

    /** @param list<Channel> $channels */
    public function wait(array $channels, int $timeoutMs): array
    {
        if ($this->closed) {
            return [];
        }

        $read = [$this->listener];
        /** @var array<int, Channel> $byStream 资源 id → 通道，回来时按同一个 id 认人 */
        $byStream = [];
        foreach ($channels as $channel) {
            if (!$channel instanceof SocketChannel) {
                continue;
            }
            $stream = $channel->stream();
            $read[] = $stream;
            $byStream[(int) $stream] = $channel;
        }

        $write = null;
        $except = null;
        if ($timeoutMs < 0) {
            // 无限等：两个都传 null。传 0 是"立即返回"，与这里的语义相反。
            $seconds = null;
            $micros = null;
        } else {
            $seconds = \intdiv($timeoutMs, 1000);
            $micros = ($timeoutMs % 1000) * 1000;
        }

        $ready = @\stream_select($read, $write, $except, $seconds, $micros);
        if ($ready === false || $ready === 0) {
            return [];
        }

        $out = [];
        foreach ($read as $stream) {
            if ($stream === $this->listener) {
                $connection = @\stream_socket_accept($this->listener, 0);
                if ($connection !== false) {
                    $out[] = new SocketChannel($connection);
                }

                continue;
            }

            $channel = $byStream[(int) $stream] ?? null;
            if ($channel !== null) {
                $out[] = $channel;
            }
        }

        return $out;
    }

    public function canPoll(): bool
    {
        return true;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        @\fclose($this->listener);
    }

    /** @param resource $listener */
    private static function portOf($listener): int
    {
        $name = \stream_socket_get_name($listener, false);
        if ($name === false) {
            return 0;
        }
        $colon = \strrpos($name, ':');

        return $colon === false ? 0 : (int) \substr($name, $colon + 1);
    }
}
