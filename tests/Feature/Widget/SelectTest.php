<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\SelectApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;
use Drift\Widget\Button;
use Drift\Widget\Option;
use Drift\Widget\Select;

/**
 * 下拉选择（W8-2，声明式浮层面板，2026-10-23）。
 *
 * 钉住四件事：① 激活 ⇒ 框架开面板（`Widget::overlayPanel()` 的通用缝）；
 * ② 面板参数（barrier / owner / restoreFocus 三处最容易搞反的）；③ 点选项 ⇒
 * 回调 + 收面板 + 焦点回控件；④ **不影响既有控件**（没声明浮层的按钮照旧 onPressed）。
 *
 * ★ 反例自检三条：值不匹配 ⇒ 不选（不夹到第一项）/ 点面板外只关不开 / host 按钮
 *   没有被新分支吃掉。
 * ★ ⑦ `PopupPanel::maxHeight()`：不限高时 30 项面板会被 `placeEntry()` 的"超大退化"
 *   夹成整个客户区那么高，超出部分被面板自己的 `Overflow::Clip` 裁掉 ⇒ 第 N 项之后
 *   **点不到**。判据**不按条目数猜**：先无界排一遍量自然高，超过上限才按上限重排。
 */
final class SelectTest extends SmokeTestCase
{
    private const W = 600.0;
    private const H = 400.0;

    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(self::W, self::H));
    }

    private function boot(): SelectApplication
    {
        $app = new SelectApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $app->root()?->effectiveBox()?->layout($this->constraints());
        $app->frame(1000.0, $this->constraints());

        return $app;
    }

    public function testPureValueToIndexMapping(): void
    {
        check(
            'Select · 受控值匹配 ⇒ selectedIndex 命中那一项',
            Select::make([Option::of('a'), Option::of('b')])->value('b')->selectedIndex() === 1,
            'idx=' . Select::make([Option::of('a'), Option::of('b')])->value('b')->selectedIndex(),
        );
        // ★ 反例：不匹配 ⇒ -1（**不夹到 0**）—— 静默选中第一项是替用户做决定
        //   （与 `SegmentedControl` 的"越界选中 ⇒ 全不选"同一条纪律）。
        check(
            'Select · 反例：值不匹配 ⇒ selectedIndex = -1（显示 placeholder，不夹到第一项）',
            Select::make([Option::of('a'), Option::of('b')])->value('nope')->selectedIndex() === -1,
            'idx=' . Select::make([Option::of('a'), Option::of('b')])->value('nope')->selectedIndex(),
        );
    }

    public function testActivationOpensPanelWithExpectedEntryParams(): void
    {
        $app = $this->boot();
        check(
            'Select · 初始没有浮层',
            $app->overlayCount() === 0,
            'count=' . $app->overlayCount(),
        );

        // —— ① 激活（真实指针链）⇒ 框架开面板 ——
        $tc = $app->triggerCenter();
        $app->dispatchInput(PointerEvent::down($tc[0] ?? 0.0, $tc[1] ?? 0.0));
        $app->frame(1100.0, $this->constraints());

        check(
            'Select · 激活触发框 ⇒ 开了一层浮层（Widget::overlayPanel() 的通用缝）',
            $app->overlayCount() === 1,
            'count=' . $app->overlayCount() . ' trigger=' . \var_export($tc, true),
        );

        $top = $app->topEntry();
        $box = $top?->element?->effectiveBox();
        $size = $box?->size();
        $w = $size?->width ?? 0.0;
        $h = $size?->height ?? 0.0;

        // ★★ 尺寸必须单独断言：Tooltip 那次"位置完全正确、尺寸 0"（AOT 外部类常量
        //   求值成 0）就是这样漏过去的 —— 位置对不代表画得出来。
        check(
            'Select · 面板宽高非零（位置对但尺寸 0 = 画面一丝不变）',
            $w > 0.0 && $h > 0.0,
            'size=' . $w . 'x' . $h,
        );
        check(
            'Select · 面板宽 ≥ PANEL_MIN_WIDTH（一列选项不能挤成一条）',
            $w >= Select::PANEL_MIN_WIDTH,
            'w=' . $w . ' min=' . Select::PANEL_MIN_WIDTH,
        );

        $trigger = $app->trigger();
        check(
            'Select · 面板参数：锚点=控件 / owner=控件（页面走了自动收尸）/ barrier=true（点外面才关得掉）'
                . ' / scrim=false / hitTest=true / dismissOnTapOutside / dismissOnEsc / 不抢焦点',
            $top !== null
                && $top->anchor === $trigger
                && $top->owner === $trigger
                && $top->barrier === true
                && $top->scrim === false
                && $top->hitTest === true
                && $top->dismissOnTapOutside === true
                && $top->dismissOnEsc === true
                && $top->focusTrap === false,
            'anchor=' . ($top?->anchor === $trigger ? 'yes' : 'no')
                . ' owner=' . ($top?->owner === $trigger ? 'yes' : 'no')
                . ' barrier=' . ($top?->barrier ? '1' : '0')
                . ' hitTest=' . ($top?->hitTest ? '1' : '0'),
        );

        // ★★ 与 Tooltip / Snackbar **相反**：那两个打开时焦点不在锚点上（悬停 / 定时器
        //   触发），所以必须把 `restoreFocus` 抹成 null；这里是控件自己激活 ⇒ 快照
        //   天然正确，收面板时焦点要回到控件上（否则选完一项焦点就丢了）。
        check(
            'Select · restoreFocus = 触发框（选完焦点回到控件，与 Tooltip/Snackbar 相反）',
            $top?->restoreFocus === $trigger,
            'restore=' . ($top?->restoreFocus === $trigger ? 'yes' : 'no'),
        );
    }

    public function testPanelContentMarksSelectedPrimary(): void
    {
        $app = $this->boot();
        $tc = $app->triggerCenter();
        $app->dispatchInput(PointerEvent::down($tc[0] ?? 0.0, $tc[1] ?? 0.0));
        $app->frame(1100.0, $this->constraints());

        $options = $app->optionButtons();
        check(
            'Select · 面板里恰好 3 个选项按钮',
            \count($options) === 3,
            'n=' . \count($options),
        );

        // 选中项用 Primary 底（不用 "✓" 前缀，字模子集没有 U+2713）
        $variants = [];
        foreach ($options as $opt) {
            $widget = $opt->widget();
            $variants[] = $widget instanceof Button ? $widget->variantValue()->name : '?';
        }
        check(
            'Select · 选中项（上海市）是 Primary、其余是 Flat',
            $variants === ['Flat', 'Primary', 'Flat'],
            'variants=' . \implode(',', $variants),
        );
    }

    public function testClickOptionCommitsAndRestoresFocus(): void
    {
        $app = $this->boot();
        $tc = $app->triggerCenter();
        $app->dispatchInput(PointerEvent::down($tc[0] ?? 0.0, $tc[1] ?? 0.0));
        $app->frame(1100.0, $this->constraints());
        $trigger = $app->trigger();

        // —— ③ 点第 1 个选项（北京市）⇒ 回调 + 收面板 + 焦点回控件 ——
        $c0 = $app->optionCenter(0);
        $app->dispatchInput(PointerEvent::down($c0[0] ?? 0.0, $c0[1] ?? 0.0));
        $app->frame(1200.0, $this->constraints());
        check(
            'Select · 点选项 ⇒ onChanged 收到那一项的 value（不是 label、不是下标）',
            $app->changed === ['110000'],
            'changed=' . \implode(',', $app->changed),
        );
        check(
            'Select · 点选项 ⇒ 面板自己收掉（dismiss 由 Application 注入）',
            $app->overlayCount() === 0,
            'count=' . $app->overlayCount(),
        );
        check(
            'Select · 点选项 ⇒ 焦点回到触发框（不是"焦点丢了"）',
            $app->focusedElement() === $trigger,
            'focused=' . ($app->focusedElement() instanceof Button ? 'button' : 'other'),
        );
    }

    public function testTapOutsideAndEscDismiss(): void
    {
        $app = $this->boot();
        $tc = $app->triggerCenter();

        // —— ④ 再开一次 ⇒ 点面板外 ⇒ 关且不开新的（`barrier` 吞掉那次按下）——
        $app->dispatchInput(PointerEvent::down($tc[0] ?? 0.0, $tc[1] ?? 0.0));
        $app->frame(1300.0, $this->constraints());
        $hostBox = $app->host()?->effectiveBox();
        [$hx, $hy] = $hostBox === null ? [0.0, 0.0] : boxOrigin($hostBox);
        $hw = $hostBox?->size()?->width ?? 0.0;
        $hh = $hostBox?->size()?->height ?? 0.0;

        $app->hostPressed = [];
        $app->dispatchInput(PointerEvent::down($hx + $hw / 2.0, $hy + $hh / 2.0));
        $app->frame(1400.0, $this->constraints());
        check(
            'Select · 点面板外 ⇒ 关面板，且那次按下被吞（host 收不到 —— 下拉的标准手感）',
            $app->overlayCount() === 0 && $app->hostPressed === [],
            'count=' . $app->overlayCount() . ' host=' . \implode(',', $app->hostPressed),
        );

        // —— ⑤ ESC ⇒ 关 ——
        $app->dispatchInput(PointerEvent::down($tc[0] ?? 0.0, $tc[1] ?? 0.0));
        $app->frame(1500.0, $this->constraints());
        $before = $app->overlayCount();
        $app->dispatchInput(KeyEvent::down(KeyChord::escape()));
        $app->frame(1600.0, $this->constraints());
        check(
            'Select · ESC ⇒ 关面板（dismissOnEsc）',
            $before === 1 && $app->overlayCount() === 0,
            'before=' . $before . ' after=' . $app->overlayCount(),
        );
    }

    public function testPlainButtonStillUsesOnPressed(): void
    {
        $app = $this->boot();
        $hostBox = $app->host()?->effectiveBox();
        [$hx, $hy] = $hostBox === null ? [0.0, 0.0] : boxOrigin($hostBox);
        $hw = $hostBox?->size()?->width ?? 0.0;
        $hh = $hostBox?->size()?->height ?? 0.0;

        // —— ⑥ 反例：没声明浮层的按钮 ⇒ 激活照旧走 onPressed（新分支是加性的）——
        $app->hostPressed = [];
        $app->dispatchInput(PointerEvent::down($hx + $hw / 2.0, $hy + $hh / 2.0));
        $app->frame(1700.0, $this->constraints());
        check(
            'Select · 反例：没有 overlayPanel() 的按钮 ⇒ 激活照旧走 onPressed（新分支加性）',
            $app->hostPressed === ['host'] && $app->overlayCount() === 0,
            'host=' . \implode(',', $app->hostPressed) . ' count=' . $app->overlayCount(),
        );
    }

    public function testLongListIsHeightCappedAndScrolls(): void
    {
        $app = $this->boot();

        // ★ 为什么必须有 `maxHeight()`：不限高时 30 项的面板被 `placeEntry()` 的
        //   "超大退化"夹成**整个客户区**那么高，超出部分被面板自己的 `Overflow::Clip`
        //   裁掉 ⇒ 第 N 项之后**点不到**；而 ⑤ 的夹紧只挪 x/y 不改高，救不了"点不到"。
        $ltc = $app->longTriggerCenter();
        $app->dispatchInput(PointerEvent::down($ltc[0] ?? 0.0, $ltc[1] ?? 0.0));
        $app->frame(1800.0, $this->constraints());
        check(
            'Select · 长列表（30 项）⇒ 激活即开面板',
            $app->overlayCount() === 1,
            'count=' . $app->overlayCount(),
        );

        $lBox = $app->topEntry()?->element?->effectiveBox();
        $lh = $lBox?->size()?->height ?? 0.0;
        check(
            'Select · 长列表面板高 = PANEL_MAX_HEIGHT（限高生效）',
            \abs($lh - Select::PANEL_MAX_HEIGHT) < 0.01,
            'h=' . $lh . ' cap=' . Select::PANEL_MAX_HEIGHT,
        );

        // 反向自检：自然高（30 行，chrome 只增不减）必须**远超**上限 —— 否则这条用例
        // 根本没在验"限高"，只是在验一个本来就够短的面板（量具只会绿的那种）。
        $row = $app->optionButtons()[0]?->effectiveBox()?->size()?->height ?? 0.0;
        check(
            'Select · 反例：自然高（30 行）远超上限 ⇒ 这条用例真的在验限高',
            30.0 * $row > $lh && $row > 0.0,
            'natural=' . (30.0 * $row) . ' actual=' . $lh,
        );

        $sv = $app->panelScrollView();
        $view = $sv?->size()?->height ?? 0.0;
        $max = $sv?->maxScroll(Axis::Vertical) ?? -1.0;
        check(
            'Select · 长列表面板里有 ScrollView，视口比盒矮（chrome 扣掉了）且 maxScroll > 0',
            $sv !== null && $view > 0.0 && $view < $lh && $max > 0.0,
            'view=' . $view . ' box=' . $lh . ' maxScroll=' . $max,
        );

        // 可滚距离 = 内容高 − 视口高（**推导**，不是抄实测值：行高与项数都从盒里读）
        check(
            'Select · 长列表面板 maxScroll = 30 行内容高 − 视口高（滚得到底）',
            \abs($max - (30.0 * $row - $view)) < 0.01,
            'maxScroll=' . $max . ' expect=' . (30.0 * $row - $view),
        );

        // ★ "为什么不限高就点不到"的**实证**：第 30 项的中心在面板下沿之外
        $last = $app->optionCenter(29);
        [$px, $py] = $lBox === null ? [0.0, 0.0] : boxOrigin($lBox);
        $bottom = $py + $lh;
        check(
            'Select · 第 30 项在面板下沿之外 ⇒ 不限高/不滚就点不到它（限高的动机）',
            $last !== null && $last[1] > $bottom,
            'last=' . ($last[1] ?? -1.0) . ' panelBottom=' . $bottom,
        );

        // ★★ 端到端：滚一格（`WHEEL_NOTCH_PX` = 48px）⇒ 第 0 项真的上移 48px。
        //   走真实 `ScrollEvent` 通道（命中 → 找最近 ScrollViewState → glideByAxis），
        //   不直接改 state。
        //   ★ 滚轮是**带动画**的（`glideByAxis` 摊成若干帧）⇒ 必须跑到收口才等于 48px；
        //     拍一帧只走一部分（`ScrollWheelTest` 里已单独钉住"第二帧只走一部分"）。
        $beforePos = $app->optionCenter(0);
        $cx = $px + ($lBox?->size()?->width ?? 0.0) / 2.0;
        $cy = $py + $lh / 2.0;
        $app->dispatchInput(ScrollEvent::make($cx, $cy, 1.0));
        $ts = 1900.0;
        pumpScroll($app, $this->constraints(), $ts);
        $afterPos = $app->optionCenter(0);
        check(
            'Select · 长列表面板滚一格 ⇒ 第 0 项上移 48px（WHEEL_NOTCH_PX，真实滚轮通道）',
            $beforePos !== null && $afterPos !== null
                && \abs(($beforePos[1] - $afterPos[1]) - 48.0) < 0.01,
            'dy=' . (($beforePos[1] ?? 0.0) - ($afterPos[1] ?? 0.0)),
        );

        // ★ 滚完面板还开着、还在限高（滚动不该把面板关掉或撑开）
        check(
            'Select · 滚完之后面板仍在、高度仍是上限',
            $app->overlayCount() === 1
                && \abs(($app->topEntry()?->element?->effectiveBox()?->size()?->height ?? 0.0) - Select::PANEL_MAX_HEIGHT) < 0.01,
            'count=' . $app->overlayCount(),
        );
    }

    public function testShortListHugsContent(): void
    {
        $app = $this->boot();

        // —— ⑧ 反例：短列表（3 项）⇒ 面板**贴着内容**，不凭空撑到上限 ——
        // ★ 这一条是限高的反面：若 `RenderPopupPanel` 无脑按上限排，3 项的面板会拖出
        //   一截空白（"明明只有 3 项却有 260px 高的面板"）。判定用**实测 chrome**，
        //   不写死数字。
        $tc = $app->triggerCenter();
        $app->dispatchInput(PointerEvent::down($tc[0] ?? 0.0, $tc[1] ?? 0.0));
        $app->frame(2000.0, $this->constraints());

        $sBox = $app->topEntry()?->element?->effectiveBox();
        $sh = $sBox?->size()?->height ?? 0.0;
        $sv = $app->panelScrollView();
        $sView = $sv?->size()?->height ?? 0.0;
        $sMax = $sv?->maxScroll(Axis::Vertical) ?? -1.0;
        $sRow = $app->optionButtons()[0]?->effectiveBox()?->size()?->height ?? 0.0;
        check(
            'Select · 反例：短列表（3 项）⇒ 面板高 = 3 行 + chrome（**贴着内容**，不撑到上限）',
            \abs($sh - (3.0 * $sRow + ($sh - $sView))) < 0.01 && $sh < Select::PANEL_MAX_HEIGHT,
            'h=' . $sh . ' rows=' . (3.0 * $sRow) . ' view=' . $sView . ' cap=' . Select::PANEL_MAX_HEIGHT,
        );
        check(
            'Select · 反例：短列表 maxScroll = 0（内容不溢出 ⇒ 不画滚动条，浏览器同行为）',
            $sMax === 0.0,
            'maxScroll=' . $sMax,
        );
    }
}
