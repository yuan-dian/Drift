<?php
declare(strict_types=1);

namespace App\Showcase;

use App\Showcase\Pages;
use Drift\Routing\NavEntry;
use Drift\Routing\PageRegistry;
use Drift\Routing\RouteTable;
use Drift\Widget\IconName;
use Drift\Widget\Widget;

/**
 * 示例台的页面清单。公共的建页 / 路由逻辑在 `PageRegistry`，这里只列页面数据。
 * 新增一页：加一行 + 页面构造器统一收 `ShowcaseCapabilities`。
 */
final readonly class ShowcaseNav
{
    /** @var list<array{string, IconName, string, class-string<Widget>}> */
    private const PAGES = [
        ['layout', IconName::Grid, '布局 Layout', Pages\LayoutPage::class],
        ['text', IconName::TextLines, '文本 Text', Pages\TextPage::class],
        ['buttons', IconName::Square, '按钮 Button', Pages\ButtonsPage::class],
        ['form', IconName::Check, '表单 Form', Pages\FormPage::class],
        ['list', IconName::ListBullets, '列表 List', Pages\ListPage::class],
        ['big-list', IconName::ListBullets, '长列表 Big', Pages\BigListPage::class],
        ['paint', IconName::Brush, '自绘 Paint', Pages\CustomPaintPage::class],
        ['feedback', IconName::Bell, '反馈 Feedback', Pages\FeedbackPage::class],
        ['motion', IconName::Play, '动画 Motion', Pages\MotionPage::class],
        ['segmented', IconName::Menu, '分段 Segmented', Pages\SegmentedPage::class],
        ['image', IconName::Image, '图片 Image', Pages\ImagePage::class],
        ['slider', IconName::Sliders, '滑杆 Slider', Pages\SliderPage::class],
        ['tooltip', IconName::Info, '提示 Tooltip', Pages\TooltipPage::class],
        ['snackbar', IconName::Bell, '通知 Snackbar', Pages\SnackbarPage::class],
        ['select', IconName::ChevronDown, '下拉 Select', Pages\SelectPage::class],
        ['combo', IconName::Search, '可编辑 Combo', Pages\ComboBoxPage::class],
        ['grid', IconName::Grid, '网格 Grid', Pages\GridPage::class],
        ['aspect', IconName::Square, '宽高比 Aspect', Pages\AspectPage::class],
        ['rich-text', IconName::TextLines, '富文本 Rich', Pages\RichTextPage::class],
        ['log', IconName::ListBullets, '日志 Log', Pages\LogPage::class],
        ['icon', IconName::Plus, '图标 Icon', Pages\IconPage::class],
        ['glass', IconName::Brush, '毛玻璃 Glass', Pages\GlassPage::class],
        ['table', IconName::Grid, '表格 Table', Pages\TablePage::class],
        ['tree', IconName::ArrowRight, '树 Tree', Pages\TreePage::class],
        ['calendar', IconName::Grid, '日历 Calendar', Pages\CalendarPage::class],
        ['picker', IconName::ChevronDown, '日期时间 Picker', Pages\PickerPage::class],
        ['disabled', IconName::Square, '禁用态 Disabled', Pages\DisabledPage::class],
        ['transform', IconName::Square, '变换 Transform', Pages\TransformPage::class],
        ['align', IconName::Square, '对齐 Align', Pages\AlignPage::class],
        ['dialog', IconName::Info, '对话框 Dialog', Pages\DialogPage::class],
        ['drop', IconName::Square, '拖文件 Drop', Pages\DropPage::class],
        ['async-table', IconName::ListBullets, '异步表格 Async', Pages\AsyncTablePage::class],
        ['load-more', IconName::ListBullets, '加载更多 More', Pages\LoadMorePage::class],
        ['concurrent', IconName::Grid, '并发取数 Concurrent', Pages\ConcurrentFetchPage::class],
    ];

    private PageRegistry $registry;

    public function __construct(ShowcaseCapabilities $caps)
    {
        $entries = [];
        foreach (self::PAGES as [$id, $icon, $label, $class]) {
            $entries[] = new NavEntry($id, $icon, $label, $class);
        }
        $this->registry = new PageRegistry($entries, $caps);
    }

    /** @return list<NavEntry> */
    public function entries(): array
    {
        return $this->registry->entries();
    }

    public function table(): RouteTable
    {
        return $this->registry->table();
    }

    public function defaultId(): string
    {
        return $this->registry->defaultId();
    }
}
