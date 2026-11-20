<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\Async\Future;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Platform\Dialog;
use Drift\Theme\ThemeScope;
use Drift\Routing\PageHost;
use Drift\Routing\RouteTable;
use Drift\Widget\AppBar;
use Drift\Widget\BuildContext;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Dock;
use Drift\Layout\FlexAlign;
use Drift\Widget\Expanded;
use Drift\Widget\IconButton;
use Drift\Widget\IconName;
use Drift\Widget\NavButton;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\SizedBox;
use Drift\Widget\Snackbar;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

use App\Showcase\Pages\AlignPage;
use App\Showcase\Pages\AsyncTablePage;
use App\Showcase\Pages\ConcurrentFetchPage;
use App\Showcase\Pages\LoadMorePage;
use App\Showcase\Pages\AspectPage;
use App\Showcase\Pages\BigListPage;
use App\Showcase\Pages\TablePage;
use App\Showcase\Pages\ButtonsPage;
use App\Showcase\Pages\ComboBoxPage;
use App\Showcase\Pages\CustomPaintPage;
use App\Showcase\Pages\DialogPage;
use App\Showcase\Pages\DropPage;
use App\Showcase\Pages\FeedbackPage;
use App\Showcase\Pages\FormPage;
use App\Showcase\Pages\GlassPage;
use App\Showcase\Pages\GridPage;
use App\Showcase\Pages\IconPage;
use App\Showcase\Pages\ImagePage;
use App\Showcase\Pages\LayoutPage;
use App\Showcase\Pages\ListPage;
use App\Showcase\Pages\LogPage;
use App\Showcase\Pages\MotionPage;
use App\Showcase\Pages\RichTextPage;
use App\Showcase\Pages\SegmentedPage;
use App\Showcase\Pages\SelectPage;
use App\Showcase\Pages\SliderPage;
use App\Showcase\Pages\SnackbarPage;
use App\Showcase\Pages\TooltipPage;
use App\Showcase\Pages\TextPage;
use App\Showcase\Pages\TransformPage;
use App\Showcase\Pages\TreePage;
use App\Showcase\Pages\CalendarPage;
use App\Showcase\Pages\DisabledPage;
use App\Showcase\Pages\PickerPage;

/**
 * 示例台根（LayUI 式 admin 布局）：**顶条 + 左导航 + 右内容**。
 *
 * 形态：顶条 + 左侧功能列表 + 右侧内容展示，落到 Drift 控件面：
 *
 * ```
 * Dock(top: AppBar, center: Row(
 *     Column(左导航：选中项 Primary、未选中灰底),     left
 *     Expanded(ScrollView(当前功能页)),              center
 * ))
 * ```
 *
 * ★ **左侧列不给固定宽度**：宽度由导航按钮的自然宽度决定
 *   —— 这正是 Flex 布局的"内容撑开"路径，不是缺陷规避。
 * ★ **导航底色走按钮自己的 `->background()`** 而不是给 Column 上底色：修饰挂在
 *   根 `Widget` 上，`Element::syncBox()` 交给各自的盒；给 Column 上色会连留白一起染掉。
 * ★ **导航顺序不是 API**：每项有稳定 `id`，探针按 id 对应的无障碍名定位，
 *   插页 / 重排不影响既有页的身份（也不再需要"追加在末尾"的注释纪律）。
 * ★ 页切换交给 `PageHost`：它按 id 缓存页面实例、用 Offstage 保活，切走再切回
 *   表单草稿 / 滚动位置 / 异步结果都还在。
 */
final class ShowcaseRoot extends StatefulWidget
{
    /** @param ShowcaseCapabilities $caps 组合根接线的一束能力（见该类） */
    public function __construct(private ShowcaseCapabilities $caps)
    {
        parent::__construct();
    }

    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new ShowcaseState($this->caps);
    }
}

/**
 * 导航条目 + 选中态。页工厂用闭包（类名字符串形态未在 codegen 验过），
 * 实例由 PageHost 按 id 缓存。
 */
final class ShowcaseState extends State
{
    /** 未选中导航按钮的底色（LayUI 式浅灰） */
    private const NAV_IDLE = 0xF2F2F2;

    /**
     * 侧栏定宽（逻辑像素）。`narrow-audit.php` 用它 + `Bootstrap::MIN_WINDOW_WIDTH`
     * 算最小窗的内容区宽；两边写字面量会让审计按老值跑、全绿是假的。
     */
    public const NAV_WIDTH = 200;

