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
 * 形态对照 `D:/TypePHP/GUI/examples/showcase`（顶栏 + 左侧功能列表 + 右侧功能展示），
 * 落到 Drift 现有控件面（§12.1 第 3 条「外壳 + 内容槽」）：
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
    /**
     * @param \Closure(Snackbar): void $notify 「弹一条底部通知」—— 由组合根接线
     * @param \Closure(int, \Closure): void $delay 「N 毫秒后跑这个回调」—— 由组合根接线
     *        （`Application::after()`）。ComboBox 页用它模拟远程搜索的**异步到达**。
     * @param \Closure(Modal): void $showModal 「开一个模态」—— 由组合根接线。
     *        Feedback 页的模态对话框演示用（2026-10-25 随 `Modal` 关闭按钮补：
     *        没有它示例台没有任何一处 `Modal::make()`，新机制真机不可见）。
     * @param Dialog $dialog 原生对话框端口（A5-①）—— 组合根从 `$platform->dialog()`
     *        取来直接递进来。★ 这里是**接口**不是平台类：平台名仍然只在组合根。
     *        ★ 为什么不走闭包（`\Closure` 那一族）：它不是"做一件事"，是**一个
     *        有四种返回语义的服务**；包成闭包只会让调用点看不出返回值是什么。
     *        ★ 形参不可空（`?Dialog` 在 AOT 侧退化 `php::Var`）⇒ 没有对话框端口的
     *        平台上这一页根本不会被建出来，那是组合根的判断，不是页面的。
     * @param \Closure(bool): void $setCloseConfirm 「关闭前确认」开关 —— 由组合根接线。
     *        ★ 方向与上面三个**相反**（页面 → 应用）：它改的是
     *        `Application::onCloseRequested()` 的答案，而那个方法只有应用有。
     *        页面只表达意图，拦截点只有一处（帧边界，`EventLoop` 统一调）。
     * @param \Closure(int): Future $fetchRows 「取第 N 页」—— 由组合根接线。
     *        ★ 它是本应用**唯一**的异步入口：页面只认"给我第 N 页的结果"，
     *        至于背后是 `ProcessTaskHost` 真跑子进程、还是基准里直接 `Future::resolved`，
     *        页面**不知道也不需要知道**（"业务与 UI 隔离"在接线层的形态）。
     *        ★ 用闭包而不是把 `TaskHost` 递下去：`TaskHost` 是 `Drift\App` 的端口，
     *        页面（`App\Showcase\Pages`）拿到它就会自己 `submit(Job)` —— Job 是
     *        组合根配置，不该下沉到页面。
     *
     * ★ 为什么前三个是**闭包**而不是 `Application`：`Widget` 层不该认识 `Drift\App`
     *   （§4.3），而且页面只该拿到"我能做什么"，不是"整个应用"。
     * ★ 为什么**不可空**：可选对象在 AOT 侧会退化 `php::Var`（同 `?float` 用
     *   `NAN` 那条纪律）⇒ 一律由组合根给一个真的。
     */
    public function __construct(private ShowcaseCapabilities $caps)
    {
        parent::__construct();
    }

    /**
     * @param ShowcaseCapabilities $caps 组合根接线的那一束能力（见该类的注释：
     *       为什么是**打包**而不是六个参数，以及为什么不做"沿树查找"）
     */
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
 * 导航条目 + 选中态。
 *
 * ★ `$entries` 存 `NavEntry`（图标 + 名称 + 页工厂）—— 2026-10-25 从
 *   `[名称, 页工厂]` 二元数组升级：加图标若继续用数组，就要靠 `[2]` 魔数，
 *   而且"图标和名字写反"在数组里不报错。
 * ★ 工厂是 `Closure` 而不是类型名：页类构造**至多一个闭包参数**，`static fn(): Widget
 *   => new XxxPage()` 对 AOT 直白（类名常量字符串形态还没在 codegen 验过）。
 *   ★ 带参的页：通知页要 `$notify`、Combo 页要 `$delay`、反馈页要 `$showModal`
 *     —— 三者都是"浮层归 `Application`，页面够不到"（§4.3）的那一族能力。
 */
final class ShowcaseState extends State
{
    /** 未选中导航按钮的底色（LayUI 式浅灰） */
    private const NAV_IDLE = 0xF2F2F2;

