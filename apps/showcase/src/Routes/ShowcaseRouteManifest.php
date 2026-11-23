<?php
declare(strict_types=1);

namespace App\Showcase\Routes;

use App\Showcase\Pages;

/**
 * AOT 页清单，由 `php tools/gen-route-manifest.php` 从 `#[Route]` 生成。
 * 新增页先写注解再跑生成器；手工改会在 `verify` 的 sceneset step 报红。
 *
 * @internal 启动期反射与量具专用
 */
final class ShowcaseRouteManifest
{
    /** @var list<class-string> */
    public const array CLASSES = [
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
}
