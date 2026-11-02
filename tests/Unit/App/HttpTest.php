<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Io\Http;
use Drift\App\Io\HttpException;
use Drift\App\Io\HttpResponse;
use Drift\App\Io\MultipartBody;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `Http` 的**参数面**与**失败面**（2026-10-31）。
 *
 * ## 为什么这个文件一条网络都不发
 *
 * `Http` 的价值全在"把一次阻塞往返变成两个说得清的结果"：要么 `HttpResponse`，
 * 要么 `HttpException`（带不带状态码分两档）。而这套判断里，**参数校验**与
 * **传输失败的形状**都能离线判死：
 *
 * | 判据 | 挡的退化 |
 * |---|---|
 * | 选项名拼错**当场抛** | `'timeout'` 拼成 `'timeOut'` ⇒ 静默用默认 10s，调完才发现 |
 * | 动词非法当场抛 | `Http::request('GETT', …)` 让 curl 猜，最后变成一次奇怪的 400 |
 * | `post()` 里 `$opts['body']` 与第二参数打架要抛 | 两个 body 谁生效取决于实现顺序 |
 * | 传输失败 `status() === null` | 与"服务端说 500"混成一档 ⇒ 调用方无法决定要不要重试 |
 * | 失败消息里带 URL | 一个页面三条请求失败，日志里分不清是哪一条 |
 *
 * ★ **真实的 200 / 404 / 重定向 / POST body** 在 `tools/smoke.php` ㊶c 段（真 socket、
 *   真 `php -S`）。这里发**一条**请求：`http://`（没有 host）—— 纯语法失败，
 *   不出网、不查 DNS，见 `testTransportFailureHasNoStatus()` 的注释。
 */
final class HttpTest extends SmokeTestCase
{
    public function testUnknownOptionIsRejected(): void
    {
        $threw = null;
        try {
            Http::get('http://example.test/x', ['timeOut' => 1.0]);
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }

        check(
            'Http · 选项名拼错 ⇒ InvalidArgumentException（不静默用默认值）',
            $threw instanceof \InvalidArgumentException,
            $threw === null ? '没抛' : '抛了',
        );
        check(
            'Http · 消息里点名那个拼错的键',
            $threw !== null && str_contains($threw->getMessage(), 'timeOut'),
            $threw === null ? '(无异常)' : $threw->getMessage(),
        );

        $this->flushChecks();
    }

    public function testBadMethodIsRejected(): void
    {
        $threw = null;
        try {
            Http::request('GETT', 'http://example.test/x');
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }

        check('Http · 动词非法 ⇒ InvalidArgumentException', $threw instanceof \InvalidArgumentException, $threw === null ? '没抛' : '抛了');

        $this->flushChecks();
    }

    public function testEmptyUrlIsRejected(): void
    {
        $threw = null;
        try {
            Http::get('   ');
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }

        check('Http · 空 URL ⇒ InvalidArgumentException', $threw instanceof \InvalidArgumentException, $threw === null ? '没抛' : '抛了');

        $this->flushChecks();
    }

    public function testPostRejectsBodyInOptions(): void
    {
        $threw = null;
        try {
            Http::post('http://example.test/x', '{"a":1}', ['body' => '{"b":2}']);
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }

        check(
            'Http · post() 的 body 只能走第二参数（两处给 body 要抛，不能靠实现顺序决定谁赢）',
            $threw instanceof \InvalidArgumentException,
            $threw === null ? '没抛' : '抛了',
        );

        $this->flushChecks();
    }

    /**
     * 三处"curl 会静默照做"的坑 —— 都要在前置校验里挡掉。
     *
     * ★ 它们的共同点：**不抛的后果都不是"报错"**，而是"请求发出去了但内容不对"，
     *   或者"永远不超时"。那类 bug 只能靠肉眼发现，而肉眼看不出"头里少了 Accept"。
     */
    public function testSilentCurlTrapsAreRejected(): void
    {
        $cases = [
            'GET 带 body' => static fn (): HttpResponse => Http::get('http://example.test/x', ['body' => 'x']),
            'headers 不是数组' => static fn (): HttpResponse => Http::get('http://example.test/x', ['headers' => 'Accept: */*']),
            'body 不是字符串' => static fn (): HttpResponse => Http::request('POST', 'http://example.test/x', ['body' => ['a' => 1]]),
            'timeout = 0（curl 里是"无限等"）' => static fn (): HttpResponse => Http::get('http://example.test/x', ['timeout' => 0]),
            'connectTimeout < 0' => static fn (): HttpResponse => Http::get('http://example.test/x', ['connectTimeout' => -1]),
        ];

        foreach ($cases as $label => $call) {
            $threw = null;
            try {
                $call();
            } catch (\InvalidArgumentException $e) {
                $threw = $e;
            }
            check('Http · ' . $label . ' ⇒ InvalidArgumentException', $threw !== null, $threw === null ? '没抛' : '抛了');
        }

        $this->flushChecks();
    }

