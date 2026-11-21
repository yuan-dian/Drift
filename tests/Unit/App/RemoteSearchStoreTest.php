<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use App\Showcase\Stores\RemoteSearchStore;
use PHPUnit\Framework\TestCase;

/**
 * 远程搜索的 attempt 仲裁判据。
 *
 * ★ 三条合起来才排除"永远丢弃"与"永远落地"两种假实现：
 *   当前号落地、过期号丢弃、落地后重复到达仍丢弃。
 * ★ 变异：把 `arrive()` 的号比对去掉 ⇒ 第二条红；把它写成恒 false ⇒ 第一条红。
 */
final class RemoteSearchStoreTest extends TestCase
{
    public function testCurrentAttemptLands(): void
    {
        $store = new RemoteSearchStore();
        $attempt = $store->query();

        self::assertNotSame(0, $attempt);
        self::assertTrue($store->arrive($attempt), '当前号应当落地');
    }

    public function testStaleAttemptIsDropped(): void
    {
        $store = new RemoteSearchStore();
        $stale = $store->query();
        $current = $store->query();

        self::assertNotSame($stale, $current, '每次查询领新号');
        self::assertFalse($store->arrive($stale), '旧号被新查询作废');
        self::assertTrue($store->arrive($current), '新号仍可落地');
    }

    public function testDuplicateArrivalIsDropped(): void
    {
        $store = new RemoteSearchStore();
        $attempt = $store->query();

        self::assertTrue($store->arrive($attempt));
        self::assertFalse($store->arrive($attempt), '已落地后再到达同一号要丢弃');
    }

    public function testArrivalWithoutQueryIsDropped(): void
    {
        $store = new RemoteSearchStore();

        self::assertFalse($store->arrive(1), '没发过查询就没有在途，任何号都不落地');
    }
}
