<?php
declare(strict_types=1);

namespace Drift\App\Io;

/**
 * **阻塞**的 HTTP 客户端（2026-10-31）—— 框架里唯一一条 HTTP 路径。
 *
 * ```php
 * // 只许写在 Job::run() 里（那一段跑在 worker 进程，UI 线程闲着）
 * $data = Http::get('http://127.0.0.1:8099/api/rows?page=2')->json();
 * $res  = Http::post($url, \json_encode($body), ['headers' => ['Content-Type' => 'application/json']]);
 * ```
 *
 * ## 为什么是阻塞的（以及为什么这是**唯一**能成立的做法）
 *
 * GUI 框架里做 HTTP 有三条路，2026-10-31 逐条量过，只剩这一条：
 *
 * | 方案 | 结论 |
 * |---|---|
 * | A2 池加 `http.request` op（C++ + 静态 libcurl） | exe **+6~8 MB**（`libcrypto` 6.94 MB 是大头），且是第二套 TLS / 第二套证书来源 |
 * | `curl_multi` 接帧循环（本仓文档原来推荐的"正解"） | **实测段错误**：`curl_multi_init()` 一次，进程退出 `exit 139`。与 swoole 无关，两次入口（`php`、`typephp/php.exe`）都复现；最小复现见 `docs/ASYNC-ARCH-2026-10-31.md` §14.1 |
 * | **阻塞 curl + 常驻 worker** | **本类**。零体积增量（`php_curl.dll` 早在部署包里）、一套 TLS、一套证书来源 |
 *
 * ⇒ 一句话：**HTTP 永远不要出现在 UI 线程上**。要取数就让 `Job::run()` 去取 ——
 *   `ProcessTaskHost` 已经把"在别的进程里等"这件事做完了（预热后每条 25.5µs 的回程）。
 *
 * ## 与 `IoPoller` 的分工（同一层，两件事）
 *
 * | | `IoPoller` | `Http` |
 * |---|---|---|
 * | 阻塞吗 | 不阻塞（`select(0)` 每帧问一次） | **阻塞**（等到响应或超时） |
 * | 谁调 | 帧循环（`PumpHost::pumpIo`） | `Job::run()`（worker 进程） |
 * | 管什么 | fd 可读/可写 | 一次完整的请求-响应 |
 * | 典型用途 | 长连接、自定义协议、流式 | 取一页 JSON |
 *
 * ★ **两者的关系不是"新旧"**：`IoPoller` 在 UI 线程上永远不能阻塞，`Http` 在 worker
 *   里永远不需要非阻塞。把它们并排放在 `Drift\App\Io\` 下，就是为了让这个对比
 *   每次打开目录都能看见 —— 而不是靠谁记得。
 *
 * ## 为什么不是第二套"取数机制"
 *
 * 本类**不碰 `TaskHost` / `Future` / `AsyncValue`**：它只把"一次阻塞往返"做成一个
 * 返回值。异步语义仍然只有一套（`Future` + `AsyncCommand`），本类只是被包在
 * `Job::run()` 里的一行。2026-10-31 之前，`apps/users` 与 `apps/showcase` **各写了
 * 一份 30 行的 curl**（同样的超时、同样的状态码判断、同样的 body 截断），
 * 两处必然漂 —— 现在它们都只剩一行 `Http::get(...)->json()`。
 *
 * ## 几条刻意的默认值
 *
 * | 项 | 默认 | 理由 |
 * |---|---|---|
 * | 连接超时 | 5 s | "连不上"要早断；与两处既有实现一致 |
 * | 总超时 | 10 s | "响应慢"可以等；同上 |
 * | 跟随重定向 | **0（不跟）** | 取数接口不该有 302。悄悄跟随会把"登录页 302 到首页"变成"解析首页 HTML 失败" |
 * | 非 2xx | **抛** | 与两处既有实现一致；要自己处理 404 就 `expectOk => false` 再看 `ok()` |
 * | 头名 | 统一小写 | 见 `HttpResponse` 的类注释 |
 *
 * ★ **不做的**（没有消费者的分支 = 未实现）：连接复用 / 并发 / 流式下载 / cookie 罐 /
 *   重试 / 代理开关。它们每一个都是真实的策略选择，而今天唯一的用法是"取一页 JSON"。
 *   真需要时，形状是**在 worker 里**加的（`curl_share_*` 或 `IoPoller`），不是在这里。
 *
 * @api
 */
final class Http
{
    /** 连接超时默认值（秒）—— "连不上"5 秒就该断 */
    public const DEFAULT_CONNECT_TIMEOUT_S = 5.0;

