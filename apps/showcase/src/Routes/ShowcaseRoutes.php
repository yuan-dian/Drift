<?php
declare(strict_types=1);

namespace App\Showcase\Routes;

use App\Showcase\Pages;
use Drift\Route\RouteCatalog;

/**
 * 示例台的路由清单 —— **一页一行**，且 `id/title/icon` 只在页面类注解里写一次。
 *
 * 这份清单唯一的用途是告诉 AOT 产物"哪些类参与启动期反射扫描"
 * （AOT 里没有扫目录找类这回事，清单本身是构建期可生成的产物）。
 * 导航项与自动化身份由 `RouteCatalog` 从注解派生 —— 迁移前 `ShowcaseNav::PAGES`
 * 那张写 id/icon/title 的平行清单已删除，不会再出现"注解改了、清单没改"的双写漂移。
 * ★ **页面工厂不在这里**：需要领域依赖的页由组合根按类名覆写建页方式
 *   （`ShowcaseApplication::pageFactories()`），本清单只管"哪些类参与启动期扫描"。
 *
 * ★ `static` 缓存保证反射只跑一次：首次用到时才扫，之后读表。
 *
 * @api
 */
final class ShowcaseRoutes
{
    /** @var list<class-string> 带 #[Route] 的页面 Widget 类 */
    private const array CLASSES = [
        Pages\LayoutPage::class,
        Pages\TextPage::class,
        Pages\ButtonsPage::class,
        Pages\FormPage::class,
        Pages\ListPage::class,
        Pages\BigListPage::class,
        Pages\CustomPaintPage::class,
        Pages\FeedbackPage::class,
        Pages\MotionPage::class,
        Pages\ReactivePage::class,
        Pages\SegmentedPage::class,
        Pages\ImagePage::class,
        Pages\SliderPage::class,
        Pages\TooltipPage::class,
        Pages\SnackbarPage::class,
        Pages\SelectPage::class,
        Pages\ComboBoxPage::class,
        Pages\GridPage::class,
        Pages\AspectPage::class,
        Pages\RichTextPage::class,
        Pages\LogPage::class,
        Pages\IconPage::class,
        Pages\GlassPage::class,
        Pages\TablePage::class,
        Pages\TreePage::class,
        Pages\CalendarPage::class,
        Pages\PickerPage::class,
        Pages\DisabledPage::class,
        Pages\TransformPage::class,
        Pages\AlignPage::class,
        Pages\DialogPage::class,
        Pages\DropPage::class,
        Pages\AsyncTablePage::class,
        Pages\LoadMorePage::class,
        Pages\ConcurrentFetchPage::class,
    ];

    private static ?RouteCatalog $catalog = null;

    public static function catalog(): RouteCatalog
    {
        return self::$catalog ??= RouteCatalog::discover(self::CLASSES);
    }

    /** 测试用：丢掉缓存，下次重新发现。 */
    public static function reset(): void
    {
        self::$catalog = null;
    }

    /** @return list<class-string> */
    public static function classes(): array
    {
        return self::CLASSES;
    }
}