    public function testTransportFailureHasNoStatus(): void
    {
        // ★★ 为什么不用"解析不了的域名"（最初就是这么写的）：那**不是**确定性的。
        //   同一台机器上，直连的环境里 `*.invalid` 立刻 `Could not resolve host`，
        //   而闸门跑起来的环境里解析被接管 ⇒ 拿回一个 **HTTP 403**（真有人应答），
        //   于是这条判据在闸门里红、在手动跑时绿。**量具换环境就翻面 = 判据不成立。**
        //   ⇒ 换成纯**语法**失败：`http://`（没有 host）。它不出一个字节的网、
        //   不查 DNS、不看端口开闭，curl 直接回 `CURLE_URL_MALFORMAT`。
        //   这与 DNS 失败走的是**同一条**代码路径（`$errno !== 0` ⇒ 抛、`status` 留 null），
        //   所以它判的仍然是"传输层失败"那一档，不是"参数校验"那一档。
        $threw = null;
        $t0 = microtime(true);
        try {
            Http::get('http://', ['connectTimeout' => 1.0, 'timeout' => 1.0]);
        } catch (HttpException $e) {
            $threw = $e;
        }
        $ms = (microtime(true) - $t0) * 1000.0;

        check('Http · 拿不到响应 ⇒ HttpException', $threw instanceof HttpException, $threw === null ? '没抛' : '抛了');
        check(
            'Http · 传输层失败 status() === null（与"服务端 500"分得开）',
            $threw !== null && $threw->status() === null,
            var_export($threw?->status(), true),
        );
        check(
            'Http · 失败消息带 URL（一个页面三条请求失败时能归因）',
            $threw !== null && str_contains($threw->getMessage(), 'http://'),
            $threw === null ? '(无异常)' : $threw->getMessage(),
        );
        check('Http · 失败要**早**（1s 超时内就回来，不是等满）', $ms < 1500.0, sprintf('%.0fms', $ms));

        $this->flushChecks();
    }

