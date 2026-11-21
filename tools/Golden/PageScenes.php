<?php
declare(strict_types=1);

namespace Drift\Dev\Golden;

/**
 * golden 整页场景的**冻结清单**（L1 判据资产，2026-11-13 冻结）。
 *
 * ★ 不是 demo 的镜像：清单入仓后就是测试资产，改 `apps/showcase` 不再改变判据集合。
 *   加页 / 删页 / 改名必须显式改这里，由 `scene-set-check.php` 双向盯着。
 * ★ 只存名字：类与构造器接线仍住 `golden-raster.php`，不把构造副作用搬进清单。
 * ★ 与 `Bench/ShowcasePages.php` 不是一份：那份是 AOT 量具的固定子集（27 页，
 *   历史分母不随页数扩），这份是整页 golden 的全集。
 *
 * @internal 量具专用，不进公共 API
 */
final class PageScenes
{
    /** @return list<string> 字典序 */
    public static function names(): array
    {
        return [
            'AlignPage',
            'AspectPage',
            'AsyncTablePage',
            'BigListPage',
            'ButtonsPage',
            'CalendarPage',
            'ComboBoxPage',
            'ConcurrentFetchPage',
            'CustomPaintPage',
            'DialogPage',
            'DisabledPage',
            'DropPage',
            'FeedbackPage',
            'FormPage',
            'GlassPage',
            'GridPage',
            'IconPage',
            'ImagePage',
            'LayoutPage',
            'ListPage',
            'LoadMorePage',
            'LogPage',
            'MotionPage',
            'PickerPage',
            'ReactivePage',
            'RichTextPage',
            'SegmentedPage',
            'SelectPage',
            'SliderPage',
            'SnackbarPage',
            'TablePage',
            'TextPage',
            'TooltipPage',
            'TransformPage',
            'TreePage',
        ];
    }
}