    /**
     * 侧栏定宽（逻辑像素）。
     *
     * ★ 公开成常量：`tools/narrow-audit.php` 要用 `Bootstrap::MIN_WINDOW_WIDTH`
     *   减它，得到"窗口拖到最小时内容区还剩多宽"，再逐页验证装不装得下。
     *   两边各自写字面量 ⇒ 改了宽度审计还按老值跑，全绿是假的。
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

        // 路由表 = 数据；PageHost = 容器（§12.1 第 7 条）。两者都不认识导航按钮。
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

        // ★ 从 ctx 的主题派生，不再硬造 ThemeData::light()：系统明暗 / HC 由
        //   AppScope 下发，root 自己造一份就把系统外观盖掉了。
        // ★ HC 下不覆盖 Accent：用户选的系统色必须赢过品牌色（Win32 官方口径）。
        //   `withExtension()` 把应用侧自定义 token 挂进这棵子树。
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
        // ★ 副标题交给 BrandCaption：它在 ThemeScope 子树里读 `ShowcaseBrand::of($ctx)`，
        //   顺带把系统明暗 / HC 的接线做成肉眼可见。
        // ★ 右侧动作区：两个 Flat 图标按钮（无底 ⇒ 顶条上不出现第二个实心块）。
        //   AppBar::actions() 内部自动插弹性占位，导航短时这组仍贴右边缘。
        return AppBar::make()
            ->title('Drift 示例台')
            ->children([
                BrandCaption::make(),
            ])
            ->actions([
                // ★ 演示"动作位"两种接线：纯 UI 回调（左）与命令（右）。
                //   `showcase.refresh` 由 ShowcaseApplication 注册（未注册命令会
                //   走 Errors::DIAGNOSTIC，见 CommandRouter）。
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
     * 外壳 = **浅灰背景板 + 白色导航面板 + 内容槽**（LayUI admin 的层次）。
     *
     * ★ 三个 `Card` 各有分工：最外层只当"背景板"（用户一旦给了盒样式，
     *   `defaultBoxStyle()` 的默认边框/圆角就整套让位），中间那层是
     *   **真正的卡片**（白底 + 1px 边框 + 圆角 2 + 内边距），内容页里每个 demo
     *   再各自一张卡片。
     * ★ `$canvas` 由 `build()` 从主题取（`ColorToken::Pane`）而不是硬编码
     *   #F2F3F5：硬编码值既不跟随明暗表，也会和 `Chrome` 撞成一片（2026-10-25）。
     * ★ `cross(Stretch)` 让导航面板拉满高：LayUI 的左侧导航就是全高白底，
     *   少了它导航只有内容那么高，下面露出灰底 —— 一眼就"没做完"。
     * ★ `$compact` 是**结构**差异而非样式差异：窄窗档侧栏收成图标栏，把宽度
     *   还给内容（页根是竖向滚动，横向被裁掉的右边拿不回来）。
     */
    private function buildBody(Color $canvas, bool $compact): Widget
    {
        return Card::make(
            Row::make()->cross(FlexAlign::Stretch)->children([
                // ★ 侧栏**定宽**（`self::NAV_WIDTH`，LayUI 侧栏的形态）：没有 `SizedBox`
                //   时宽度只能由"最长那个按钮文字"撑出来，改文案/换语言就变。外层
                //   `Row::cross(Stretch)` 负责把它拉满高，`SizedBox` 只定宽 —— 两轴各自管一件事。
                SizedBox::make(Card::make(ScrollView::make($this->buildNav($compact)))->paddingAll(10.0))
                    ->width((float) ($compact ? self::NAV_WIDTH_COMPACT : self::NAV_WIDTH)),
                // ★ 滚动归**页根**（每页自己 `Card(ScrollView(...))`）：外壳再套一层
                //   就是嵌套滚动 —— 内层永远"量到全部内容"，外层滚不动，两层都白搭。
                // ★ PageHost 按 id 缓存页实例、用 Offstage 保活；expanded() 让每页
                //   吃满内容区高度（页根 ScrollView 才量得到可视高，而不是全部内容高）。
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

        // ★ 未选中用 `Flat`（无底无边框 ⇒ 看起来像菜单项，不像一排按钮）；
        //   选中用 `Primary` + 显式主色（主题的 Accent 是蓝的，示例台要 LayUI 的墨绿）。
        // ★ 宽档用 `NavButton`：壳控件词汇要可静态识别，且它把内容设成左对齐
        //   （图标列必须齐，居中会按标签长度各自漂移）。窄档没地方放文字，换
        //   `IconButton` —— 标签仍进无障碍名，不丢信息。
        $button = $compact
            ? IconButton::of($entry->icon, $entry->label)
            : NavButton::make($entry->label)->icon($entry->icon);

        $button
            ->variant($isHere ? ButtonVariant::Primary : ButtonVariant::Flat)
            // ★ 稳定 id 走 UIA 的 AutomationId，不占用可见文案：探针按它定位，
            //   改中文名 / 加英文标不会让 6 个探针同时失效（宽窄两档同一个 id）。
            ->semanticsMeta(automationId: $entry->id)
            ->onPressed(function () use ($entry): void {
                // ★ `$this` 在闭包里（**不能** static fn —— 要调 setState）
                $this->select($entry->id);
            });

        if ($isHere) {
            $button->background(Color::fromRgb(0x00, 0x96, 0x88));
        }

        return $button;
    }

    private function buildNav(bool $compact): Widget
    {
        // ★ `cross(Stretch)` 在这里**安全**：宽度已被外层 `SizedBox` 定死，
        //   `Stretch` 拉的是"可用宽"（扣掉卡片 padding），不再是整行 ——
        //   ⇒ 导航项整行可点（LayUI 侧栏就是这个形态），右边缘也齐。
        return Column::make()->cross(FlexAlign::Stretch)->spacing(2.0)->children($this->navButtons($compact));
    }

    private function select(string $id): void
    {
        $this->setState(function () use ($id): void {
            $this->selected = $id;
        });
    }
}