    /**
     * multipart 的**参数面**（2026-11-02，第一个消费者是 `apps/watermark` 的 OSS 直传）。
     *
     * ★ 这一组挡的全是"发出去但内容不对"的退化 —— 它们**不会**报错：
     *   | 退化 | 表现 |
     *   |---|---|
     *   | `body` 与 `fields` 一起给 | 谁生效取决于实现顺序，另一份静默丢弃 |
     *   | 手设 `Content-Type` | boundary 被顶掉 ⇒ 服务端"缺少字段"，报的不是格式错 |
     *   | 文件路径不存在 | 发一个 0 字节 part ⇒ OSS 回 200，之后任务报"图片格式错误" |
     *   | 字段值含 `\r\n` | **能伪造出额外的 part** —— 一次拼接失误就是一个注入面 |
     */
    public function testMultipartRejectsSilentDegradations(): void
    {
        $tmp = (string) \tempnam(\sys_get_temp_dir(), 'drift-mp-');
        \file_put_contents($tmp, 'x');

        // ★ 每个 case 都带一个"消息里必须出现的关键词"。
        //   只判 `$threw !== null` 是**假绿**：删掉前置校验之后，后面那道兜底
        //   （`file_get_contents` 读不出 ⇒ 抛）会顶上，异常类型一样、测试照样绿，
        //   而语义已经从"发请求前就挡住"退化成"半路才发现"——**错误发生的位置**
        //   才是这条判据要钉的东西。变异 ⑤ 就是这么发现判据太弱的。
        $cases = [
            'body 与 fields 一起给' => [
                static fn (): HttpResponse => Http::request('POST', 'http://example.test/x', [
                    'body' => 'raw', 'fields' => ['a' => '1'],
                ]),
                'multipart 用 fields / files',
            ],
            '手设 Content-Type' => [
                static fn (): HttpResponse => Http::request('POST', 'http://example.test/x', [
                    'fields' => ['a' => '1'], 'headers' => ['Content-Type' => 'multipart/form-data'],
                ]),
                'Content-Type 由本类生成',
            ],
            '文件不存在' => [
                static fn (): HttpResponse => Http::request('POST', 'http://example.test/x', [
                    'files' => ['file' => '/no/such/file-' . \bin2hex(\random_bytes(4)) . '.jpg'],
                ]),
                '不是可读文件',
            ],
            '字段值含 CRLF' => [
                static fn (): HttpResponse => Http::request('POST', 'http://example.test/x', [
                    'fields' => ['a' => "1\r\n--evil"],
                ]),
                '不能有换行',
            ],
            '字段名含 CRLF' => [
                static fn (): HttpResponse => Http::request('POST', 'http://example.test/x', [
                    'fields' => ["a\r\nb" => '1'],
                ]),
                '不能有换行',
            ],
            'GET 带 multipart' => [
                static fn (): HttpResponse => Http::get('http://example.test/x', ['fields' => ['a' => '1']]),
                'multipart 只支持 POST',
            ],
            // ★ 2026-11-02：进度回调只在 multipart 时才有观察点。收下却不生效
            //   ⇒ 调用方的进度条永远停在 0（`body` 是一次性发出去的）。
            'onProgress 但没 multipart' => [
                static fn (): HttpResponse => Http::post('http://example.test/x', 'raw', [
                    'onProgress' => static function (float $f): void {
                    },
                ]),
                '只在 multipart',
            ],
            'onProgress 不是 callable' => [
                static fn (): HttpResponse => Http::request('POST', 'http://example.test/x', [
                    'fields' => ['a' => '1'], 'onProgress' => 'not-a-function',
                ]),
                '必须是 callable',
            ],
        ];

        foreach ($cases as $label => [$call, $needle]) {
            // ★ 捕 `\Throwable` 而不是只捕 `InvalidArgumentException`：变异之后请求
            //   真的发出去了（`example.test` 解析失败 ⇒ `HttpException`），只捕前者
            //   会让测试变成 **ERROR** 而不是 FAIL —— 同样是红，但读起来像"测试写坏了"。
            $threw = null;
            try {
                $call();
            } catch (\Throwable $e) {
                $threw = $e;
            }
            check(
                'Http · multipart：' . $label . ' ⇒ InvalidArgumentException',
                $threw instanceof \InvalidArgumentException,
                $threw === null ? '没抛' : \get_class($threw) . '：' . $threw->getMessage(),
            );
            check(
                'Http · multipart：' . $label . ' 的**理由**对（消息含「' . $needle . '」）',
                $threw instanceof \InvalidArgumentException && \str_contains($threw->getMessage(), $needle),
                $threw === null ? '(无异常)' : $threw->getMessage(),
            );
        }

        @\unlink($tmp);

        $this->flushChecks();
    }

