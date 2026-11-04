<?php
declare(strict_types=1);

namespace Drift\App\Io;

/**
 * 阻塞的 HTTP 客户端 —— 框架里唯一一条 HTTP 路径。
 *
 * 只许写在 Job::run() 里（worker 进程跑，UI 线程闲着）；
 * 与 IoPoller 分工不同：它永不阻塞，本类在 worker 里永不需非阻塞。
 *
 * @api
 */
final class Http
{
    /** 连接超时默认值（秒） */
    public const DEFAULT_CONNECT_TIMEOUT_S = 5.0;

    /** 总超时默认值（秒） */
    public const DEFAULT_TIMEOUT_S = 10.0;

    /** 允许的动词（别的在此无意义，早抛比让 curl 猜好） */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

    /** 合法的 `$opts` 键 —— 拼错的键当场抛，不静默忽略 */
    private const OPT_KEYS = ['body', 'headers', 'connectTimeout', 'timeout', 'followRedirects', 'expectOk', 'fields', 'files', 'onProgress'];

    /**
     * 上传体积上限（字节）。超过 ⇒ 抛，不静默截断、不静默换路。
     */
    public const MAX_UPLOAD_BYTES = 1024 * 1024 * 1024;

    /**
     * 扩展名 → MIME 的最小映射（multipart 每个 part 都要 Content-Type）。
     * 不认得的扩展退回 application/octet-stream。
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
     * GET（最常用）。
     *
     * @param array<string, mixed> $opts 见 {@see self::request()}
     * @throws HttpException 传输失败，或非 2xx 且 expectOk 为真
     * @api
     */
    public static function get(string $url, array $opts = []): HttpResponse
    {
        return self::request('GET', $url, $opts);
    }

    /**
     * POST（body 走第二个参数，不塞进 $opts）。
     *
     * @param array<string, mixed> $opts 见 {@see self::request()}；不许再带 body
     * @throws HttpException 传输失败，或非 2xx 且 expectOk 为真
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
     * @param array<string, mixed> $opts
     *        · body 请求体，默认 ''
     *        · headers 请求头
     *        · connectTimeout 秒，默认 5；timeout 秒，默认 10
     *        · followRedirects 最多跟几跳，默认 0 = 不跟
     *        · expectOk 非 2xx 是否抛，默认 true
     *        · fields / files multipart 普通字段 / 文件字段
     *        · onProgress 上传进度回调（仅 multipart）
     *
     * fields / files 与 body 互斥，且此时不许在 headers 里手设 Content-Type
     * （boundary 由本类生成，手设会顶掉）。
     *
     * @throws \InvalidArgumentException 动词非法 / 选项名拼错 / URL 为空
     * @throws HttpException 传输失败，或非 2xx 且 expectOk 为真
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
            // GET/HEAD 带 body 会被 curl 静默丢掉 ⇒ 当场抛。
            throw new \InvalidArgumentException('Http：' . $method . ' 不带请求体（curl 会静默丢掉它）');
        }

        // ── multipart 表单（fields / files）────────────────────────────
        $payload = $body;
        $multipart = null;
        $multipartType = '';
        if (isset($opts['fields']) || isset($opts['files'])) {
            if ($body !== '') {
                throw new \InvalidArgumentException('Http：multipart 用 fields / files 描述请求体，不要再给 body（两者拼在一起没有语义）');
            }
            if ($method !== 'POST') {
                // multipart 只支持 POST：OSS PostObject 是 POST，PUT/PATCH 走另一条流式语义，本类没实现。
                throw new \InvalidArgumentException('Http：multipart 只支持 POST（' . $method . ' 要走另一条流式语义，本类没实现）');
            }
            foreach ($headers as $name => $_) {
                // ★ 不许手设 Content-Type：multipart 的 Content-Type 必须带 boundary=，
                //   boundary 由本类生成。手设会顶掉 curl 自动补的 boundary ⇒
                //   服务端收到无法解析的表单。
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

        // ── 上传进度回调 ────────────────────────────────────────────────
        // 只对 multipart 有效；给了回调但没走 multipart ⇒ 当场抛
        //   （普通 body 一次性发出，没有中途进度观察点）。
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
            // CURLOPT_TIMEOUT_MS => 0 在 curl 里是"无限等待"，与直觉相反 ⇒ 挡掉。
            throw new \InvalidArgumentException('Http：connectTimeout / timeout 必须 > 0（0 在 curl 里表示"无限等"）');
        }

        $redirects = \max(0, (int) ($opts['followRedirects'] ?? 0));
        $expectOk = (bool) ($opts['expectOk'] ?? true);

        $ch = \curl_init($url);
        if ($ch === false) {
            // curl 初始化失败通常是部署包缺 extension=curl（安装问题，不是网络问题）。
            throw new HttpException('Http：curl 初始化失败（运行时 ini 没启 extension=curl？）：' . $url, $url);
        }

        /** @var array<string, string> $seen 最新一段响应头（重定向会重来一段） */
        $seen = [];