    /**
     * 窄窗档的侧栏宽（图标栏）。
     *
     * ★ 窗口拖到最小时侧栏若仍是 200，内容区只剩 220 —— 页面里的 `Row` 一律被裁，
     *   而页根是竖向滚动，右边**拿不回来**。收成图标栏把宽度还给内容。
     */
    public const NAV_WIDTH_COMPACT = 56;

    /** @var list<NavEntry> */
    private array $entries = [];

    private RouteTable $table;

    private string $selected = 'layout';

    /**
     * @param ShowcaseCapabilities $caps 组合根接线的那一束能力（见该类的注释）
     */
    public function __construct(private ShowcaseCapabilities $caps)
    {
    }

    public function initState(): void
    {
        $this->entries = [
            new NavEntry('layout', IconName::Grid, '布局 Layout', static fn(): Widget => new LayoutPage()),
            new NavEntry('text', IconName::TextLines, '文本 Text', static fn(): Widget => new TextPage()),
            new NavEntry('buttons', IconName::Square, '按钮 Button', static fn(): Widget => new ButtonsPage()),
            new NavEntry('form', IconName::Check, '表单 Form', static fn(): Widget => new FormPage()),
            new NavEntry('list', IconName::ListBullets, '列表 List', static fn(): Widget => new ListPage()),
            new NavEntry('big-list', IconName::ListBullets, '长列表 Big', static fn(): Widget => new BigListPage()),
            new NavEntry('paint', IconName::Brush, '自绘 Paint', static fn(): Widget => new CustomPaintPage()),
            new NavEntry('feedback', IconName::Bell, '反馈 Feedback', fn(): Widget => new FeedbackPage($this->caps->showModal)),
            new NavEntry('motion', IconName::Play, '动画 Motion', static fn(): Widget => new MotionPage()),
            new NavEntry('segmented', IconName::Menu, '分段 Segmented', static fn(): Widget => new SegmentedPage()),
            new NavEntry('image', IconName::Image, '图片 Image', static fn(): Widget => new ImagePage()),
            new NavEntry('slider', IconName::Sliders, '滑杆 Slider', static fn(): Widget => new SliderPage()),
            new NavEntry('tooltip', IconName::Info, '提示 Tooltip', static fn(): Widget => new TooltipPage()),
            new NavEntry('snackbar', IconName::Bell, '通知 Snackbar', fn(): Widget => new SnackbarPage($this->caps->notify)),
            new NavEntry('select', IconName::ChevronDown, '下拉 Select', static fn(): Widget => new SelectPage()),
            new NavEntry('combo', IconName::Search, '可编辑 Combo', fn(): Widget => new ComboBoxPage($this->caps->delay)),
            new NavEntry('grid', IconName::Grid, '网格 Grid', static fn(): Widget => new GridPage()),
            new NavEntry('aspect', IconName::Square, '宽高比 Aspect', static fn(): Widget => new AspectPage()),
            new NavEntry('rich-text', IconName::TextLines, '富文本 Rich', static fn(): Widget => new RichTextPage()),
            new NavEntry('log', IconName::ListBullets, '日志 Log', static fn(): Widget => new LogPage()),
            new NavEntry('icon', IconName::Plus, '图标 Icon', static fn(): Widget => new IconPage()),
            new NavEntry('glass', IconName::Brush, '毛玻璃 Glass', static fn(): Widget => new GlassPage()),
            new NavEntry('table', IconName::Grid, '表格 Table', static fn(): Widget => new TablePage()),
            new NavEntry('tree', IconName::ArrowRight, '树 Tree', static fn(): Widget => new TreePage()),
            new NavEntry('calendar', IconName::Grid, '日历 Calendar', static fn(): Widget => new CalendarPage()),
            new NavEntry('picker', IconName::ChevronDown, '日期时间 Picker', static fn(): Widget => new PickerPage()),
            new NavEntry('disabled', IconName::Square, '禁用态 Disabled', static fn(): Widget => new DisabledPage()),
            new NavEntry('transform', IconName::Square, '变换 Transform', static fn(): Widget => new TransformPage()),
            new NavEntry('align', IconName::Square, '对齐 Align', static fn(): Widget => new AlignPage()),
            new NavEntry('dialog', IconName::Info, '对话框 Dialog', fn(): Widget => new DialogPage($this->caps->dialog, $this->caps->setCloseConfirm)),
            new NavEntry('drop', IconName::Square, '拖文件 Drop', static fn(): Widget => new DropPage()),
            new NavEntry('async-table', IconName::ListBullets, '异步表格 Async', fn(): Widget => new AsyncTablePage($this->caps->fetchRows)),
            new NavEntry('load-more', IconName::ListBullets, '加载更多 More', fn(): Widget => new LoadMorePage($this->caps->fetchRows)),
            new NavEntry('concurrent', IconName::Grid, '并发取数 Concurrent', fn(): Widget => new ConcurrentFetchPage($this->caps->fetchRows)),
        ];

        // 路由表 = 数据；PageHost = 容器。两者都不认识导航按钮。
        $table = RouteTable::make();
        foreach ($this->entries as $entry) {
            $table->route($entry->id, $entry->page);
        }
        $this->table = $table;
    }