    /**
     * multipart 的**字节面** —— 上面那组"抛不抛"全绿**不等于**"拼对了"。
     *
     * ★ 为什么必须直测私有方法：`buildMultipart()` 的产物是**协议字节**，任何一处
     *   写成 `"\n"` 而不是 `"\r\n"`、任何一处少了空行，PHP 自己的解析器可能宽容，
     *   而别的服务端直接判"缺少字段"。这类退化在端到端判据里表现为**别的错**
     *   （字段缺失 / 0 字节对象），归因会跑偏两个系统。
     */
    public function testMultipartBytesAreWellFormed(): void
    {
        $tmp = (string) \tempnam(\sys_get_temp_dir(), 'drift-mp-');
        // ★ payload **故意含换行**（`\r\n` 与裸 `\n` 都有）：这样 `fopen(..., 'rb')`
        //   漏掉 `b` 的变异才抓得住 —— Windows 文本模式会把 `\n` 翻译成 `\r\n`，
        //   图片/二进制文件被悄悄改字节，服务端拿到的是"能打开但内容不对"。
        //   第一版 payload 全是 `X`，这条判据是**无牙**的（变异 ④ 实测全绿）。
        $payload = "BINARY\r\nLINE2\n" . \str_repeat('X', 64);
        \file_put_contents($tmp, $payload);

        $method = new \ReflectionMethod(Http::class, 'buildMultipart');
        /** @var array{MultipartBody, string} $out */
        $out = $method->invoke(null, ['键' => '值', 'k2' => 'v2'], ['file' => $tmp]);
        [$stream, $type] = $out;

        // 小文件 ⇒ 一次读完，拼成完整 body 再判结构
        $body = '';
        while (($chunk = $stream->read(65536)) !== '') {
            $body .= $chunk;
        }
        $stream->close();

        $boundary = '';
        if (\preg_match('/boundary=(.+)$/', $type, $m) === 1) {
            $boundary = $m[1];
        }

        check('Http · multipart：Content-Type 带 boundary', $boundary !== '', $type);
        check(
            // 3 个 part（2 字段 + 1 文件）各有一个 `--boundary` 头，结尾那个 `--boundary--`
            // 里也含一次 ⇒ 4。数错成 3 会让人以为"少了一个 part"。
            'Http · multipart：4 处 boundary（3 个 part 头 + 1 个结尾）',
            $boundary !== '' && \substr_count($body, '--' . $boundary) === 4,
            $boundary === '' ? '(无 boundary)' : (string) \substr_count($body, '--' . $boundary),
        );
        check(
            'Http · multipart：以 `--boundary--` + CRLF 收尾（少了这个服务端会等下一个 part）',
            $boundary !== '' && \str_ends_with($body, '--' . $boundary . "--\r\n"),
            $boundary === '' ? '(无 boundary)' : \substr($body, -60),
        );
        check(
            'Http · multipart：文件字节原样在体内（含 `\r\n` / 裸 `\n`，验 `rb` 真的没翻译换行）',
            \str_contains($body, $payload),
            'len=' . \strlen($body),
        );
        // ★ 裸 `\n`（前面不是 `\r`）在 multipart 的**结构**里就是格式错：有些实现宽容，
        //   有些直接 400。判之前先把文件内容抠掉 —— 否则 payload 里的 `\n` 会污染判据
        //   （第一版就是这么假红的，而红的原因与"我们拼错了 CRLF"毫无关系）。
        $structure = \str_replace($payload, '', $body);
        $bareLf = (int) \preg_match_all('/(?<!\r)\n/', $structure);
        check('Http · multipart：结构里没有裸 LF（全是 CRLF）', $bareLf === 0, 'bareLF=' . $bareLf);
        check(
            'Http · multipart：文件 part 带 filename 与 Content-Type',
            \str_contains($body, 'filename="' . \basename($tmp) . '"')
                && \str_contains($body, 'Content-Type: application/octet-stream'),
            'basename=' . \basename($tmp),
        );
        check(
            'Http · multipart：`length()` 与真实吐出的字节数**逐字节一致**'
            . '（不一致 ⇒ POSTFIELDSIZE 与实体长度不符 ⇒ 服务端挂住或报连接重置）',
            \strlen($body) === $stream->length(),
            '吐出=' . \strlen($body) . ' length()=' . $stream->length(),
        );
        // ★ `bytesSent()` 是上传进度的分子 ⇒ 吐完之后必须**正好**等于 `length()`。
        //   少 2 字节（漏了文件后的分隔 CRLF）在这里是"进度停在 99.9%"，而
        //   `strlen($body)` 那条判据抓不到"记账"和"实际"分开走样的情形。
        check(
            'Http · multipart：吐完后 `bytesSent()` === `length()`（进度分子与分母同口径）',
            $stream->bytesSent() === $stream->length(),
            'bytesSent=' . (string) $stream->bytesSent() . ' length()=' . (string) $stream->length(),
        );

        @\unlink($tmp);

        $this->flushChecks();
    }