    /** 总超时默认值（秒）—— "响应慢"可以等到 10 秒 */
    public const DEFAULT_TIMEOUT_S = 10.0;

    /** 允许的动词（别的动词在这里没有意义，早抛比让 curl 猜好） */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

    /** 合法的 `$opts` 键 —— 拼错的键**当场抛**，不静默忽略 */
    private const OPT_KEYS = ['body', 'headers', 'connectTimeout', 'timeout', 'followRedirects', 'expectOk'];

    /**
     * GET（最常用的一档）。
     *
     * @param array<string, mixed> $opts 见 {@see self::request()}
     *
     * @throws HttpException 传输失败，或非 2xx 且 `expectOk` 为真
     *
     * @api
     */
    public static function get(string $url, array $opts = []): HttpResponse
    {
        return self::request('GET', $url, $opts);
    }

    /**
     * POST（body 走第二个参数，不塞进 `$opts` —— 这是唯一会被天天用到的那一格）。
     *
     * @param array<string, mixed> $opts 见 {@see self::request()}；**不许**再带 `body`
     *
     * @throws HttpException 传输失败，或非 2xx 且 `expectOk` 为真
     *
     * @api
     */
    public static function post(string $url, string $body = '', array $opts = []): HttpResponse
    {
        if (isset($opts['body'])) {
            throw new \InvalidArgumentException('Http::post() 的 body 走第二个参数，$opts 里不要再给 body');
        }

        return self::request('POST', $url, $opts + ['body' => $body]);
    }