        $lines = [];
        foreach ($headers as $name => $value) {
            // ★ 值可以是数组（同名头多次出现合法）。直接拼会让 PHP 把数组转成
            //   "Array" 字符串发出去 —— 不报错，只是头是假的。
            foreach (\is_array($value) ? $value : [$value] as $one) {
                $lines[] = $name . ': ' . $one;
            }
        }
        if ($multipartType !== '') {
            // 必须在这里补 Content-Type：CURLOPT_POSTFIELDS 收到字符串时 curl 不
            //   自己加；少了 boundary= 服务端解不开。
            $lines[] = 'Content-Type: ' . $multipartType;
        }
        if ($payload !== '' || $multipart !== null) {
            // 显式禁掉 Expect: 100-continue：curl 对 >1KB body 会先等 1 秒看服务端。
            //   对端是 PHP 内置服务器时不回 100 ⇒ 每次 POST 白等 1s。
            $lines[] = 'Expect:';
        }

        \curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // 用毫秒版：秒版只表达整数，2.5s 会被截成 2s。
            CURLOPT_CONNECTTIMEOUT_MS => (int) \round($connectS * 1000.0),
            CURLOPT_TIMEOUT_MS => (int) \round($timeoutS * 1000.0),
            CURLOPT_FOLLOWLOCATION => $redirects > 0,
            CURLOPT_MAXREDIRS => $redirects,
            CURLOPT_HTTPHEADER => $lines,
            // 头用回调收：CURLOPT_HEADER => true 会把头混进 body。
            //   回调里"见到新状态行就清空"⇒ 留下的是最后一段。
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

        // ── TLS 信任根 ──────────────────────────────────────────────────
        // ★ 随 exe 分发的 php.ini 只写了 extension=…，没有 curl.cainfo /
        //   openssl.cafile ⇒ curl 信任库为空，任何 HTTPS 都过不去（错误码 60）。
        //   三档兜底（详见 tlsTrustOptions()）：① ini 配了且文件存在就用；
        //   ② 否则用随包 cacert.pem（构建时拷到 exe 旁，部署里唯一可靠来源）；
        //   ③ 再没有退到 Windows 系统证书库（NATIVE_CA）。
        \curl_setopt_array($ch, self::tlsTrustOptions(
            (string) \ini_get('curl.cainfo'),
            (string) \ini_get('openssl.cafile'),
            self::trustBundlePath(),
            \DIRECTORY_SEPARATOR === '\\',
        ));

        if ($multipart !== null) {
            // 流式上传：不设 CURLOPT_POSTFIELDS（会顶掉 READFUNCTION），改 UPLOAD +
            //   显式长度 + 读回调。内存只占一个块（curl 默认 64 KB）。
            //
            // PHP 没有 CURLOPT_POSTFIELDSIZE 常量；"POST + 流式 body"只能走
            //   UPLOAD + INFILESIZE + CUSTOMREQUEST('POST')。
            \curl_setopt($ch, CURLOPT_UPLOAD, true);
            \curl_setopt($ch, CURLOPT_INFILESIZE, $multipart->length());
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            // 读回调必须声明 3 个参数：curl 传 3 个，AOT 严格校验实参个数。
            //   进度在这里报（bytesSent() 自记账），与 length() 同口径。
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
            // 归一到 HttpException：Http 对外契约只有这一种失败，
            //   多一种异常类型就逼每个调用方多 catch 一个。
            throw new HttpException($method . ' ' . $url . ' → ' . $e->getMessage(), $url);
        } finally {
            // 文件句柄必须在这里关：curl 提前失败时 read() 不会吐完。
            if ($multipart !== null) {
                $multipart->close();
            }
        }