    /**
     * 上传进度：`bytesSent()` 单调递增、最后到 1.0（2026-11-02）。
     *
     * ★ 判据落在**逐块观察**上：只看最后的值，一个"每块都报 0.99"的实现照样绿。
     * ★ 也钉住"第一块就已经 > 0"：`read()` 第一次就把 part 头吐出去了，
     *   所以进度条在第一个块之后就该动 —— 否则用户会看到"点了上传没反应"。
     */
    public function testMultipartReportsMonotonicProgress(): void
    {
        $tmp = (string) \tempnam(\sys_get_temp_dir(), 'drift-mp-prog-');
        \file_put_contents($tmp, \str_repeat('P', 300 * 1024));

        $method = new \ReflectionMethod(Http::class, 'buildMultipart');
        /** @var array{MultipartBody, string} $out */
        $out = $method->invoke(null, ['a' => '1'], ['file' => $tmp]);
        [$stream] = $out;

        $total = $stream->length();
        $fractions = [];
        $lastSent = 0;
        $monotonic = true;

        // 小块读 ⇒ 多次回调（模拟 curl 的 64 KB 块）
        while (($chunk = $stream->read(64 * 1024)) !== '') {
            $sent = $stream->bytesSent();
            if ($sent < $lastSent) {
                $monotonic = false;
            }
            $lastSent = $sent;
            $fractions[] = $total > 0 ? $sent / $total : 0.0;
        }
        $stream->close();

        check(
            'Http · 进度：bytesSent() 单调不减（倒退 ⇒ 进度条会往回跳）',
            $monotonic,
            'sent=' . (string) $lastSent,
        );
        check(
            'Http · 进度：至少回调 2 次（一次读完的实现看不出进度）',
            \count($fractions) >= 2,
            '次数=' . (string) \count($fractions),
        );
        check(
            'Http · 进度：最后一块到 1.0（停在 0.99 ⇒ 用户以为没传完）',
            $fractions !== [] && \abs($fractions[\count($fractions) - 1] - 1.0) < 1e-9,
            $fractions === [] ? '(没有回调)' : \sprintf('%.6f', $fractions[\count($fractions) - 1]),
        );
        check(
            'Http · 进度：第一块就 > 0（part 头在第一次 read 里就吐出去了）',
            $fractions !== [] && $fractions[0] > 0.0,
            $fractions === [] ? '(没有回调)' : \sprintf('%.6f', $fractions[0]),
        );

        @\unlink($tmp);

        $this->flushChecks();
    }

    /**
     * **流式**的判据：内存不随文件大小增长（2026-11-02）。
     *
     * ★ 这条判据的存在理由：第一版把整个表单拼进 `string`，8 MB 的文件要吃
     *   16~24 MB 内存。用户一眼看出"先读进内存不是最优"，而 lint 也在同一处
     *   报了 `str-concat-loop` —— 两条独立线索指向同一个错。
     *
     * ★ 为什么不用 `memory_get_peak_usage()`：峰值**只增不减**，前面任何一条测试
     *   把它推高了，这里就恒绿（假绿）。改成"读的过程中观察**当前**占用的最大值"，
     *   判据才有牙：改回 `file_get_contents` 会让它跳满一个文件大小。
     */
    public function testMultipartStreamsWithoutHoldingTheFile(): void
    {
        $size = 8 * 1024 * 1024;
        $tmp = (string) \tempnam(\sys_get_temp_dir(), 'drift-mp-big-');
        $fh = \fopen($tmp, 'wb');
        if ($fh !== false) {
            // 分块写，别在测试里再造一个 8 MB 的字符串
            $block = \str_repeat('B', 64 * 1024);
            for ($i = 0; $i < $size / 65536; $i++) {
                \fwrite($fh, $block);
            }
            \fclose($fh);
        }

        $method = new \ReflectionMethod(Http::class, 'buildMultipart');
        /** @var array{MultipartBody, string} $out */
        $out = $method->invoke(null, ['k' => 'v'], ['file' => $tmp]);
        [$stream] = $out;

        $base = \memory_get_usage();
        $peak = 0;
        $sent = 0;
        while (($chunk = $stream->read(65536)) !== '') {
            $sent += \strlen($chunk);
            // ★ 不累加、不留引用：curl 的真实行为也是这样（发完就丢）。
            //   如果这里攒起来，测的就成了"测试自己占多少内存"。
            // ★ 用 `memory_get_usage()`（实际使用量）而不是 `(true)`（页对齐后的
            //   分配量）：后者按 2 MB chunk 向上取整，读文件时一跨页增量就正好
            //   是 2 MB，于是"增量 < 2 MB"这条**必然**在别人占过的池子上失败
            //   （2026-11-02 全量跑实测：单跑绿、全量红，峰值恒为 2 MB）。
            $peak = \max($peak, \memory_get_usage() - $base);
        }
        $stream->close();

        check(
            'Http · multipart：8 MB 文件**没有**被整份读进内存（增量 < 2 MB）',
            $peak < 2 * 1024 * 1024,
            '峰值增量=' . \round($peak / 1048576, 2) . ' MB（文件 ' . \round($size / 1048576, 2) . ' MB）',
        );
        check(
            'Http · multipart：流式吐出的总字节 = 文件 + 表单头尾',
            $sent === $stream->length() && $sent > $size && $sent < $size + 4096,
            '吐出=' . $sent . ' length()=' . $stream->length() . ' 文件=' . $size,
        );

        @\unlink($tmp);

        $this->flushChecks();
    }

