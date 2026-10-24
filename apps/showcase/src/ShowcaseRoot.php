<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Widget\Text;
use Drift\Theme\ThemeData;
use Drift\Theme\ThemeScope;
use Drift\Widget\AppBar;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Dock;
use Drift\Layout\FlexAlign;
use Drift\Widget\Expanded;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\SizedBox;
use Drift\Widget\Snackbar;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

use App\Showcase\Pages\AspectPage;
use App\Showcase\Pages\BigListPage;
use App\Showcase\Pages\ButtonsPage;
use App\Showcase\Pages\ComboBoxPage;
use App\Showcase\Pages\CustomPaintPage;
use App\Showcase\Pages\FeedbackPage;
use App\Showcase\Pages\FormPage;
use App\Showcase\Pages\GridPage;
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

/**
 * 示例台根（LayUI 式 admin 布局）：**顶条 + 左导航 + 右内容**。
 *
 * 形态对照 `D:/TypePHP/GUI/examples/showcase`（顶栏 + 左侧功能列表 + 右侧功能展示），
 * 落到 Drift2 现有控件面（§12.1 第 3 条「外壳 + 内容槽」）：
 *
 * ```
 * Dock(top: AppBar, center: Row(
 *     Column(左导航：选中项 Primary、未选中灰底),     left
 *     Expanded(ScrollView(当前功能页)),              center
 * ))
 * ```
 *
 * ★ **左侧列没有 SizedBox**（框架暂无此控件）：宽度由导航按钮的自然宽度决定
 *   —— 这正是 Flex 布局的"内容撑开"路径，不是缺陷规避。
 * ★ **导航底色走按钮自己的盒样式**（`BoxWidget::background`）而不是给 Column
 *   上底色（`Flex extends RenderBoxWidget`，无盒样式）—— 渲染能力边界的一次直观演示。
 * ★ 页切换是**纯 UI 态**（§12.3）：住 `ShowcaseState::$selected`，不注册命令。
 */
final class ShowcaseRoot extends StatefulWidget
{
    /**
     * @param \Closure(Snackbar): void $notify 「弹一条底部通知」—— 由组合根接线
     * @param \Closure(int, \Closure): void $delay 「N 毫秒后跑这个回调」—— 由组合根接线
     *        （`Application::after()`）。ComboBox 页用它模拟远程搜索的**异步到达**。
     *
     * ★ 为什么是**闭包**而不是 `Application`：`Widget` 层不该认识 `Drift\App`
     *   （§4.3），而且页面只该拿到"我能做什么"，不是"整个应用"。
     * ★ 为什么**不可空**：可选对象在 AOT 侧会退化 `php::Var`（同 `?float` 用
     *   `NAN` 那条纪律）⇒ 一律由组合根给一个真的。
     */
    public function __construct(
        private \Closure $notify,
        private \Closure $delay,
    ) {
        parent::__construct();
    }

    /**
     * @param \Closure(Snackbar): void $notify
     * @param \Closure(int, \Closure): void $delay
     */
    public static function make(\Closure $notify, \Closure $delay): self
    {
        return new self($notify, $delay);
    }

    public function createState(): State
    {
        return new ShowcaseState($this->notify, $this->delay);
    }
}

/**
 * 导航条目 + 选中态。
 *
 * ★ `$entries` 平行存 `[名称, 页工厂]`（**不是** map：PHP 数组键不能是 Closure）。
 * ★ 工厂是 `Closure` 而不是类型名：页类构造**至多一个闭包参数**，`static fn(): Widget
 *   => new XxxPage()` 对 AOT 直白（类名常量字符串形态还没在 codegen 验过）。
 *   ★ 通知页是唯一带参的：它要 `$notify`（浮层归 `Application`，页面够不到 —— §4.3）。
 */
final class ShowcaseState extends State
{
    /** 未选中导航按钮的底色（LayUI 式浅灰） */
    private const NAV_IDLE = 0xF2F2F2;

    /** @var list<array{0: string, 1: \Closure(): Widget}> */
    private array $entries = [];

    private int $selected = 0;

    /**
     * @param \Closure(Snackbar): void $notify 组合根接线的通知能力（转手给通知页）
     * @param \Closure(int, \Closure): void $delay 组合根接线的延时能力（转手给 ComboBox 页）
     */
    public function __construct(
        private \Closure $notify,
        private \Closure $delay,
    ) {
    }

    public function initState(): void
    {
        $this->entries = [
            ['布局 Layout', static fn(): Widget => new LayoutPage()],
            ['文本 Text', static fn(): Widget => new TextPage()],
            ['按钮 Button', static fn(): Widget => new ButtonsPage()],
            ['表单 Form', static fn(): Widget => new FormPage()],
            ['列表 List', static fn(): Widget => new ListPage()],
            ['长列表 Big', static fn(): Widget => new BigListPage()],
            ['自绘 Paint', static fn(): Widget => new CustomPaintPage()],
            ['反馈 Feedback', static fn(): Widget => new FeedbackPage()],
            // ★ 追加**在末尾**：前 10 项的 y 坐标不变 ⇒ 既有真机探针量出的坐标不用改。
            ['动画 Motion', static fn(): Widget => new MotionPage()],
            ['分段 Segmented', static fn(): Widget => new SegmentedPage()],
            ['图片 Image', static fn(): Widget => new ImagePage()],
            ['滑杆 Slider', static fn(): Widget => new SliderPage()],
            ['提示 Tooltip', static fn(): Widget => new TooltipPage()],
            ['通知 Snackbar', fn(): Widget => new SnackbarPage($this->notify)],
            ['下拉 Select', static fn(): Widget => new SelectPage()],
            ['可编辑 Combo', fn(): Widget => new ComboBoxPage($this->delay)],
            ['网格 Grid', static fn(): Widget => new GridPage()],
            ['宽高比 Aspect', static fn(): Widget => new AspectPage()],
            ['富文本 Rich', static fn(): Widget => new RichTextPage()],
            // ★ 追加**在末尾**（同上条注释）：前 19 项的 y 坐标不变 ⇒ 既有真机探针不用改。
            ['日志 Log', static fn(): Widget => new LogPage()],
        ];
    }

