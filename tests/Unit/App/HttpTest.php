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

        $base = \memory_get_usage(true);
        $peak = 0;
        $sent = 0;
        while (($chunk = $stream->read(65536)) !== '') {
            $sent += \strlen($chunk);
            // ★ 不累加、不留引用：curl 的真实行为也是这样（发完就丢）。
            //   如果这里攒起来，测的就成了"测试自己占多少内存"。
            $peak = \max($peak, \memory_get_usage(true) - $base);
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
}