    /**
     * TLS 信任根的决策表（2026-11-02，`apps/watermark` 的第一次真 HTTPS 打出来的）。
     *
     * ## 这条判据挡的是什么
     *
     * AOT 产物随包的 `php.ini` **只有** `extension=…`，没有 `curl.cainfo` ⇒ curl
     * 的信任库是空的 ⇒ 任何 HTTPS 都失败：
     *
     * ```
     * POST https://…oss… → SSL certificate problem: unable to get local issuer certificate (20)
     * ```
     *
     * 而**同一个应用的网关是 `http://`** ⇒ 签名 / 建任务全过、只有上传挂，看起来
     * 像"OSS 拒绝我们"。所以这条判据必须钉在**决策**上（发不发得出去是网络的事，
     * 而"该不该改信任根"是纯逻辑）。
     *
     * ## 为什么 `is_file()` 那一条不是防御性编程
     *
     * 开发机的 php.ini 把 `curl.cainfo` 指向 `C:\tools\php\ssl\cacert.pem`，而
     * **那个文件不存在** ⇒ 交给 curl 用会得到 `errno 77: error adding trust anchors`。
     * 实测三种组合（本机）：ini 原值 → 77；清空 → 77；清空 + NATIVE_CA → ok。
     * 所以"有值"不等于"可用"，必须验文件。
     */
    public function testTlsTrustDecisions(): void
    {
        $ca = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'drift-ca-test-' . \bin2hex(\random_bytes(4)) . '.pem';
        $bundle = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'drift-bundle-test-' . \bin2hex(\random_bytes(4)) . '.pem';
        \file_put_contents($ca, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");
        \file_put_contents($bundle, "-----BEGIN CERTIFICATE-----\nMIIC\n-----END CERTIFICATE-----\n");

        // ① 有可用的 cainfo ⇒ 显式用它，且**不要**掺 native（两者同时给，谁赢没定义）
        $opts = Http::tlsTrustOptions($ca, '', $bundle, true);
        check(
            'Http · TLS：ini 给了可用 CA 文件 ⇒ 用 CURLOPT_CAINFO，不掺 native',
            $opts === [CURLOPT_CAINFO => $ca],
            \json_encode($opts, \JSON_UNESCAPED_SLASHES) . '（期望 CAINFO=' . $ca . '）',
        );

        // ② cainfo 指向不存在的文件 ⇒ 跳过它，退到 cafile（**开发机的真实状态**）
        $opts = Http::tlsTrustOptions('/no/such/ca.pem', $ca, $bundle, true);
        check(
            'Http · TLS：cainfo 指向不存在的文件 ⇒ 跳过它，用 openssl.cafile',
            $opts === [CURLOPT_CAINFO => $ca],
            \json_encode($opts, \JSON_UNESCAPED_SLASHES),
        );

        // ③ 随包证书包**优先于系统库**：Windows 的 NATIVE_CA 只认「机器/用户装过的根」，
        //    碰到企业代理签发的中间 CA 或系统库缺根时会失败；随包 cacert.pem 是可复现的。
        $opts = Http::tlsTrustOptions('', '', $bundle, true);
        check(
            'Http · TLS：ini 全空但随包证书包可用 ⇒ 用随包（**不**退系统库）',
            $opts === [CURLOPT_CAINFO => $bundle],
            \json_encode($opts, \JSON_UNESCAPED_SLASHES) . '（期望 CAINFO=' . $bundle . '）',
        );

        // ④ 三个都没有 + Windows ⇒ 改用系统证书库
        $opts = Http::tlsTrustOptions('', '', '', true);
        $native = \defined('CURLSSLOPT_NATIVE_CA') ? \CURLSSLOPT_NATIVE_CA : -1;
        check(
            'Http · TLS：没有可用 CA 文件 + Windows ⇒ 改用系统证书库（NATIVE_CA）',
            $opts === [CURLOPT_SSL_OPTIONS => $native] && $native !== -1,
            \json_encode($opts, \JSON_UNESCAPED_SLASHES) . '（期望 SSL_OPTIONS=' . $native . '）',
        );

        // ⑤ 三个都没有 + 非 Windows ⇒ **什么都不动**（Linux 的 curl 自带默认信任根）
        $opts = Http::tlsTrustOptions('', '', '', false);
        check(
            'Http · TLS：非 Windows 且没有 ini 配置 ⇒ 一项都不加（别动发行版的默认信任根）',
            $opts === [],
            \json_encode($opts, \JSON_UNESCAPED_SLASHES),
        );

        // ⑥ 随包文件存在但**指向不存在**的路径 ⇒ 必须当作没有（"有值" ≠ "可用"）
        $opts = Http::tlsTrustOptions('', '', '/no/such/bundle.pem', true);
        check(
            'Http · TLS：随包路径不可用 ⇒ 跳过它，退系统库',
            $opts === [CURLOPT_SSL_OPTIONS => $native] && $native !== -1,
            \json_encode($opts, \JSON_UNESCAPED_SLASHES),
        );

        // ⑦ 字面量钉住：`Http::NATIVE_CA = 16` 是**手写**的（理由见该常量注释），
        //    所以必须有东西在 curl 换值时报出来。
        check(
            'Http · TLS：手写的 NATIVE_CA 字面量与 PHP 导出的常量一致',
            $native === 16,
            'CURLSSLOPT_NATIVE_CA=' . $native . '（写成 16；不一致就要改 Http::NATIVE_CA）',
        );

        @\unlink($ca);
        @\unlink($bundle);

        $this->flushChecks();
    }

    /**
     * 随包信任根**真的能用**（2026-11-02，用户指定的 `D:\PHP\bin\cacert\cacert.pem`）。
     *
     * ## 为什么这条不能只查"文件在不在"
     *
     * 一个 0 字节 / 被截断 / 全是注释的 `cacert.pem` 也能通过 `is_file()` 和
     * `substr_count('BEGIN CERTIFICATE')`（截断到只剩头）。唯一能证伪的是**拿它
     * 真握手一次**。所以这里发一个真请求到 OSS 桶根。
     *
     * ## 判据为什么不是「200」
     *
     * 桶根未授权时 OSS 返回 **403** —— 那是应用层拒绝，恰恰证明 TCP + TLS 握手 +
     * 证书链校验**全都过了**（校验失败根本走不到 HTTP 状态码）。判 `status > 0`
     * 让这条判据不依赖"OSS 今天是否允许匿名访问"。
     */
    public function testBundledTrustStoreIsPresent(): void
    {
        $bundle = Http::trustBundlePath();
        check(
            'Http · TLS：随包 cacert.pem 能被定位到',
            $bundle !== '' && \is_file($bundle),
            'trustBundlePath()=' . $bundle . '（getcwd=' . \getcwd() . '）',
        );

        if ($bundle === '' || !\is_file($bundle)) {
            $this->flushChecks();
            return;
        }

        $pem = (string) \file_get_contents($bundle);
        $count = \substr_count($pem, 'BEGIN CERTIFICATE');
        check(
            'Http · TLS：随包证书包含 ≥100 张根证书（不是空壳 / 不是截断）',
            $count >= 100,
            '证书数=' . $count . '，字节=' . \strlen($pem),
        );

        $ok = $this->handshakeWith($bundle);
        check(
            'Http · TLS：用随包证书包对 OSS 真握手成功（拿到 HTTP 状态码即算过）',
            $ok,
            '握手结果=' . ($ok ? 'ok' : '失败') . '（errno 见下）',
        );

        $this->flushChecks();
    }

    /**
     * 用指定 CA 包发一次真实 HTTPS 请求，判据 = `errno === 0 && status > 0`。
     *
     * 不用 `Http::get()`（它自己会挑信任根，测不出"这个包能不能用"）。
     */
    private function handshakeWith(string $caFile): bool
    {
        $ch = \curl_init('https://kuque-ai-beta.oss-cn-hangzhou.aliyuncs.com/');
        if ($ch === false) {
            return false;
        }
        \curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CAINFO => $caFile,
        ]);
        \curl_exec($ch);
        $errno = \curl_errno($ch);
        $status = (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        // 不调 `curl_close()`：PHP 8.5 起已废弃（8.0 起就是空操作）⇒ 会带一条 deprecation
        return $errno === 0 && $status > 0;
    }
}
