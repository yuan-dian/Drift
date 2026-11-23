<?php
declare(strict_types=1);

namespace App\Showcase\Routes;

/**
 * AOT 页清单，由 `php tools/gen-route-manifest.php <app>` 生成。
 * 新增页先写 `#[Route]` 再跑生成器；手工改会在 `verify` 的 sceneset step 报红。
 *
 * @internal 启动期反射与量具专用
 */
final class RouteManifest
{
    /** @var list<class-string> */
    public const array CLASSES = [
        \App\Showcase\Pages\LayoutPage::class,
        \App\Showcase\Pages\TextPage::class,
        \App\Showcase\Pages\ButtonsPage::class,
        \App\Showcase\Pages\FormPage::class,
        \App\Showcase\Pages\ListPage::class,
        \App\Showcase\Pages\BigListPage::class,
        \App\Showcase\Pages\CustomPaintPage::class,
        \App\Showcase\Pages\FeedbackPage::class,
        \App\Showcase\Pages\MotionPage::class,
        \App\Showcase\Pages\ReactivePage::class,
        \App\Showcase\Pages\SegmentedPage::class,
        \App\Showcase\Pages\ImagePage::class,
        \App\Showcase\Pages\SliderPage::class,
        \App\Showcase\Pages\TooltipPage::class,
        \App\Showcase\Pages\SnackbarPage::class,
        \App\Showcase\Pages\SelectPage::class,
        \App\Showcase\Pages\ComboBoxPage::class,
        \App\Showcase\Pages\GridPage::class,
        \App\Showcase\Pages\AspectPage::class,
        \App\Showcase\Pages\RichTextPage::class,
        \App\Showcase\Pages\LogPage::class,
        \App\Showcase\Pages\IconPage::class,
        \App\Showcase\Pages\GlassPage::class,
        \App\Showcase\Pages\TablePage::class,
        \App\Showcase\Pages\TreePage::class,
        \App\Showcase\Pages\CalendarPage::class,
        \App\Showcase\Pages\PickerPage::class,
        \App\Showcase\Pages\DisabledPage::class,
        \App\Showcase\Pages\TransformPage::class,
        \App\Showcase\Pages\AlignPage::class,
        \App\Showcase\Pages\DialogPage::class,
        \App\Showcase\Pages\DropPage::class,
        \App\Showcase\Pages\AsyncTablePage::class,
        \App\Showcase\Pages\LoadMorePage::class,
        \App\Showcase\Pages\ConcurrentFetchPage::class,
    ];
}