    public function build(BuildContext $ctx): Widget
    {
        $theme = $ctx->theme();
        $canvas = $theme->color(ColorToken::Pane);
        $compact = $ctx->windowSize()->isCompact();
        $brand = ShowcaseBrand::defaults();

        // 从 ctx 主题派生：自建 theme 会盖掉系统明暗/HC。HC 下不覆盖 Accent。
        $scoped = $theme->isHighContrast()
            ? $theme
            : $theme->withColor(ColorToken::Accent, $brand->accent);

        return ThemeScope::make(
            $scoped->withExtension($brand),
            Dock::make()
                ->top($this->buildTopBar())
                ->center($this->buildBody($canvas, $compact))
        );
    }

    private function buildTopBar(): Widget
    {
        // 右侧演示两种动作接线：左纯 UI 回调，右命令（未注册会走 DIAGNOSTIC）。
        return AppBar::make()
            ->title('Drift 示例台')
            ->children([
                BrandCaption::make(),
            ])
            ->actions([
                // 左：纯 UI 回调（示例不真做搜索）
                IconButton::of(IconName::Search, '搜索')
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(function (): void {
                        // 示例台：只演示动作位接线，不真做搜索
                    }),
                IconButton::of(IconName::More, '刷新')
                    ->variant(ButtonVariant::Flat)
                    ->command('showcase.refresh'),
            ]);
    }

    /**
     * 外壳 = 背景板 + 白导航面板 + 内容槽。$canvas 从主题取（硬编码色不跟随明暗表）。
     * $compact 是结构差异：窄窗收成图标栏把宽度还给内容（横向被裁的右边拿不回来）。
     */
    private function buildBody(Color $canvas, bool $compact): Widget
    {
        return Card::make(
            Row::make()->cross(FlexAlign::Stretch)->children([
                // 定宽侧栏：否则宽度由最长文案撑出，改文案/换语言就变。
                SizedBox::make(Card::make(ScrollView::make($this->buildNav($compact)))->paddingAll(10.0))
                    ->width((float) ($compact ? self::NAV_WIDTH_COMPACT : self::NAV_WIDTH)),
                // 滚动归页根，外壳再套一层就是嵌套滚动（内层量到全部内容、外层滚不动）。
                // expanded() 让页根 ScrollView 量到可视高而非全部内容高。
                Expanded::make(
                    PageHost::make()
                        ->current($this->selected)
                        ->table($this->table)
                        ->expanded()
                ),
            ])
        )
            ->background($canvas)
            ->paddingAll(10.0);
    }

    /** @return list<Widget> */
    private function navButtons(bool $compact): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            $out[] = $this->navButton($entry, $compact);
        }
        return $out;
    }

    private function navButton(NavEntry $entry, bool $compact): Widget
    {
        $isHere = $entry->id === $this->selected;

        // 非选中 Flat（像菜单项），选中 Primary + 示例台墨绿。宽档 NavButton 左对齐
        // （图标列要齐），窄档 IconButton —— 标签仍进无障碍名。
        $button = $compact
            ? IconButton::of($entry->icon, $entry->label)
            : NavButton::make($entry->label)->icon($entry->icon);

        $button
            ->variant($isHere ? ButtonVariant::Primary : ButtonVariant::Flat)
            // 探针按此 id 定位，与可见文案无关（宽窄两档同一个 id）。
            ->semanticsMeta(automationId: $entry->id)
            ->onPressed(function () use ($entry): void {
                // 非 static fn：要调 setState
                $this->select($entry->id);
            });

        if ($isHere) {
            $button->background(Color::fromRgb(0x00, 0x96, 0x88));
        }

        return $button;
    }

    private function buildNav(bool $compact): Widget
    {
        // 宽度已被 SizedBox 定死，Stretch 拉的是可用宽（扣掉 padding）⇒ 整行可点。
        return Column::make()->cross(FlexAlign::Stretch)->spacing(2.0)->children($this->navButtons($compact));
    }

    private function select(string $id): void
    {
        $this->setState(function () use ($id): void {
            $this->selected = $id;
        });
    }
}
