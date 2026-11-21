<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use App\Showcase\Models\ConcurrentResult;
use PHPUnit\Framework\TestCase;

/**
 * 并发取数结果的解码 —— **不挂树**。
 *
 * ★ 编排（`gather()` 里真的把三条发出去）仍由挂树的
 *   `ConcurrentFetchPageTest` 守：那件事的失败方式是"看起来在工作"，
 *   只有真跑帧才看得见。这里管的是另一半 —— 拿回来的载荷怎么读，
 *   而它原先散在页面六处 `is_array($data['entries'])` 里。
 */
final class ConcurrentResultTest extends TestCase
{
    /** @return array<string, mixed> 一份 `gather()` 落地形态的载荷 */
    private static function payload(): array
    {
        return [
            'entries' => [
                1 => ['ok' => true, 'value' => ['items' => [['id' => 1], ['id' => 2]], 'source' => '后台'], 'error' => null],
                2 => ['ok' => false, 'value' => null, 'error' => 'HTTP 500'],
                3 => ['ok' => true, 'value' => ['items' => [['id' => 3]], 'source' => '后台'], 'error' => null],
            ],
            // 失败那条没有耗时：`then` 没跑到
            'spent' => [1 => 120.5, 3 => 80.0],
            'totalMs' => 350.0,
        ];
    }

    public function testMissingPayloadDecodesToEmptyResult(): void
    {
        foreach ([null, 'oops', 42, []] as $payload) {
            $result = ConcurrentResult::from($payload);

            self::assertNull($result->entryOf(1));
            self::assertSame(0.0, $result->totalMs());
            self::assertSame(0, $result->timedCount());
        }
    }

    public function testEntriesDecodeOkRowsAndSource(): void
    {
        $result = ConcurrentResult::from(self::payload());

        $first = $result->entryOf(1);
        self::assertNotNull($first);
        self::assertTrue($first->ok());
        self::assertSame(2, $first->rowCount());
        self::assertSame('后台', $first->source());
        self::assertSame(120.5, $first->spentMs());
    }

    public function testFailedEntryKeepsReasonAndHasNoTiming(): void
    {
        $failed = ConcurrentResult::from(self::payload())->entryOf(2);

        self::assertNotNull($failed);
        self::assertFalse($failed->ok());
        self::assertSame('HTTP 500', $failed->error());
        self::assertNull($failed->spentMs(), '失败条必须没有耗时，页面据此画「—」');
    }

    public function testMissingPageIsNullNotFailure(): void
    {
        // "没发这条请求" 与 "这条失败了" 是两件事，混成一个会让界面撒谎
        self::assertNull(ConcurrentResult::from(self::payload())->entryOf(9));
    }

    public function testSumAndTimedCountIgnoreFailedEntries(): void
    {
        $result = ConcurrentResult::from(self::payload());

        self::assertSame(2, $result->timedCount(), '只有两条有耗时');
        self::assertEqualsWithDelta(200.5, $result->sumMs(), 0.001);
        self::assertSame(350.0, $result->totalMs());
    }

    /** 回归判据：落地即解码，所以再喂回来必须原样返回（同 `PagedRows::from()`） */
    public function testFromIsIdempotentForDecodedResult(): void
    {
        $result = ConcurrentResult::from(self::payload());

        self::assertSame($result, ConcurrentResult::from($result));
    }
}
