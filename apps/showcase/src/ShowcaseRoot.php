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
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

use App\Showcase\Pages\ButtonsPage;
use App\Showcase\Pages\FeedbackPage;
use App\Showcase\Pages\FormPage;
use App\Showcase\Pages\LayoutPage;
use App\Showcase\Pages\ListPage;
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
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new ShowcaseState();
    }
}

/**
 * 导航条目 + 选中态。
 *
 * ★ `$entries` 平行存 `[名称, 页工厂]`（**不是** map：PHP 数组键不能是 Closure）。
 * ★ 工厂是 `Closure` 而不是类型名：页类构造无参，`static fn(): Widget => new XxxPage()`
 *   对 AOT 直白（类名常量字符串形态还没在 codegen 验过）。
 */
final class ShowcaseState extends State
{
    /** 未选中导航按钮的底色（LayUI 式浅灰） */
    private const NAV_IDLE = 0xF2F2F2;

    /** @var list<array{0: string, 1: \Closure(): Widget}> */
    private array $entries = [];

    private int $selected = 0;

    public function initState(): void
    {
        $this->entries = [
            ['布局 Layout', static fn(): Widget => new LayoutPage()],
            ['文本 Text', static fn(): Widget => new TextPage()],
            ['按钮 Button', static fn(): Widget => new ButtonsPage()],
            ['表单 Form', static fn(): Widget => new FormPage()],
            ['列表 List', static fn(): Widget => new ListPage()],
            ['反馈 Feedback', static fn(): Widget => new FeedbackPage()],
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
                Card::make($this->buildNav())->paddingAll(10.0),
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

        return Button::make($name)
            ->variant($isHere ? ButtonVariant::Primary : ButtonVariant::Default)
            ->background($isHere ? Color::fromRgb(0x00, 0x96, 0x88) : Color::fromRgb(0xF2, 0xF2, 0xF2))
            ->onPressed(function () use ($index): void {
                // ★ `$this` 在闭包里（**不能** static fn —— 要调 setState）
                $this->select($index);
            });
    }

    private function buildNav(): Widget
    {
        // ★ 这里**不能**用 `cross(Stretch)`：`Stretch` 的语义是"拉满**可用**宽"
        //   （与 Flutter 一致），而导航卡的可用宽是整行 940 ⇒ 侧栏会被撑满、
        //   把内容区挤到 0（真机第一帧 FATAL 就是这么来的）。要固定侧栏宽需要
        //   "定尺寸"原语（`SizedBox` 那类），Drift 目前没有 —— 见 DESIGN §24.2 #25。
        return Column::make()->spacing(4.0)->children($this->navButtons());
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
