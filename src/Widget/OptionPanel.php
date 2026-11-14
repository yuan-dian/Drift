<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\EdgeInsets;
use Drift\State\Subject;

/**
 * 选项面板的共用外壳（`Select` / `ComboBox`，2026-10-24）。
 *
 * 它只管两件事，都是**每多一个选项控件就要抄一遍**的：
 *
 * 1. **面板 chrome**：`PopupPanel` + `ScrollView` + 内距 + 阴影 + 宽高上限。
 *    这条配方在 `Menu` / `ContextMenu` / `Select` / `ComboBox` 各写过一遍；
 *    这里收口给"内容会随状态变"的那一类用（菜单是打开时建一次就走，不需要它）。
 * 2. **跨树订阅**：浮层是**第二棵根渲染树**，主树重建**不带动**它。面板必须
 *    自己 `watch()` 一个 `Subject` 才能跟着变（`OverlayEntry::$panel` 的字段注释
 *    就是这条设计）。订阅只能写在 `initState()`（§11.3 RT-1 / A4）—— 写在
 *    `build()` 里会让订阅数随重建次数增长。
 *
 * ★ **内容用闭包给，不用 Widget**：`content()` 在**每次重建**时调用，所以它必须
 *   读 owner 的**当前**值。传一个 Widget 进来就锁死了建面板那一刻的快照 —— 那正是
 *   重构前 `Select` 的老毛病（面板只建一次，选中高亮停在旧值上）。
 *
 * ★ `onClosed` 是"面板被回收"的唯一汇合点：点面板外 / owner 清扫 / Esc 三条路径
 *   都会走到 `State::dispose()`，而只有 Esc 经过控件自己的键盘分支 ⇒ 靠键盘分支
 *   复位会漏掉另外两条。
 *
 * @internal 只给选项类控件用（应用不该直接 new 它）
 */
final class OptionPanel extends StatefulWidget
{
    /** 内容四周留白（逻辑像素）—— 面板有边框，行贴边会显得挤 */
    public const float PADDING = 4.0;

    /** 阴影高度（逻辑像素）—— 浮层要"浮"起来 */
    public const float ELEVATION = 4.0;

    private function __construct(
        private readonly Subject $source,
        private readonly \Closure $content,
        private readonly float $minWidth,
        private readonly float $maxHeight,
        private readonly ?\Closure $onClosed,
        private readonly bool $scrollable,
    ) {
        parent::__construct();
    }

    /**
     * @param Subject $source 面板订阅它（owner 改了"内容该长什么样"就 publish）
     * @param \Closure(): Widget $content 面板内容（通常是一个 `Column`）。**每次重建都调**
     * @param float $minWidth 面板最小宽
     * @param float $maxHeight 面板高度上限（`RenderPopupPanel` 按实测自然高决定"贴着还是滚"）
     * @param (callable(): void)|null $onClosed 面板被回收时调（三条关闭路径的汇合点）
     * @param bool $scrollable 内容**自己不是**滚动视口时才为真（见 `build()` 的 ★）
     */
    public static function make(
        Subject $source,
        \Closure $content,
        float $minWidth,
        float $maxHeight,
        ?callable $onClosed = null,
        bool $scrollable = true,
    ): self {
        $closed = $onClosed === null ? null : $onClosed(...);

        return new self($source, $content, $minWidth, $maxHeight, $closed, $scrollable);
    }

    /** @internal */
    public function source(): Subject
    {
        return $this->source;
    }

    /** @internal 每次调用都重新求值（见类头 ★） */
    public function content(): Widget
    {
        return ($this->content)();
    }

    /** @internal */
    public function minWidthValue(): float
    {
        return $this->minWidth;
    }

    /** @internal */
    public function maxHeightValue(): float
    {
        return $this->maxHeight;
    }

    /** @internal */
    public function scrollableValue(): bool
    {
        return $this->scrollable;
    }

    /** @internal */
    public function notifyClosed(): void
    {
        if ($this->onClosed === null) {
            return;
        }

        ($this->onClosed)();
    }

    public function createState(): State
    {
        return new OptionPanelState();
    }
}

/**
 * @internal `OptionPanel` 的私有 State —— 和控件同文件，不进公共面。
 */
final class OptionPanelState extends State
{
    #[\Override]
    public function initState(): void
    {
        // ★ 订阅**只能**在 initState()（§11.3 RT-1 / A4）。写在 build() 里会让
        //   订阅数随重建次数增长（面板每改一次高亮就重建一次 ⇒ 指数爆炸）。
        $this->watch($this->panel()->source(), function (): void {
            $this->markDirty();
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        $panel = $this->panel();
        $content = $panel->content();

        // ★ 必须套 `ScrollView`：`PopupPanel::maxHeight()` 只给上限，
        //   "超高就滚"是靠内容自己是滚动视口实现的（它不会替内容滚）。
        // ★★ 但内容**自己已经是滚动视口**时不能套（`TimeView` 的 `LazyColumn`
        //   就是）：嵌套滚动会在布局期拿"外层给的无界主轴"算窗口，实测直接崩
        //   （`RenderScrollView` 的不变量）。`DatePicker` / `DateTimePicker` 传
        //   `$scrollable = false` —— 日历与时刻列都是定高的，超限由 `PopupPanel`
        //   裁剪。
        if ($panel->scrollableValue()) {
            $content = ScrollView::make($content);
        }

        return PopupPanel::make($content)
            ->minWidth($panel->minWidthValue())
            ->maxHeight($panel->maxHeightValue())
            ->padding(EdgeInsets::symmetric(OptionPanel::PADDING, OptionPanel::PADDING))
            ->elevation(OptionPanel::ELEVATION);
    }

    public function dispose(): void
    {
        // ★ 三条关闭路径（点面板外 / owner 清扫 / Esc）都汇到这里
        $this->panel()->notifyClosed();
        parent::dispose();
    }

    private function panel(): OptionPanel
    {
        $widget = $this->widget();
        if (!$widget instanceof OptionPanel) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('OptionPanelState 只能配 OptionPanel（框架内部不变量被破坏）');
        }

        return $widget;
    }
}
