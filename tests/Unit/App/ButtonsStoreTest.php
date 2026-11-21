<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use App\Showcase\Stores\ButtonsStore;
use PHPUnit\Framework\TestCase;

/**
 * Store-first 样板判据。
 *
 * ★ 行为判据证明状态与动作能脱离页面单测；结构判据证明页面退化为纯 View。
 *   两者缺一都可能让样板名存实亡（状态又搬回页面，但测试还绿）。
 */
final class ButtonsStoreTest extends TestCase
{
    public function testStoreOwnsStateAndActions(): void
    {
        $store = new ButtonsStore();
        $store->hover(true);
        $store->hover(false);

        self::assertSame(1, $store->enters->peek());
        self::assertSame(1, $store->leaves->peek());
        self::assertSame('enters=1  leaves=1', $store->hoverText());

        $store->press();
        self::assertSame('点击我（此前 enters=1）', $store->lastPressed->peek());
    }

    public function testPageIsPureView(): void
    {
        $src = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/apps/showcase/src/Pages/ButtonsPage.php'
        );

        self::assertStringNotContainsString('private Cell', $src);
        self::assertStringNotContainsString('function initState', $src);

        // 依赖是构造参数里的具体类型，不是一束同型闭包 —— 写反是构造期 Fatal。
        self::assertStringContainsString('private readonly ButtonsStore $buttons', $src);
        self::assertStringNotContainsString('ShowcaseCapabilities', $src);
    }
}
