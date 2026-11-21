<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use App\Showcase\Models\PagedRows;
use PHPUnit\Framework\TestCase;

/**
 * 分页载荷的解码与合并 —— **不挂树**。
 *
 * ★ 这些判据原先只能通过"挂一页 + 跑两帧 + 读命令值"间接验：合并规则的边界
 *   （载荷为 null / 缺键 / `items` 不是数组）藏在页面的三个 `is_array` 里。
 *   现在形状只在 `PagedRows::from()` 认一次，边界也就能直接喂进去。
 */
final class PagedRowsTest extends TestCase
{
    public function testMissingOrMalformedPayloadDecodesToEmptyPage(): void
    {
        foreach ([null, 'oops', 42, [], ['items' => 'not-a-list'], ['total' => 9]] as $payload) {
            $rows = PagedRows::from($payload);

            self::assertSame(0, $rows->count(), '载荷 ' . \json_encode($payload) . ' 应解成空页');
            self::assertSame([], $rows->items());
        }
    }

    public function testPayloadDecodesFields(): void
    {
        $rows = PagedRows::from([
            'items' => [['id' => 1], ['id' => 2]],
            'total' => 40,
            'source' => '后台',
        ]);

        self::assertSame(2, $rows->count());
        self::assertSame(40, $rows->total());
        self::assertSame('后台', $rows->source());
    }

    public function testMergeAppendsItemsAndTakesNewMeta(): void
    {
        $first = PagedRows::from(['items' => [['id' => 1]], 'total' => 3, 'source' => '旧']);
        $second = PagedRows::from(['items' => [['id' => 2]], 'total' => 3, 'source' => '新']);

        $merged = $first->mergedWith($second);

        self::assertSame([['id' => 1], ['id' => 2]], $merged->items());
        self::assertSame(3, $merged->total());
        self::assertSame('新', $merged->source(), 'total / source 以最新一次为准');
    }

    /**
     * 回归判据：合并之后载荷就是 `PagedRows` 本身，再喂回 `from()` 必须原样返回。
     *
     * ★ 少了幂等这一条，第二次追加会把对象当"认不出的载荷"降级成空页 ——
     *   症状是"追加完行全没了"，而且只有连点两次「加载更多」才出现。
     */
    public function testFromIsIdempotentForDecodedPayload(): void
    {
        $rows = PagedRows::from(['items' => [['id' => 7]], 'total' => 1]);
        $again = PagedRows::from($rows);

        self::assertSame($rows->items(), $again->items());
        self::assertSame($rows, $again, '已经解码过的载荷应当原样返回');
    }

    public function testNextPageDerivesFromLoadedCount(): void
    {
        self::assertSame(1, PagedRows::from(null)->nextPage(20));
        self::assertSame(2, PagedRows::from(['items' => \array_fill(0, 20, ['id' => 1])])->nextPage(20));
        self::assertSame(3, PagedRows::from(['items' => \array_fill(0, 41, ['id' => 1])])->nextPage(20));

        // 每页行数配错成 0 时别除零：保守回第 1 页
        self::assertSame(1, PagedRows::from(['items' => [['id' => 1]]])->nextPage(0));
    }

    public function testCompleteNeedsKnownTotal(): void
    {
        // total 未知（0）⇒ 不能说"全了"，否则进页面就再也不让翻页
        self::assertFalse(PagedRows::from(['items' => [['id' => 1]]])->isComplete());

        self::assertTrue(PagedRows::from(['items' => [['id' => 1]], 'total' => 1])->isComplete());
        self::assertFalse(PagedRows::from(['items' => [['id' => 1]], 'total' => 2])->isComplete());
    }
}
