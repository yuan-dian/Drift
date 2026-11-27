<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\DevTools\View\Network;
use PHPUnit\Framework\TestCase;

/**
 * 网络视图判据：投影层只展示查询层给的事实，不许自己改一个数。
 *
 * 这里防的是"看起来正常的假报告"：脱敏后把原文当原文印出来、裁剪了却不报、
 * 查不到时静默空输出——这些都不报错，但会让读者对错误的数据下结论。
 */
final class NetworkViewTest extends TestCase
{
    public function testSkeletonReportsWindowAndCuts(): void
    {
        $answer = [
            'window' => ['from' => 3, 'to' => 9, 'capacity' => 500],
            'total' => 7,
            'rows' => [
                [
                    'request' => 3, 'method' => 'POST', 'status' => 200, 'bytes' => 42,
                    'elapsedMs' => 18.2, 'startedAtFrame' => 1, 'settledAtFrame' => 2,
                    'path' => '/rows.php', 'initiator' => 'TablePage.php:31',
                ],
            ],
        ];
        $lines = Network::skeleton($answer);
        $text = \implode("\n", $lines);
        self::assertStringContainsString('7 条', $text);
        self::assertStringContainsString('窗口 3..9 / 容量 500', $text);
        self::assertStringContainsString('本次只列 1 条', $text);
        self::assertStringContainsString('#3', $text);
        self::assertStringContainsString('POST', $text);
        self::assertStringContainsString('TablePage.php:31', $text);
        self::assertSame($lines, Network::skeleton($answer), '同样的 answer 必须逐字节相同');
    }

    public function testSkeletonEmptySaysNoRequests(): void
    {
        $lines = Network::skeleton(['window' => [], 'total' => 0, 'rows' => []]);
        self::assertCount(2, $lines);
        self::assertStringContainsString('无请求', $lines[1]);
        self::assertStringNotContainsString('本次只列', $lines[0]);
    }

    public function testSkeletonMarksFailedRequestAsErr(): void
    {
        $lines = Network::skeleton([
            'window' => [], 'total' => 1,
            'rows' => [[
                'request' => 1, 'method' => 'GET', 'status' => 0, 'bytes' => 0,
                'elapsedMs' => 0.0, 'startedAtFrame' => 1, 'settledAtFrame' => 2,
                'path' => '/missing', 'initiator' => 'x',
            ]],
        ]);
        self::assertStringContainsString('ERR', \implode("\n", $lines));
    }

    public function testBodyShowsMetadataAndRedactedTextVerbatim(): void
    {
        $answer = [
            'found' => true,
            'request' => 5,
            'requestBody' => "{\n  \"user\": \"bob\",\n  \"password\": \"[REDACTED]\"\n}",
            'requestMeta' => ['bytes' => 41, 'truncated' => false, 'redacted' => 1, 'format' => 'json'],
            'responseBody' => '{"rows":[]}',
            'responseMeta' => ['bytes' => 11, 'truncated' => true, 'redacted' => 0, 'format' => 'json'],
            'window' => [],
        ];
        $text = \implode("\n", Network::body($answer));
        self::assertStringContainsString('已脱敏', $text);
        self::assertStringContainsString('json  41 字节  脱敏 1 处  完整', $text);
        self::assertStringContainsString('json  11 字节  脱敏 0 处  已截断', $text);
        self::assertStringContainsString('"user": "bob"', $text, '业务字段必须保留，否则定位不了数据错在哪');
        self::assertStringContainsString('"password": "[REDACTED]"', $text);
        self::assertStringContainsString('    {', $text, '多行 body 必须逐行缩进');
    }

    public function testBodyRespectsPartSelection(): void
    {
        $answer = [
            'found' => true,
            'request' => 5,
            'responseBody' => '{"rows":[]}',
            'responseMeta' => ['bytes' => 11, 'truncated' => false, 'redacted' => 0, 'format' => 'json'],
            'window' => [],
        ];
        $text = \implode("\n", Network::body($answer));
        self::assertStringContainsString('响应', $text);
        self::assertStringNotContainsString('  请求 ', $text);
        self::assertSame(Network::body($answer), Network::body($answer));
    }

    public function testBodyMissIsSpelledOutWithWindow(): void
    {
        foreach (['unknown-request', 'body-not-retained', 'outside-network-window'] as $miss) {
            $lines = Network::body([
                'found' => false, 'request' => 12, 'miss' => $miss,
                'window' => ['from' => 2, 'to' => 9, 'capacity' => 200, 'requests' => 8],
            ]);
            self::assertCount(1, $lines);
            $text = $lines[0];
            self::assertStringContainsString('#12 查不到：' . $miss, $text);
            self::assertStringContainsString('窗口 2..9 / 容量 200，已存 8 条', $text);
        }
    }
}
