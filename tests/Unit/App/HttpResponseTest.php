<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Io\HttpException;
use Drift\App\Io\HttpResponse;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `HttpResponse` —— 一次 HTTP 响应的值（2026-10-31）。
 *
 * ## 判据只落在"读法"上，不落在"能不能存"
 *
 * 存一个状态码 + 一段字节，任何数组都能做。`HttpResponse` 存在的理由是**读**：
 * 头名大小写、`ok()` 的边界、JSON 坏了要响、body 摘要要能进日志。所以下面每条
 * 都在问"这个读法会不会骗人"：
 *
 * | 判据 | 挡的退化 |
 * |---|---|
 * | `ok()` 只认 200–299（3xx 不算） | 把 302 当成功 ⇒ 解析一段 `<html>Moved</html>` |
 * | 头名大小写不敏感 | `Content-Type` / `content-type` 变成两把钥匙 |
 * | 同名头按 `", "` 合并 | 重定向链上 Set-Cookie 互相覆盖，只剩最后一个 |
 * | 坏 JSON **抛**（不返回 null） | 调用方 `if ($data === null)` 分不清"空"与"坏了" |
 * | 合法的 `null` 不抛 | 把"服务端回了 null"当成事故 |
 * | 摘要压换行 + 按**字符**截断 | 一条异常消息被 200 个换行切成一片，或截出半个汉字 |
 *
 * ★ 真实的 200 / 404 / 重定向往返在 `tools/smoke.php` ㊻ 段（真 socket、真 `php -S`）。
 *   这里一条网络都不发 —— 纯值对象就该能离线判死。
 */
final class HttpResponseTest extends SmokeTestCase
{
    /** 造一个只有状态码与 body 够用的响应（其余字段取固定值） */
    private static function res(int $status, string $body = '', array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, $body, 'http://example.test/x', 12.5);
    }

    public function testOkCoversOnly2xx(): void
    {
        foreach ([200, 204, 226, 299] as $status) {
            check('HttpResponse · ' . $status . ' ⇒ ok', self::res($status)->ok(), 'status=' . $status);
        }

        // ★ 3xx 单独列：`Http` 默认不跟随重定向，所以 3xx 落到这里意味着"东西在别处"，
        //   当成功会让调用方去解析一段空 body。
        foreach ([199, 301, 304, 400, 404, 500, 599] as $status) {
            check('HttpResponse · ' . $status . ' ⇒ 不 ok', !self::res($status)->ok(), 'status=' . $status);
        }

        $this->flushChecks();
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $res = new HttpResponse(
            200,
            ['Content-Type' => 'application/json', 'X-Total-Count' => '42'],
            '{}',
            'http://example.test/x',
            1.0,
        );

        check('HttpResponse · 原样名取得着', $res->header('Content-Type') === 'application/json', (string) $res->header('Content-Type'));
        check('HttpResponse · 全小写取得着', $res->header('content-type') === 'application/json', (string) $res->header('content-type'));
        check('HttpResponse · 全大写取得着', $res->header('CONTENT-TYPE') === 'application/json', (string) $res->header('CONTENT-TYPE'));
        check('HttpResponse · headers() 的键是小写', array_keys($res->headers()) === ['content-type', 'x-total-count'], implode(',', array_keys($res->headers())));
        check(
            'HttpResponse · 没有这个头 ⇒ null（不是空串 —— 空串是合法值）',
            $res->header('x-missing') === null,
            var_export($res->header('x-missing'), true),
        );

        $this->flushChecks();
    }

    public function testDuplicateHeadersAreMerged(): void
    {
        // 同一段响应里出现两次同名头（大小写不同 ⇒ 数组里是两个键）——
        // 重定向链上 Set-Cookie 就是这样，覆盖式写法会只剩最后一个。
        $res = new HttpResponse(
            200,
            ['Set-Cookie' => 'a=1', 'set-cookie' => 'b=2'],
            '',
            'http://example.test/x',
            1.0,
        );

        check('HttpResponse · 同名头按 ", " 合并', $res->header('set-cookie') === 'a=1, b=2', (string) $res->header('set-cookie'));

        $this->flushChecks();
    }

    public function testJsonDecodesAndThrowsOnGarbage(): void
    {
        check(
            'HttpResponse · 合法 JSON ⇒ 关联数组',
            self::res(200, '{"a":1,"b":[2,3]}')->json() === ['a' => 1, 'b' => [2, 3]],
            'n/a',
        );

        // ★ 合法的 `null` 与"坏了"是两件事：前者不抛（服务端就是回了 null），
        //   后者必须抛（否则调用方分不清"没数据"与"解析失败"）。
        check('HttpResponse · body 是合法 null ⇒ 返回 null 不抛', self::res(200, 'null')->json() === null, 'n/a');

        $threw = null;
        try {
            self::res(500, '<html>boom</html>')->json();
        } catch (HttpException $e) {
            $threw = $e;
        }
        check('HttpResponse · 坏 JSON ⇒ 抛 HttpException', $threw instanceof HttpException, $threw === null ? '没抛' : '抛了');
        check('HttpResponse · 异常带状态码（能分辨"解析失败"与"服务端 500"）', $threw?->status() === 500, var_export($threw?->status(), true));
        check(
            'HttpResponse · 异常消息带 body 现场',
            $threw !== null && str_contains($threw->getMessage(), '<html>boom</html>'),
            $threw === null ? '(无异常)' : $threw->getMessage(),
        );

        $this->flushChecks();
    }

    public function testSnippetFlattensAndTruncatesByCharacter(): void
    {
        check(
            'HttpResponse · 摘要把换行压成空格',
            self::res(200, "a\n\nb\r\nc")->snippet() === 'a b c',
            self::res(200, "a\n\nb\r\nc")->snippet(),
        );

        $long = str_repeat('x', 300);
        $snippet = self::res(200, $long)->snippet();
        check('HttpResponse · 超长按 200 字截断并加省略号', mb_strlen($snippet, 'UTF-8') === 201 && str_ends_with($snippet, '…'), mb_strlen($snippet, 'UTF-8') . ' 字');

        // ★ 按**字节**截会把一个汉字劈成两半 ⇒ 这条消息进 `json_encode` 时整条回程编码失败，
        //   症状从"任务失败"变成"encode failed"。
        $chinese = str_repeat('汉', 300);
        $cut = self::res(200, $chinese)->snippet();
        check('HttpResponse · 中文截断不劈字（仍是合法 UTF-8）', preg_match('//u', $cut) === 1, 'invalid utf-8');
        check('HttpResponse · 中文截断后仍是 200 个汉字 + 省略号', mb_strlen($cut, 'UTF-8') === 201, mb_strlen($cut, 'UTF-8') . ' 字');

        $this->flushChecks();
    }

    public function testAccessorsPassThrough(): void
    {
        $res = new HttpResponse(404, [], 'nope', 'http://example.test/final', 33.25);

        check('HttpResponse · status', $res->status() === 404, (string) $res->status());
        check('HttpResponse · body', $res->body() === 'nope', $res->body());
        check('HttpResponse · url 是最终地址', $res->url() === 'http://example.test/final', $res->url());
        check('HttpResponse · elapsedMs', $res->elapsedMs() === 33.25, (string) $res->elapsedMs());

        $this->flushChecks();
    }
}