    /**
     * 通用入口。
     *
     * ```php
     * $res = Http::request('PUT', $url, [
     *     'body'            => $json,
     *     'headers'         => ['Content-Type' => 'application/json'],
     *     'connectTimeout'  => 2.0,
     *     'timeout'         => 30.0,
     *     'followRedirects' => 3,
     *     'expectOk'        => false,   // 4xx/5xx 也当数据返回（自己看 ok()）
     * ]);
     * ```
     *
     * @param array<string, mixed> $opts
     *        · `body` (string) 请求体，默认 `''`
     *        · `headers` (array<string, string>) 请求头
     *        · `connectTimeout` (float) 秒，默认 {@see self::DEFAULT_CONNECT_TIMEOUT_S}
     *        · `timeout` (float) 秒，默认 {@see self::DEFAULT_TIMEOUT_S}
     *        · `followRedirects` (int) 最多跟几跳，默认 0 = 不跟
     *        · `expectOk` (bool) 非 2xx 是否抛，默认 `true`
     *
     * @throws \InvalidArgumentException 动词非法 / 选项名拼错 / URL 为空（**程序员错**，不是运行期失败）
     * @throws HttpException 传输失败，或非 2xx 且 `expectOk` 为真
     *
     * @api
     */
    public static function request(string $method, string $url, array $opts = []): HttpResponse
    {
        $method = \strtoupper(\trim($method));
        if (!\in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('不支持的 HTTP 动词：' . $method . '（只认 ' . \implode('/', self::METHODS) . '）');
        }
        if (\trim($url) === '') {
            throw new \InvalidArgumentException('Http：URL 不能为空');
        }
        foreach ($opts as $key => $_) {
            if (!\in_array((string) $key, self::OPT_KEYS, true)) {
                throw new \InvalidArgumentException(
                    'Http：未知选项 \'' . $key . '\'（只有 ' . \implode(' / ', self::OPT_KEYS) . '）—— 拼错的选项不会报错，只会静默不生效，所以这里直接抛'
                );
            }
        }

        $headers = $opts['headers'] ?? [];
        if (!\is_array($headers)) {
            throw new \InvalidArgumentException('Http：headers 必须是 array<string, string|list<string>>');
        }
        $body = $opts['body'] ?? '';
        if (!\is_string($body)) {
            throw new \InvalidArgumentException('Http：body 必须是 string（要发 JSON 就自己 json_encode）');
        }
        if ($body !== '' && ($method === 'GET' || $method === 'HEAD')) {
            // ★ curl 对 GET/HEAD 会**静默丢掉** POSTFIELDS —— 与其让调用方以为发出去了，
            //   不如当场抛。真要用 GET 带 body（少见但合法）就自己 `request('GET', …)`
            //   之外的路走不通，本类不支持。
            throw new \InvalidArgumentException('Http：' . $method . ' 不带请求体（curl 会静默丢掉它）');
        }

        $connectS = (float) ($opts['connectTimeout'] ?? self::DEFAULT_CONNECT_TIMEOUT_S);
        $timeoutS = (float) ($opts['timeout'] ?? self::DEFAULT_TIMEOUT_S);
        if ($connectS <= 0.0 || $timeoutS <= 0.0) {
            // ★ `CURLOPT_TIMEOUT_MS => 0` 在 curl 里是"**无限**等待"，恰好与"给 0 表示不等"
            //   的直觉相反 ⇒ 这里挡掉，别让 0 变成一个永不超时的请求。
            throw new \InvalidArgumentException('Http：connectTimeout / timeout 必须 > 0（0 在 curl 里表示"无限等"）');
        }

        $redirects = \max(0, (int) ($opts['followRedirects'] ?? 0));
        $expectOk = (bool) ($opts['expectOk'] ?? true);

        $ch = \curl_init($url);
        if ($ch === false) {
            // AOT 产物里 curl 由运行时 ini 的 `extension=curl` 提供（`drift-build` 写死）；
            // 真到这里说明部署包缺扩展 —— 这是安装问题，不是网络问题，所以要说得明白。
            throw new HttpException('Http：curl 初始化失败（运行时 ini 没启 extension=curl？）：' . $url, $url);
        }

        /** @var array<string, string> $seen 最新一段响应头（重定向会重来一段） */
        $seen = [];

        $lines = [];
        foreach ($headers as $name => $value) {
            // ★ 值可以是数组（同名头多次出现是合法的：`Accept` / `X-Tag` 都是这样）。
            //   直接拼会让 PHP 把数组转成字符串 "Array" 发出去 —— 不报错，只是头是假的。
            foreach (\is_array($value) ? $value : [$value] as $one) {
                $lines[] = $name . ': ' . $one;
            }
        }
        if ($body !== '' && $method !== 'GET' && $method !== 'HEAD') {
            // ★ 必须显式禁掉 `Expect: 100-continue`：curl 对 >1KB 的 body 会先发这个头
            //   并**等 1 秒**看服务端怎么说。对端是 PHP 内置服务器时不回 100 ⇒
            //   每次 POST 白等 1s。这是 curl 的经典坑，不是本仓的选择。
            $lines[] = 'Expect:';
        }

        \curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // ★ 用毫秒版：秒版只能表达整数，2.5s 这种值会被截成 2s。
            CURLOPT_CONNECTTIMEOUT_MS => (int) \round($connectS * 1000.0),
            CURLOPT_TIMEOUT_MS => (int) \round($timeoutS * 1000.0),
            CURLOPT_FOLLOWLOCATION => $redirects > 0,
            CURLOPT_MAXREDIRS => $redirects,
            CURLOPT_HTTPHEADER => $lines,
            // ★ 头用回调收（`CURLOPT_HEADER => true` 会把头混进 body，还得自己切）；
            //   回调里"见到新的状态行就清空"⇒ 重定向 / 100-continue 之后留下的是**最后**一段。
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $raw) use (&$seen): int {
                $line = \rtrim($raw, "\r\n");
                if ($line === '') {
                    return \strlen($raw);
                }
                if (\str_starts_with($line, 'HTTP/')) {
                    $seen = [];

                    return \strlen($raw);
                }
                $colon = \strpos($line, ':');
                if ($colon === false) {
                    return \strlen($raw);
                }
                $name = \strtolower(\trim(\substr($line, 0, $colon)));
                $value = \trim(\substr($line, $colon + 1));
                $seen[$name] = isset($seen[$name]) ? $seen[$name] . ', ' . $value : $value;

                return \strlen($raw);
            },
        ]);

        if ($method === 'HEAD') {
            \curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($method !== 'GET') {
            if ($method !== 'POST') {
                // PUT / PATCH / DELETE：curl 只有 POST 是一等公民，别的要自己说
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = \curl_exec($ch);
        $errno = \curl_errno($ch);
        $error = \curl_error($ch);
        $status = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string) \curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $elapsedMs = ((float) \curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000.0;

        if ($errno !== 0 || !\is_string($raw)) {
            // ★ 传输层失败**没有**状态码（`status` 留 null）—— 那是与"服务端说 500"
            //   完全不同的两件事，见 `HttpException` 的类注释。
            throw new HttpException(
                \sprintf('%s %s → %s', $method, $url, $error !== '' ? $error : '传输失败（curl 没给出原因）'),
                $url,
            );
        }

        $response = new HttpResponse(
            $status,
            $seen,
            $raw,
            $effective !== '' ? $effective : $url,
            $elapsedMs,
        );

        if ($expectOk && !$response->ok()) {
            throw new HttpException(
                \sprintf('%s %s → HTTP %d：%s', $method, $url, $status, $response->snippet()),
                $response->url(),
                $status,
            );
        }

        return $response;
    }
}
