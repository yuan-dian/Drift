<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Io\Http;
use Drift\App\Io\HttpException;
use Drift\App\Io\HttpResponse;
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
}