    public function build(BuildContext $ctx): Widget
    {
        // ★ 全局色板：LayUI 招牌色（与 GUI 仓 ShowcaseRoot::palette() 同键）——
        //   ThemeScope 覆盖整棵子树，Text 的角色色从主题解析 ⇒ 换主题字号/色跟着走。
        return ThemeScope::make(
            ThemeData::light()
                ->withColor(ColorToken::Accent, Color::fromRgb(0x00, 0x96, 0x88))
                ->withColor(ColorToken::Pane, Color::fromRgb(0xFF, 0xFF, 0xFF)),
            Dock::make()
                ->top($this->buildTopBar())
                ->center($this->buildBody())
        );
    }

    private function buildTopBar(): Widget
    {
        return AppBar::make()
            ->title('Drift 示例台')
            ->children([
                Text::make('LayUI 式布局 · 覆盖现有控件面'),
            ]);
    }

    /**
     * 外壳 = **浅灰背景板 + 白色导航面板 + 内容槽**（LayUI admin 的层次）。
     *
     * ★ 三个 `Card` 各有分工：最外层只当"背景板"（`->background()` 一给，
     *   `BoxWidget` 的默认边框/圆角就整套让位 —— §15.7 纪律 3），中间那层是
     *   **真正的卡片**（白底 + 1px 边框 + 圆角 2 + 内边距），内容页里每个 demo
     *   再各自一张卡片。
     * ★ `cross(Stretch)` 让导航面板拉满高：LayUI 的左侧导航就是全高白底，
     *   少了它导航只有内容那么高，下面露出灰底 —— 一眼就"没做完"。
     */
    private function buildBody(): Widget
    {
        return Card::make(
            Row::make()->cross(FlexAlign::Stretch)->children([
                // ★ 侧栏**定宽 200**（LayUI 侧栏的形态）：没有 `SizedBox` 时宽度只能由
                //   "最长那个按钮文字"撑出来，改文案/换语言就变。外层 `Row::cross(Stretch)`
                //   负责把它拉满高，`SizedBox` 只定宽 —— 两轴各自管一件事。
                SizedBox::make(Card::make($this->buildNav())->paddingAll(10.0))->width(200.0),
                // ★ 滚动归**页根**（每页自己 `Card(ScrollView(...))`）：外壳再套一层
                //   就是嵌套滚动 —— 内层永远"量到全部内容"，外层滚不动，两层都白搭。
                Expanded::make($this->pageFor($this->selected)()),
            ])
        )
            ->background(Color::fromRgb(0xF2, 0xF3, 0xF5))
            ->paddingAll(10.0);
    }

    /** @return list<Widget> */
    private function navButtons(): array
    {
        $out = [];
        foreach ($this->entries as $index => $entry) {
            $name = $entry[0];
            $out[] = $this->navButton($name, $index);
        }
        return $out;
    }

    private function navButton(string $name, int $index): Widget
    {
        $isHere = $index === $this->selected;

        // ★ 未选中用 `Flat`（无底无边框 ⇒ 看起来像菜单项，不像一排按钮）；
        //   选中用 `Primary` + 显式主色（主题的 Accent 是蓝的，示例台要 LayUI 的墨绿）。
        $button = Button::make($name)
            ->variant($isHere ? ButtonVariant::Primary : ButtonVariant::Flat)
            ->onPressed(function () use ($index): void {
                // ★ `$this` 在闭包里（**不能** static fn —— 要调 setState）
                $this->select($index);
            });

        if ($isHere) {
            $button->background(Color::fromRgb(0x00, 0x96, 0x88));
        }

        return $button;
    }

    private function buildNav(): Widget
    {
        // ★ `cross(Stretch)` 在这里**安全**：宽度已被外层 `SizedBox(200)` 定死，
        //   `Stretch` 拉的是"可用宽"= 180（扣掉卡片 padding），不再是整行 940。
        //   ⇒ 导航项整行可点（LayUI 侧栏就是这个形态），右边缘也齐。
        return Column::make()->cross(FlexAlign::Stretch)->spacing(2.0)->children($this->navButtons());
    }

    /** @return \Closure(): Widget */
    private function pageFor(int $index): \Closure
    {
        $entry = $this->entries[$index] ?? null;
        if ($entry === null) {
            // 索引越界只能在测试里出现：兜底回第 0 页（不静默成空窗）
            return $this->entries[0][1];
        }
        return $entry[1];
    }

    private function select(int $index): void
    {
        $this->setState(function () use ($index): void {
            $this->selected = $index;
        });
    }
}