        $status = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string) \curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $elapsedMs = ((float) \curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000.0;

        if ($errno !== 0 || !\is_string($raw)) {
            // 传输层失败没有状态码（与"服务端说 500"是两回事，见 HttpException）。
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
     * TLS 信任根：决定给 curl 补哪几项（纯函数，输入都是外部世界）。
     *
     * ① ini 的 cainfo/cafile 指向真实存在的文件 ⇒ 用它；
     * ② 随包 cacert.pem 存在 ⇒ 用它（部署里唯一可靠来源）；
     * ③ 都没 + Windows ⇒ NATIVE_CA；④ 都没 + 非 Windows ⇒ 什么都不给
     *   （Linux/macOS 的 curl 自带编译期默认信任根）。
     * is_file 是必须的：ini 指向不存在的文件交给 curl 会 errno 77。
     *
     * @return array<int, int|string> curl 选项（键是 CURLOPT_*）
     * @internal
     */
    public static function tlsTrustOptions(string $cainfo, string $cafile, string $bundled, bool $windows): array
    {
        foreach ([$cainfo, $cafile, $bundled] as $candidate) {
            if ($candidate !== '' && \is_file($candidate)) {
                return [CURLOPT_CAINFO => $candidate];
            }
        }

        if (!$windows) {
            return [];
        }

        return [CURLOPT_SSL_OPTIONS => self::NATIVE_CA];
    }

    /**
     * 随包信任根在哪（assets/cacert.pem，构建时拷到 exe 旁）。
     *
     * 候选顺序：① exe 同目录（AOT）；② 仓库根；③ 仓库根/assets（解释器/单测）。
     * 找不到返回 ''，由 tlsTrustOptions() 退到系统证书库 —— 不是错误。
     *
     * @internal
     */
    public static function trustBundlePath(): string
    {
        $candidates = [];

        $argv = $_SERVER['argv'] ?? [];
        $exe = \is_array($argv) ? ($argv[0] ?? '') : '';
        if (\is_string($exe) && $exe !== '') {
            $candidates[] = \dirname($exe) . \DIRECTORY_SEPARATOR . 'cacert.pem';
        }

        $cwd = (string) \getcwd();
        $candidates[] = $cwd . \DIRECTORY_SEPARATOR . 'cacert.pem';
        $candidates[] = $cwd . \DIRECTORY_SEPARATOR . 'assets' . \DIRECTORY_SEPARATOR . 'cacert.pem';

        foreach ($candidates as $path) {
            if (\is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * CURLSSLOPT_NATIVE_CA 的值（curl 8.x = 16）。
     *
     * 不用常量名：老 curl 上 \defined() 为假时裸写常量名是 Undefined constant，
     *   会让退化行为变成直接崩。值由单测钉住。
     */
    private const NATIVE_CA = 16;

    /**
     * 造一个流式的 multipart/form-data 请求体，返回 [body, content-type]。
     *
     * 手拼而不用 CURLFile：避免 AOT 对未知内置类的风险，只用字符串 + 文件句柄。
     * 流式而非整块拼：内存 O(一个块)，不读全文件。
     *
     * @param array<string, mixed> $fields 普通字段：名 → 标量值
     * @param array<string, mixed> $files  文件字段：名 → 路径，或 [path, filename?, mime?]
     * @return array{MultipartBody, string}
     * @throws \InvalidArgumentException 字段名/值含 CRLF、文件不存在、体积超限
     */
    private static function buildMultipart(array $fields, array $files): array
    {
        // boundary 用随机值：固定 boundary 会让"字段值里含 --boundary"从不可能变可能。
        $boundary = '----DriftFormBoundary' . \bin2hex(\random_bytes(16));

        /** @var list<array{head: string, path: string, size: int}> $parts */
        $parts = [];
        $total = 0;

        foreach ($fields as $name => $value) {
            $name = self::assertNoCrlf((string) $name, 'fields 的名字');
            if (\is_array($value) || \is_object($value)) {
                throw new \InvalidArgumentException('Http：fields 的值必须是标量（\'' . $name . '\' 是 ' . \get_debug_type($value) . '）');
            }
            // 值里的 CRLF 能伪造额外的 part ⇒ 无论调用方是谁都拦下。
            $value = self::assertNoCrlf((string) $value, 'fields[\'' . $name . '\'] 的值');

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
                // 不能静默发空 part：OSS 返回 200 但 key 下是 0 字节，之后任务报"图片格式错误"。
                throw new \InvalidArgumentException('Http：files[\'' . $name . '\'] 指向的不是可读文件：' . $path);
            }
            if ($filename === '') {
                $filename = \basename($path);
            }
            if ($mime === '') {
                $ext = \strtolower((string) \pathinfo($path, PATHINFO_EXTENSION));
                $mime = self::MIME_BY_EXT[$ext] ?? 'application/octet-stream';
            }

            // 只用 filesize()，不读内容。clearstatcache 是必须的：
            //   PHP 按路径缓存 stat，刚写过的文件会拿到旧大小 ⇒ 请求体比声明短。
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
     * @throws \InvalidArgumentException 含 \r 或 \n
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
     * Content-Disposition 名字/文件名转义（RFC 7578 保守做法）。
     * 中文文件名不直接放：各家 UTF-8 解释不一致；" / \ 转 percent-encoding，
     *   其余字节原样（OSS 不看 filename，对象名由 key 决定）。
     */
    private static function quoteDisposition(string $value): string
    {
        return \str_replace(['\\', '"', "\r", "\n"], ['%5C', '%22', '%0D', '%0A'], $value);
    }
}
