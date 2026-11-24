<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\Debug\Protocol\Channel;

/**
 * 一条 loopback TCP 连接。
 *
 * ## 读为什么是阻塞的
 *
 * `Server::wait()` 已经回答过"这条现在可读"，所以 `read()` 不必再猜"有没有数据"——
 * 它只要把这一行读完。写成非阻塞会逼出一个新契约（"空串 = 暂无"），而空串不是
 * 合法消息，`Wire::decode()` 会把它报成格式错误，于是"还没发完"和"发了垃圾"就
 * 分不开了。
 *
 * ## 5 秒超时兜的是"对端发了一半就停"
 *
 * 那种情况下 `fgets` 会一直等，因为连接没关、只是没动静。超时后 `feof` 为 false
 * 而 `timed_out` 为 true —— 两者要分开报：前者是对端走了（正常），后者是这条连接
 * 挂了（异常，而且指向具体是谁）。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class SocketChannel implements Channel
{
    /** @var resource */
    private $stream;

    private readonly string $peer;

    private bool $closed = false;

    /** @param resource $stream */
    public function __construct($stream)
    {
        $this->stream = $stream;
        $name = @\stream_socket_get_name($stream, true);
        // 先算好：close 之后 `stream_socket_get_name` 拿不到，而报错信息里最需要的
        // 恰恰是"哪个连接断了"。
        $this->peer = $name === false ? '未知连接' : $name;

        // 读阻塞（见类注释），写立刻出去；超时给"半条消息"兜底。
        \stream_set_blocking($stream, true);
        \stream_set_timeout($stream, 5);
    }

    /** @return resource 给 {@see SocketServer::wait()} 做 `stream_select` 用 */
    public function stream()
    {
        return $this->stream;
    }

    /**
     * 连一个已经在监听的宿主。
     *
     * 连不上时**只报端口与系统原因**：更该说的话（宿主是不是已经退出）需要查发现
     * 文件里的 pid，那是 {@see Discovery::diagnose()} 的事 —— 这里拿不到那条记录。
     *
     * @throws \RuntimeException 连不上
     */
    public static function connect(int $port, float $timeoutSeconds = 3.0): self
    {
        $errno = 0;
        $errstr = '';
        $stream = @\stream_socket_client(
            'tcp://127.0.0.1:' . $port,
            $errno,
            $errstr,
            $timeoutSeconds,
        );
        if ($stream === false) {
            throw new \RuntimeException("连不上 127.0.0.1:{$port}：{$errstr}");
        }

        return new self($stream);
    }

    public function read(): ?string
    {
        if ($this->closed) {
            return null;
        }

        while (true) {
            $line = \fgets($this->stream);
            if ($line === false) {
                if (\feof($this->stream)) {
                    $this->closed = true;

                    return null;
                }
                $meta = \stream_get_meta_data($this->stream);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new \RuntimeException('读超时（5 秒没有完整一行）：' . $this->peer);
                }
                $this->closed = true;

                return null;
            }

            $line = \rtrim($line, "\r\n");
            // 与 `StdioTransport` 同一口径：空行不是消息。手敲 `nc` 多按一次
            // 回车，不该变成"格式错误"。
            if ($line === '') {
                continue;
            }

            return $line;
        }
    }

    public function write(string $line): void
    {
        if ($this->closed) {
            throw new \RuntimeException('通道已关闭：' . $this->peer);
        }

        $payload = $line . "\n";
        $total = \strlen($payload);
        $done = 0;
        while ($done < $total) {
            $written = @\fwrite($this->stream, \substr($payload, $done));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('写不进通道：' . $this->peer);
            }
            $done += $written;
        }
        \fflush($this->stream);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        @\fclose($this->stream);
    }

    public function peer(): string
    {
        return $this->peer;
    }
}
