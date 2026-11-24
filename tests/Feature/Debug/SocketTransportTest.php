<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Dev\Debug\SingleServer;
use Drift\Dev\Debug\SocketChannel;
use Drift\Dev\Debug\SocketServer;
use Drift\Dev\Tests\Support\FakeTransport;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 传输层：`Server` / `Channel` 两种实现。
 *
 * 判据只针对**契约**，不针对实现细节：
 * - `wait()` 只回答"哪条可读"，**不改** `read()` 的阻塞语义；
 * - `read()` 返回 `null` 只有一种含义：对端走了；
 * - `canPoll()` 是"能不能自走"的唯一开关，说 false 的实现必须真的不能立即返回。
 *
 * loopback TCP 在 CI 上是可用的（不经过外部网络），但仍给每一步设超时：一条挂住的
 * 判据会把整套测试拖成超时，而那种失败看起来像"机器慢"。
 */
final class SocketTransportTest extends SmokeTestCase
{
    /** @return array{0: SocketServer, 1: resource, 2: SocketChannel} 服务器、客户端流、服务端通道 */
    private function pair(): array
    {
        $server = new SocketServer(0);
        $client = \stream_socket_client('tcp://127.0.0.1:' . $server->port(), $errno, $errstr, 2);
        if ($client === false) {
            $server->close();
            self::fail('连不上自己开的端口：' . $errstr);
        }
        \stream_set_timeout($client, 2);

        $ready = $server->wait([], 2000);
        if ($ready === [] || !$ready[0] instanceof SocketChannel) {
            $server->close();
            self::fail('wait 没有给出新连接');
        }

        return [$server, $client, $ready[0]];
    }

    public function testAcceptThenReadAndWriteBothWays(): void
    {
        [$server, $client, $channel] = $this->pair();

        try {
            check('端口由系统分配（不是 0）', $server->port() > 0, 'port=' . $server->port());
            check('对端地址可读', $channel->peer() !== '' && $channel->peer() !== '未知连接', $channel->peer());
            check('socket 服务器支持立即返回', $server->canPoll());

            \fwrite($client, "{\"id\":1,\"method\":\"ping\"}\n");
            $ready = $server->wait([$channel], 2000);
            check('已有连接可读时被返回', \count($ready) === 1, 'ready=' . \count($ready));
            check('读到的是整行，行尾已去掉', $ready[0]->read() === '{"id":1,"method":"ping"}');

            $ready[0]->write('{"id":1,"ok":true}');
            $reply = \fgets($client);
            check('客户端收到同一行', $reply === "{\"id\":1,\"ok\":true}\n", (string) \json_encode($reply));

            // 空行不是消息（与 StdioTransport 同口径）：跳过它读下一条。
            \fwrite($client, "\n\n{\"id\":2}\n");
            $ready = $server->wait([$channel], 2000);
            check('空行被跳过', $ready[0]->read() === '{"id":2}');
        } finally {
            $channel->close();
            \fclose($client);
            $server->close();
        }
    }

    public function testPeerGoneReadsAsNull(): void
    {
        [$server, $client, $channel] = $this->pair();

        try {
            \fwrite($client, "{\"id\":1}\n");
            $server->wait([$channel], 2000);
            check('先确认能读', $channel->read() === '{"id":1}');

            \fclose($client);
            $server->wait([$channel], 2000);
            check('对端走了返回 null（而不是空串）', $channel->read() === null);
        } finally {
            $channel->close();
            $server->close();
        }
    }

    /** `timeout = 0` 必须真的立即返回：自走宿主每帧都要靠它扫一眼。 */
    public function testZeroTimeoutReturnsImmediately(): void
    {
        $server = new SocketServer(0);

        try {
            $start = \microtime(true);
            $ready = $server->wait([], 0);
            $elapsedMs = (\microtime(true) - $start) * 1000;

            check('立即返回空', $ready === [], 'ready=' . \count($ready));
            // 100ms 是宽松上限：它要抓的是"阻塞住了"，不是"调度慢了"。
            check('没有连接时也不阻塞', $elapsedMs < 100.0, \sprintf('%.1fms', $elapsedMs));
        } finally {
            $server->close();
        }
    }

    /**
     * stdio 那条单连接：`wait()` 交出去之后 `read()` 自己阻塞，所以不能自走。
     *
     * 说 false 与"实际不能立即返回"必须一致——反过来（说 true 却阻塞）会让宿主
     * 在 tick 模式下卡在等消息上，表现为"帧不动了"，而没有任何报错。
     */
    public function testSingleServerCannotPoll(): void
    {
        $transport = new FakeTransport(['one']);
        $server = new SingleServer($transport);

        check('单连接不支持自走', !$server->canPoll());
        check('空的时候交出那条通道', \count($server->wait([], 0)) === 1);
        check('有了之后原样返回', \count($server->wait([$transport], 0)) === 1);
    }
}
