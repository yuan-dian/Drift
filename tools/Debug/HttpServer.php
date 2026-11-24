<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

/**
 * 够用就好的 HTTP/1.1：一个连接一条请求，读完即走。
 *
 * ## 为什么不用 `php -S`
 *
 * 内置服务器是独立进程，而它要回答的问题全部依赖本进程里的 `SessionQueries`
 * （几百帧的数组）。把状态传过去的办法只有落盘或环境变量 —— 前者等于每个请求
 * 重做一遍 `Facts::gather()`，后者装不下。
 *
 * ## 为什么不做 keep-alive / 分块 / Range
 *
 * 本机调试工具，一次请求一条连接，多一次握手是微秒级。做这些等于给自己写一个
 * 半吊子的 HTTP 栈，而半吊子的表现是"页面偶尔白屏"。发 `Connection: close` 之后
 * 浏览器的行为是确定的。
 *
 * ## 三条硬边界
 *
 * 1. 请求头超过 8 KB 直接断 —— 没有上限的读取就是一个可以被一条请求打满内存的洞。
 * 2. 只认 `GET` / `POST`，其余 405。
 * 3. 默认只绑 `127.0.0.1`：这个服务能读到进程内的全部结构，绑 0.0.0.0 等于给
 *    局域网开了一扇门，而它连密码都没有。
 *
 * @internal 证据层内部；`tools/debug-web.php` 是它唯一的调用方
 */
final class HttpServer
{
    private const int MAX_HEADER = 8192;

    /** @var resource */
    private $socket;

    private int $port;

    /**
     * @param string $host 默认 loopback。传 `0.0.0.0` 前先想清楚谁会连上来。
     * @param int $port 0 = 让系统挑一个
     *
     * @throws \RuntimeException 绑不上（端口被占 / 权限不够）
     */
    public function __construct(string $host = '127.0.0.1', int $port = 0)
    {
        $errno = 0;
        $errstr = '';
        $socket = @\stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );

        if ($socket === false) {
            throw new \RuntimeException("绑不上 {$host}:{$port}（{$errstr}）");
        }

        $this->socket = $socket;
        $name = (string) \stream_socket_get_name($socket, false);
        $this->port = (int) \substr($name, (int) \strrpos($name, ':') + 1);
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * 阻塞地服务下去。
     *
     * @param callable(string, string, array<string, mixed>, array<string, mixed>): array{status: int, type: string, body: string} $handler
     *   `(method, path, query, body)` 进，`{status, type, body}` 出。
     *
     * @return never
     */
    public function serve(callable $handler): never
    {
        while (true) {
            $conn = @\stream_socket_accept($this->socket, -1);
            if ($conn === false) {
                // 被信号打断（Ctrl-C 在部分平台走这里）而不是监听坏了：继续。
                continue;
            }

            try {
                $this->handle($conn, $handler);
            } finally {
                @\fclose($conn);
            }
        }
    }

    public function close(): void
    {
        if (\is_resource($this->socket)) {
            @\fclose($this->socket);
        }
    }

    /**
     * @param resource $conn
     * @param callable(string, string, array<string, mixed>, array<string, mixed>): array{status: int, type: string, body: string} $handler
     */
    private function handle($conn, callable $handler): void
    {
        // 慢客户端不该把服务卡死：读超时 5 秒，超了就这条连接作废。
        \stream_set_timeout($conn, 5);

        $head = '';
        while (!\str_contains($head, "\r\n\r\n")) {
            $chunk = \fread($conn, 1024);
            if ($chunk === false || $chunk === '') {
                return;
            }
            $head .= $chunk;
            if (\strlen($head) > self::MAX_HEADER) {
                $this->write($conn, 431, 'text/plain; charset=utf-8', '请求头太大');

                return;
            }
        }

        [$rawHead, $rest] = \explode("\r\n\r\n", $head, 2);
        $lines = \explode("\r\n", $rawHead);
        $requestLine = (string) \array_shift($lines);
        if (!\preg_match('#^([A-Z]+) (\S+) HTTP/1\.[01]$#', $requestLine, $m)) {
            $this->write($conn, 400, 'text/plain; charset=utf-8', '请求行不是 HTTP');

            return;
        }

        $method = $m[1];
        $target = $m[2];
        $length = 0;
        foreach ($lines as $line) {
            if (\preg_match('/^content-length:\s*(\d+)$/i', $line, $lm)) {
                $length = (int) $lm[1];
            }
        }

        // body 可能已经跟着头一起读进来了（浏览器 POST 常常一次 write）。
        $body = $rest;
        while (\strlen($body) < $length) {
            $chunk = \fread($conn, $length - \strlen($body));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        [$path, $queryString] = \array_pad(\explode('?', $target, 2), 2, '');
        $query = [];
        if ($queryString !== '') {
            \parse_str($queryString, $query);
        }

        $decoded = [];
        if ($body !== '') {
            $json = \json_decode($body, true);
            $decoded = \is_array($json) ? $json : [];
        }

        if ($method !== 'GET' && $method !== 'POST') {
            $this->write($conn, 405, 'text/plain; charset=utf-8', '只支持 GET / POST');

            return;
        }

        $response = $handler($method, $path, $query, $decoded);
        $this->write($conn, $response['status'], $response['type'], $response['body']);
    }

    /** @param resource $conn */
    private function write($conn, int $status, string $type, string $body): void
    {
        $reason = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            431 => 'Request Header Fields Too Large',
            501 => 'Not Implemented',
            default => 'Error',
        };

        $response = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: {$type}\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            // 不让浏览器复用连接：复用就要处理下一轮请求的边界，而那是这个类
            // 刻意不承担的部分（见类注释）。
            . "Connection: close\r\n"
            // 调试工具的数据只对当下这一次有效，缓存的代价是"改了阈值页面没变"。
            . "Cache-Control: no-store\r\n"
            . "\r\n"
            . $body;

        @\fwrite($conn, $response);
        @\fflush($conn);
    }
}
