<?php
declare(strict_types=1);

namespace Drift\DevTools\Host;

/**
 * 够用就好的 HTTP/1.1：一个事件循环，多连接，支持流式应答。
 *
 * ## 为什么不用 `php -S`
 *
 * 内置服务器是独立进程，而它要回答的问题全部依赖本进程里的 `SessionQueries`
 * （几百帧的数组）。把状态传过去的办法只有落盘或环境变量 —— 前者等于每个请求
 * 重做一遍 `Facts::gather()`，后者装不下。
 *
 * ## 为什么是 `stream_select` 而不是"一次一条"
 *
 * 早先是 `accept → 阻塞读完 → 写一条 Content-Length 响应 → fclose`，一次只服务
 * 一条连接。**这个形状容不下 SSE**：事件流要占住连接直到页面关掉，占住唯一的
 * 服务位之后页面连 `/app.js` 都发不出去。而且它的症状早就存在，只是本地感觉不到：
 * 页面首屏 `Promise.all` 六个请求，服务端一次只服务一条，实际是六次串行握手。
 *
 * 所以循环改成"select 一下，谁有事就办谁"，与 {@see SocketServer::wait()} 同一套
 * 机制（那边已经在生产路径上跑着，不是新发明的写法）。这**推翻了**本类早先写的
 * "不做 keep-alive / 不做分块"两条：不做 keep-alive 仍然成立（一次请求一条连接，
 * 多一次握手是微秒级）；但"不做流式"不成立了 —— SSE 就是要一条不结束的响应。
 *
 * ## 流式应答的形状
 *
 * handler 返回 `{status, type, body}` 就是一次性响应（写完即关，与原来一样）；
 * 返回 `{status, type, stream}` 就是流式：先写头（**没有 Content-Length**，靠
 * `Connection: close` 定界），然后每个 tick 调一次 `stream()`，返回非空字符串就
 * 写出去，返回 `null` 表示"这一轮没有"。tick 的节奏由这个循环给（{@see self::TICK_MS}），
 * 生产者只管"现在有什么"，不管"多久问一次" —— 两件事分开，生产者才不用自己 sleep。
 *
 * ## 三条硬边界
 *
 * 1. 请求头超过 8 KB 直接断 —— 没有上限的读取就是一个可以被一条请求打满内存的洞。
 * 2. 只认 `GET` / `POST`，其余 405。
 * 3. 默认只绑 `127.0.0.1`：这个服务能读到进程内的全部结构，绑 0.0.0.0 等于给
 *    局域网开了一扇门，而它连密码都没有。
 *
 * ## 空闲连接必须有截止时间
 *
 * 非阻塞之后没有"读超时"兜底了：一条半开连接（发了半行请求就停）会永远占着一个
 * 槽。所以每条连接记一个 `idleAt`，超过 {@see self::IDLE_LIMIT} 秒没有任何进展
 * 就摘掉；流式连接每推一次算"有进展"。上限 {@see self::MAX_CONNS} 是第二道闸。
 *
 * @internal 证据层内部；`devtools/bin/debug-web.php` 是它唯一的调用方
 */
final class HttpServer
{
    private const int MAX_HEADER = 8192;

    /** 流式 tick：25ms ⇒ 40 次/秒，够 60fps 的事件不积压，又不至于空转太凶。 */
    private const int TICK_MS = 25;

    /** 一条连接最多活多久没有任何进展（秒）。半开连接靠它回收。 */
    private const float IDLE_LIMIT = 30.0;

    /** 流式连接多久没东西可推就发一行注释保活（秒）。 */
    private const float HEARTBEAT = 15.0;

    /** 同时在册的连接上限。第二道闸：idle 回收失效时也不至于把内存吃光。 */
    private const int MAX_CONNS = 64;

    /** @var resource */
    private $socket;

    private int $port;

    /**
     * 在册连接。`stream` 非空 = 这条已经进入流式应答。
     *
     * @var array<int, array{sock: resource, in: string, stream: ?\Closure, idleAt: float}>
     */
    private array $conns = [];

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

    /** 在册连接数。判据用：SSE 断开之后它必须回到 0。 */
    public function connections(): int
    {
        return \count($this->conns);
    }

