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
 * ★ **上传（2026-11-02 起）**：`fields` / `files` 走 multipart，形状对齐 OSS PostObject
 *   （`apps/watermark` 是第一个消费者）。它是**手拼 + 流式**而不是 `CURLFile`：
 *   `CURLOPT_READFUNCTION` 按块喂，内存 O(64 KB)，**不是**整个文件。理由与边界
 *   见 {@see self::buildMultipart()} 与 {@see MultipartBody}。
 *   ★ 严格意义的零拷贝（`sendfile` / `TransmitFile`）对表单上传**用不了** ——
 *   它只能原样发一个 fd，而表单必须在文件前后拼 HTTP 头。流式是这里能做到的上限。
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
    private const OPT_KEYS = ['body', 'headers', 'connectTimeout', 'timeout', 'followRedirects', 'expectOk', 'fields', 'files', 'onProgress'];

    /**
     * 上传体积上限（字节）。超过 ⇒ **抛**，不静默截断、不静默换路。
     *
     * ★ 流式上传**不占内存**（O(一个块)，见 {@see MultipartBody}），所以这个数
     *   不是内存保护，是**防手滑**：1 GB 差不多是"误拖一个视频进来"的量级。
     *   它也不是 `CURLOPT_POSTFIELDSIZE` 的上限（那个在 64 位 PHP 上是 long）。
     */
    public const MAX_UPLOAD_BYTES = 1024 * 1024 * 1024;

    /**
     * 扩展名 → MIME 的最小映射（multipart 每个 part 都要 `Content-Type`）。
     *
     * ★ **为什么不用 `mime_content_type()`**：那是 `fileinfo` 扩展，而 `drift-build`
     *   的运行时扩展清单只有 `mbstring / openssl / curl`（`tools/drift-build.php`
     *   里写死）。解释器里跑得通、AOT 产物里 Fatal —— 这类"只在真机炸"的坑
     *   本仓已经付过一次学费。映射表不认识就退回 `application/octet-stream`，
     *   OSS 对 `file` 字段不看 MIME，看不出来也不影响功能。
     */
    private const MIME_BY_EXT = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'heic' => 'image/heic', 'svg' => 'image/svg+xml', 'tif' => 'image/tiff',
        'tiff' => 'image/tiff', 'pdf' => 'application/pdf',
        'txt' => 'text/plain', 'json' => 'application/json', 'csv' => 'text/csv',
        'zip' => 'application/zip', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
    ];

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
     *        · `fields` (array<string, scalar>) multipart 普通字段（给了就走 multipart）
     *        · `files` (array<string, string|array{path:string,filename?:string,mime?:string}>)
     *          multipart 文件字段，值是本地路径
     *        · `onProgress` (callable(float $fraction): void) 上传进度（**只在
     *          multipart 时可用**；`$fraction` 是请求体口径的 `0..1`，可能重复报
     *          同一个值 —— 节流是调用方的事）
     *
     * ★ `fields` / `files` 与 `body` **互斥**，且此时**不许**在 `headers` 里手设
     *   `Content-Type`（boundary 由本类生成，手设会把它顶掉）。见 {@see self::buildMultipart()}。
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

        // ── multipart 表单（`fields` / `files`，2026-11-02）────────────────────
        //
        // 形状对齐 OSS PostObject：`key` / `policy` / `OSSAccessKeyId` / `signature` /
        // `success_action_status` 走 `fields`，图片本身走 `files`。
        $payload = $body;
        $multipart = null;
        $multipartType = '';
        if (isset($opts['fields']) || isset($opts['files'])) {
            if ($body !== '') {
                throw new \InvalidArgumentException('Http：multipart 用 fields / files 描述请求体，不要再给 body（两者拼在一起没有语义）');
            }
            if ($method !== 'POST') {
                // ★ 只支持 POST：OSS PostObject 是 POST，而 PUT/PATCH 的流式上传要走
                //   `CURLOPT_UPLOAD`（另一套语义，动词也会被改）。今天没有消费者，
                //   与其留一条没走过的分支，不如说清楚。
                throw new \InvalidArgumentException('Http：multipart 只支持 POST（' . $method . ' 要走另一条流式语义，本类没实现）');
            }
            foreach ($headers as $name => $_) {
                // ★ **不许手设 Content-Type**：multipart 的 Content-Type 必须带
                //   `boundary=`，而 boundary 是本类自己生成的。手设一个
                //   `multipart/form-data` 会把 curl 自动补的 boundary 顶掉 ⇒
                //   服务端收到一个**无法解析**的表单，报的还是"缺少字段"。
                if (\strtolower((string) $name) === 'content-type') {
                    throw new \InvalidArgumentException('Http：multipart 的 Content-Type 由本类生成（要带 boundary），不要在 headers 里手设');
                }
            }

            $fields = $opts['fields'] ?? [];
            $files = $opts['files'] ?? [];
            if (!\is_array($fields) || !\is_array($files)) {
                throw new \InvalidArgumentException('Http：fields / files 必须是 array');
            }

            [$multipart, $multipartType] = self::buildMultipart($fields, $files);
        }

        // ── 上传进度回调（2026-11-02）────────────────────────────────────────
        //
        // ★ **只对 multipart 有效**，且给了回调但没走 multipart ⇒ **当场抛**：
        //   `body` 是整块发出去的（`CURLOPT_POSTFIELDS`），中途没有"读到第几字节"
        //   这个观察点 —— 收下这个选项却不生效，调用方会以为进度条该动。
        $onProgress = $opts['onProgress'] ?? null;
        if ($onProgress !== null) {
            if ($multipart === null) {
                throw new \InvalidArgumentException('Http：onProgress 只在 multipart（fields / files）时可用 —— 普通 body 是一次性发出去的，没有中途进度');
            }
            if (!\is_callable($onProgress)) {
                throw new \InvalidArgumentException('Http：onProgress 必须是 callable(float $fraction): void');
            }
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
        if ($multipartType !== '') {
            // ★ 必须在这里补：`CURLOPT_POSTFIELDS` 收到**字符串**时 curl 不会自己加
            //   Content-Type（收到数组才会）。而 multipart 的 Content-Type 少了
            //   `boundary=` 服务端就解不开 —— 所以 boundary 由 `buildMultipart()`
            //   生成，这里原样带上，两者必须是**同一次调用**的产物。
            $lines[] = 'Content-Type: ' . $multipartType;
        }
        if ($payload !== '' || $multipart !== null) {
            // ★ 必须显式禁掉 `Expect: 100-continue`：curl 对 >1KB 的 body 会先发这个头
            //   并**等 1 秒**看服务端怎么说。对端是 PHP 内置服务器时不回 100 ⇒
            //   每次 POST 白等 1s。这是 curl 的经典坑，不是本仓的选择。
            //   ★ 上传几百 KB 以上的图必然 > 1KB ⇒ 这一行对 multipart 尤其重要。
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

        if ($multipart !== null) {
            // ★ **流式上传**：不设 `CURLOPT_POSTFIELDS`（设了会顶掉 READFUNCTION），
            //   改成 UPLOAD + 显式长度 + 读回调。内存只占一个块（curl 默认 64 KB），
            //   不是整个文件。
            //
            // ★★ **PHP 没有 `CURLOPT_POSTFIELDSIZE`**（2026-11-02 实测：8.4.15 与
            //   8.5.11 都 `defined()` 为 false）。PHP 的 curl 扩展只导出
            //   `CURLOPT_POSTFIELDS`（= 10015，是 PHP 自己的伪常量，对应 libcurl 的
            //   `COPYPOSTFIELDS`），而 libcurl 的 `CURLOPT_POSTFIELDSIZE` 从来没被
            //   导出过。所以"POST + 流式 body"只能走这条路：
            //     `CURLOPT_UPLOAD`（用 READFUNCTION 读 body）+ `CURLOPT_INFILESIZE`
            //     （给长度，否则退化成 chunked）+ `CURLOPT_CUSTOMREQUEST('POST')`
            //     （把动词从默认的 PUT 改回 POST，**只改动词不改语义**）。
            //   两个 PHP 版本这三个常量都在，已实测。
            \curl_setopt($ch, CURLOPT_UPLOAD, true);
            \curl_setopt($ch, CURLOPT_INFILESIZE, $multipart->length());
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            // ★ 回调**必须声明 3 个参数**：curl 就是传 3 个。AOT 严格校验实参个数，
            //   少声明一个在解释器里没事、在真机上是 `Fatal: expects exactly N`。
            //
            // ★ 进度在这里顺手报（`$multipart->bytesSent()` 是它自己的记账）：
            //   不用闭包改外部变量，也就不依赖 tpc 对 `use (&$x)` 的支持。
            //   ★ 报的是**请求体**进度（含 part 头），与 `length()` 同口径 ——
            //   两者混用会让进度停在 99%（最后一段是 `--boundary--`）。
            \curl_setopt($ch, CURLOPT_READFUNCTION, static function ($_handle, $_fd, int $want) use ($multipart, $onProgress): string {
                $chunk = $multipart->read($want);
                if ($onProgress !== null) {
                    $total = $multipart->length();
                    if ($total > 0) {
                        $onProgress(\min(1.0, $multipart->bytesSent() / $total));
                    }
                }

                return $chunk;
            });
        } elseif ($method === 'HEAD') {
            \curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($method !== 'GET') {
            if ($method !== 'POST') {
                // PUT / PATCH / DELETE：curl 只有 POST 是一等公民，别的要自己说
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        try {
            $raw = \curl_exec($ch);
            $errno = \curl_errno($ch);
            $error = \curl_error($ch);
        } catch (\RuntimeException $e) {
            // `MultipartBody::read()` 抛的（文件读到一半读不出）⇒ 归到"传输层失败"那一档。
            // ★ 不能让它以 `\RuntimeException` 原样冒出去：`Http` 的对外契约只有
            //   `HttpException` 一种失败，多一种异常类型就是让每个调用方都要多 catch 一个。
            throw new HttpException($method . ' ' . $url . ' → ' . $e->getMessage(), $url);
        } finally {
            // ★ 文件句柄必须在这里关：curl 提前失败（超时 / 被中断）时
            //   `read()` 不会吐完，句柄就挂在那里。
            if ($multipart !== null) {
                $multipart->close();
            }
        }

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

    /**
     * 造一个**流式**的 `multipart/form-data` 请求体，返回 `[body, content-type]`。
     *
     * ## 为什么手拼，而不用 `CURLFile` 或 `CURLOPT_POSTFIELDS => array`
     *
     * 1. **AOT 风险**：`CURLFile` 是 PHP 内置类，`typephp` 认不认识它没有先例。
     *    而"解释器全绿、AOT 产物启动即 Fatal"本仓已经付过一次学费
     *    （见 `MEMORY.md`「AOT 缺 `use` 只在真机炸」）。手拼只用字符串 + 文件句柄。
     * 2. **可控性**：part 的头部顺序、文件名转义、字段注入防护都在一处，
     *    而不是散给 libcurl 的默认行为。
     *
     * ## 为什么是流式（2026-11-02 第二版）
     *
     * 第一版把整个表单拼进一个 `string`。三个问题，第一个就足以否掉它：
     *
     * | 问题 | 后果 |
     * |---|---|
     * | `file_get_contents()` + `.=` 扩容 | 内存峰值 = 文件 × 2~3；64 MB 的图吃 150 MB |
     * | 循环里 `.=` 累加 | lint `str-concat-loop`（§2.4 纪律 2），多字节下堆损坏 |
     * | 严格零拷贝用不了 | `sendfile()` 只能原样发一个 fd，表单要在文件前后拼头 ⇒ **不适用** |
     *
     * 所以上限不是零拷贝，是**流式**：内存 O(一个块)。具体形状见
     * {@see MultipartBody}。
     *
     * ★ **总长度先算不读**（各 part 头长度 + `filesize()` + 尾部）⇒
     *   `CURLOPT_POSTFIELDSIZE` 拿得到精确值，进度分母不用猜（第 2 棒直接用
     *   `length()`），请求也不会退化成 chunked。
     *
     * @param array<string, mixed> $fields 普通字段：名 → 标量值
     * @param array<string, mixed> $files  文件字段：名 → 路径字符串，或
     *                                     `['path' => …, 'filename' => …, 'mime' => …]`
     *
     * @return array{MultipartBody, string}
     *
     * @throws \InvalidArgumentException 字段名/值含 CRLF、文件不存在、体积超限
     */
    private static function buildMultipart(array $fields, array $files): array
    {
        // ★ boundary 用随机值：固定 boundary 会让"字段值里恰好含 `--boundary`"
        //   从"不可能"变成"可能"。16 字节十六进制足够。
        $boundary = '----DriftFormBoundary' . \bin2hex(\random_bytes(16));

        /** @var list<array{head: string, path: string, size: int}> $parts */
        $parts = [];
        $total = 0;

        foreach ($fields as $name => $value) {
            $name = self::assertNoCrlf((string) $name, 'fields 的名字');
            if (\is_array($value) || \is_object($value)) {
                throw new \InvalidArgumentException('Http：fields 的值必须是标量（\'' . $name . '\' 是 ' . \get_debug_type($value) . '）');
            }
            // ★ 值里的 CRLF 能让攻击者**伪造额外的 part**（`\r\n--boundary\r\nContent-Disposition: …`）。
            //   调用方是自己人也不能放过：一次拼接失误就是一个静默的注入面。
            $value = self::assertNoCrlf((string) $value, 'fields[\'' . $name . '\'] 的值');

            // 纯字段的整个 part（含结尾 CRLF）都很小，一次性拼好塞进 head。
            $head = '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . self::quoteDisposition($name) . "\"\r\n\r\n"
                . $value . "\r\n";
            $parts[] = ['head' => $head, 'path' => '', 'size' => 0];
            $total += \strlen($head);
        }

        foreach ($files as $name => $spec) {
            $name = self::assertNoCrlf((string) $name, 'files 的名字');

            $path = '';
            $filename = '';
            $mime = '';
            if (\is_array($spec)) {
                $path = (string) ($spec['path'] ?? '');
                $filename = (string) ($spec['filename'] ?? '');
                $mime = (string) ($spec['mime'] ?? '');
            } else {
                $path = (string) $spec;
            }
            if ($path === '' || !\is_file($path) || !\is_readable($path)) {
                // ★ 不能静默发一个空 part：OSS 会返回 200 但 key 下是个 0 字节对象，
                //   之后任务报"图片格式错误"—— 错误点离原因隔着两个系统。
                throw new \InvalidArgumentException('Http：files[\'' . $name . '\'] 指向的不是可读文件：' . $path);
            }
            if ($filename === '') {
                $filename = \basename($path);
            }
            if ($mime === '') {
                $ext = \strtolower((string) \pathinfo($path, PATHINFO_EXTENSION));
                $mime = self::MIME_BY_EXT[$ext] ?? 'application/octet-stream';
            }

            // ★ 只用 `filesize()`，**不读内容**。`clearstatcache()` 是必须的：
            //   PHP 按路径缓存 stat 结果，同一路径刚被写过（比如刚解码出的缩略图）
            //   会拿到**旧的大小** ⇒ 请求体比声明短，服务端报"连接被重置"。
            \clearstatcache(true, $path);
            $size = @\filesize($path);
            if (!\is_int($size) || $size < 0) {
                throw new \InvalidArgumentException('Http：取不到 files[\'' . $name . '\'] 的大小：' . $path);
            }

            $parts[] = [
                'head' => '--' . $boundary . "\r\n"
                    . 'Content-Disposition: form-data; name="' . self::quoteDisposition($name)
                    . '"; filename="' . self::quoteDisposition($filename) . "\"\r\n"
                    . 'Content-Type: ' . $mime . "\r\n\r\n",
                'path' => $path,
                'size' => $size,
            ];
            $total += $size;
            if ($total > self::MAX_UPLOAD_BYTES) {
                throw new \InvalidArgumentException(
                    'Http：上传体超过 ' . (self::MAX_UPLOAD_BYTES >> 20) . ' MB'
                    . '（这是**业务上限**，不是内存上限 —— 流式上传只占一个块）'
                );
            }
        }

        $body = new MultipartBody($parts, '--' . $boundary . "--\r\n");

        return [$body, 'multipart/form-data; boundary=' . $boundary];
    }

    /**
     * CRLF 检查（multipart 的注入面就这一处）。
     *
     * @throws \InvalidArgumentException 含 `\r` 或 `\n`
     */
    private static function assertNoCrlf(string $value, string $what): string
    {
        if (\str_contains($value, "\r") || \str_contains($value, "\n")) {
            throw new \InvalidArgumentException(
                'Http：' . $what . ' 里不能有换行（能伪造出额外的 multipart part）：' . \str_replace(["\r", "\n"], ['\r', '\n'], $value)
            );
        }

        return $value;
    }

    /**
     * `Content-Disposition` 里的名字/文件名转义（RFC 7578 §5.1 的保守做法）。
     *
     * ★ 中文文件名**不能**直接放进去再指望服务端按 UTF-8 解 —— 各家实现不一致。
     *   本仓的选择是：`"` / `\` 转成 percent-encoding，其余字节原样（OSS 不看
     *   `filename`，真正决定对象名的是 `key` 字段）。
     */
    private static function quoteDisposition(string $value): string
    {
        return \str_replace(['\\', '"', "\r", "\n"], ['%5C', '%22', '%0D', '%0A'], $value);
    }
}