    /**
     * 阻塞地服务下去。
     *
     * @param callable(string, string, array<string, mixed>, array<string, mixed>): array{status: int, type: string, body?: string, stream?: \Closure(): ?string} $handler
     *   `(method, path, query, body)` 进；`{status, type, body}`（一次性）或
     *   `{status, type, stream}`（流式）出。
     *
     * @return never
     */
    public function serve(callable $handler): never
    {
        while (true) {
            $read = [$this->socket];
            foreach ($this->conns as $one) {
                $read[] = $one['sock'];
            }
            $write = null;
            $except = null;

            // 没有流式连接时不必每 25ms 醒一次：一直等到有人说话。
            $streaming = $this->anyStreaming();
            $seconds = $streaming ? 0 : null;
            $micros = $streaming ? self::TICK_MS * 1000 : null;

            $ready = @\stream_select($read, $write, $except, $seconds, $micros);
            if ($ready === false) {
                // 被信号打断（Ctrl-C 在部分平台走这里）而不是监听坏了：继续。
                continue;
            }

            foreach ($read as $sock) {
                if ($sock === $this->socket) {
                    $this->accept($handler);

                    continue;
                }
                $this->onReadable($sock, $handler);
            }

            $this->pump();
            $this->reapIdle();
        }
    }

    public function close(): void
    {
        foreach (\array_keys($this->conns) as $key) {
            $this->drop($key);
        }
        if (\is_resource($this->socket)) {
            @\fclose($this->socket);
        }
    }

    private function anyStreaming(): bool
    {
        foreach ($this->conns as $one) {
            if ($one['stream'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(string, string, array<string, mixed>, array<string, mixed>): array{status: int, type: string, body?: string, stream?: \Closure(): ?string} $handler
     */
    private function accept(callable $handler): void
    {
        $sock = @\stream_socket_accept($this->socket, 0);
        if ($sock === false) {
            return;
        }
        if (\count($this->conns) >= self::MAX_CONNS) {
            // 满了就明确拒绝，而不是让 accept 队列慢慢积到对端超时。
            @\fwrite($sock, "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            @\fclose($sock);

            return;
        }

        // 非阻塞：select 已经说过"可读"，`fread` 不该再等。半条请求留在
        // `in` 里，下一轮继续拼。
        \stream_set_blocking($sock, false);
        // 键用 `get_resource_id` 而不是 `spl_object_id`：socket 是 resource 不是
        // object，后者会当场 TypeError（PHP 8 起不再接受 resource）。
        $this->conns[\get_resource_id($sock)] = [
            'sock' => $sock,
            'in' => '',
            'stream' => null,
            'idleAt' => \microtime(true),
        ];
    }

    /**
     * @param resource $sock
     * @param callable(string, string, array<string, mixed>, array<string, mixed>): array{status: int, type: string, body?: string, stream?: \Closure(): ?string} $handler
     */
    private function onReadable($sock, callable $handler): void
    {
        $key = \get_resource_id($sock);
        $conn = $this->conns[$key] ?? null;
        if ($conn === null) {
            return;
        }

        $chunk = @\fread($sock, 8192);
        if ($chunk === false || $chunk === '') {
            // 非阻塞下 `''` 可能只是"暂时没有"，但对端关掉时 `feof` 会同时为真。
            if (\feof($sock)) {
                $this->drop($key);
            }

            return;
        }

        $conn['in'] .= $chunk;
        $conn['idleAt'] = \microtime(true);
        $this->conns[$key]['in'] = $conn['in'];
        $this->conns[$key]['idleAt'] = $conn['idleAt'];

        $request = $this->parse($conn['in']);
        // 头长度的上限要在**解析之后**判（解析成功时才知道头在哪儿结束），
        // 而且必须按"头部分"而不是"整条 in"来判：`in` 里可能还带着请求体。
        $split = \strpos($conn['in'], "\r\n\r\n");
        $headLength = $split === false ? \strlen($conn['in']) : $split;
        if ($headLength > self::MAX_HEADER) {
            $this->respond($key, 431, 'text/plain; charset=utf-8', '请求头太大');

            return;
        }
        if ($request === null) {
            return;
        }

        [$method, $target, $body] = $request;
        if ($method !== 'GET' && $method !== 'POST') {
            $this->respond($key, 405, 'text/plain; charset=utf-8', '只支持 GET / POST');

            return;
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

        $response = $handler($method, $path, $query, $decoded);
        if (isset($response['stream']) && $response['stream'] instanceof \Closure) {
            $this->startStream($key, $response);

            return;
        }

        $this->respond($key, $response['status'], $response['type'], $response['body'] ?? '');
    }

    /**
     * 拆一条完整请求；还没收齐就是 `null`。
     *
     * @return array{0: string, 1: string, 2: string}|null `[方法, 目标, 请求体]`
     */
    private function parse(string $raw): ?array
    {
        $split = \strpos($raw, "\r\n\r\n");
        if ($split === false) {
            return null;
        }

        $head = \substr($raw, 0, $split);
        $lines = \explode("\r\n", $head);
        $requestLine = (string) \array_shift($lines);
        if (!\preg_match('#^([A-Z]+) (\S+) HTTP/1\.[01]$#', $requestLine, $m)) {
            return null;   // 不是 HTTP：等下一轮拼齐，最终撞 8KB 上限被 431 掉
        }

        $length = 0;
        foreach ($lines as $line) {
            if (\preg_match('/^content-length:\s*(\d+)$/i', $line, $lm)) {
                $length = (int) $lm[1];
            }
        }

        $body = \substr($raw, $split + 4);
        if (\strlen($body) < $length) {
            return null;
        }

        return [$m[1], $m[2], \substr($body, 0, $length)];
    }

    /** @param array{status: int, type: string, body?: string, stream?: \Closure(): ?string} $response */
    private function startStream(int $key, array $response): void
    {
        $conn = $this->conns[$key] ?? null;
        if ($conn === null) {
            return;
        }

        // 没有 Content-Length：流式的边界是"连接关掉"，SSE 正是这么用的。
        $head = "HTTP/1.1 {$response['status']} {$this->reason($response['status'])}\r\n"
            . "Content-Type: {$response['type']}\r\n"
            . "Connection: close\r\n"
            . "Cache-Control: no-store\r\n"
            . "\r\n";

        if (@\fwrite($conn['sock'], $head) === false) {
            $this->drop($key);

            return;
        }
        @\fflush($conn['sock']);

        /** @var \Closure(): ?string $stream */
        $stream = $response['stream'];
        $this->conns[$key]['stream'] = $stream;
        // 头写完立刻推一次：第一屏数据不该等到下一个 tick。
        $this->pumpOne($key);
    }

    /** 每条流式连接问一次"现在有什么"。 */
    private function pump(): void
    {
        foreach (\array_keys($this->conns) as $key) {
            if (($this->conns[$key]['stream'] ?? null) !== null) {
                $this->pumpOne($key);
            }
        }
    }

    private function pumpOne(int $key): void
    {
        $conn = $this->conns[$key] ?? null;
        if ($conn === null || $conn['stream'] === null) {
            return;
        }

        $chunk = ($conn['stream'])();
        if ($chunk === null || $chunk === '') {
            // 静止的应用可以几分钟没有帧。不发心跳，浏览器或中间层会以为这条
            // 连接死了；心跳是**连接**的事，不该让生产者去操心。
            if (\microtime(true) - $conn['idleAt'] > self::HEARTBEAT) {
                if (@\fwrite($conn['sock'], ": keep-alive\n\n") !== false) {
                    @\fflush($conn['sock']);
                    $this->conns[$key]['idleAt'] = \microtime(true);
                }
            }

            return;
        }

        if (@\fwrite($conn['sock'], $chunk) === false) {
            $this->drop($key);

            return;
        }
        @\fflush($conn['sock']);
        // 推出去就算"有进展"：流式连接本来就可以长时间不推（静止的应用），
        // 但只要还在推，它就不该被 idle 回收。
        $this->conns[$key]['idleAt'] = \microtime(true);
    }

    /**
     * 摘掉"没有任何进展"的连接。半开请求与走掉的浏览器都在这里回收。
     *
     * **流式连接不参与**：它本来就可以长时间没有数据（静止的应用），靠心跳保活、
     * 靠对端 FIN 收尾。把它也按 30 秒掐掉，等于让页面每 30 秒重连一次。
     */
    private function reapIdle(): void
    {
        $now = \microtime(true);
        foreach ($this->conns as $key => $one) {
            if ($one['stream'] !== null) {
                continue;
            }
            if ($now - $one['idleAt'] > self::IDLE_LIMIT) {
                $this->drop($key);
            }
        }
    }

    private function drop(int $key): void
    {
        $conn = $this->conns[$key] ?? null;
        unset($this->conns[$key]);
        if ($conn !== null && \is_resource($conn['sock'])) {
            @\fclose($conn['sock']);
        }
    }

    private function respond(int $key, int $status, string $type, string $body): void
    {
        $conn = $this->conns[$key] ?? null;
        if ($conn === null) {
            return;
        }

        $response = "HTTP/1.1 {$status} {$this->reason($status)}\r\n"
            . "Content-Type: {$type}\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            // 不让浏览器复用连接：复用就要处理下一轮请求的边界，而那是这个类
            // 刻意不承担的部分（见类注释）。
            . "Connection: close\r\n"
            // 调试工具的数据只对当下这一次有效，缓存的代价是"改了阈值页面没变"。
            . "Cache-Control: no-store\r\n"
            . "\r\n"
            . $body;

        @\fwrite($conn['sock'], $response);
        @\fflush($conn['sock']);
        $this->drop($key);
    }

    private function reason(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            431 => 'Request Header Fields Too Large',
            501 => 'Not Implemented',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }
}
