<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Smoke\Tree\CounterState;
use Drift\Dev\Smoke\Tree\CounterWidget;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\ActivationContext;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Offstage;
use Drift\Widget\Segment;
use Drift\Widget\StatefulElement;
use Drift\Widget\TabView;
use Drift\Widget\Text;

/**
 * `TabView`：多页签容器（§12.1 第 4/7 条）。
 *
 * ## 判据要钉的是"保活"，不是"画对"
 *
 * 页签条是复用 `SegmentedControl`（它的判据在 `SegmentedControlTest`），本文件
 * 只钉 `TabView` 自己加的那层：**换页不丢状态**。
 *
 * ★ 最容易写错成"看起来能跑"的那一版：
 *
 * ```php
 * Column::make()->children([$strip, $pages[$selected]])
 * ```
 *
 * 它切页也正常显示 —— **但旧页被 unmount、State 被 dispose**。这个错在画面上
 * **看不出来**（新页照样画出来），只有"切回去发现滚动位置没了"才发现，而那已经
 * 是用户报的 bug 了。⇒ 判据必须直接抓 State 的**对象身份**，不能抓"画出来了没"。
 *
 * ## 受控为什么也要钉
 *
 * `TabView` 不自持选中（与 `SegmentedControl` 同纪律）。"不自持"这件事若只写在注释
 * 里，下一个人加个 `$this->selected = $i` 就破了，而且**画面反而"变好用了"**
 * （点一下就切，不用应用接）⇒ 没人会报。判据 `点击后画面不变` 就是它的守卫。
 */
final class TabViewTest extends SmokeTestCase
{
    /**
     * ① 核心：切走再切回，页面的 State 是**同一个对象**。
     *
     * ★ 为什么用对象身份而不是"值没变"：值没变可能是"重建后恰好一样"；
     *   而"同一个对象"才等价于"Element 没被卸载、State 没 dispose"。
     */
    public function testSwitchingAwayAndBackKeepsPageState(): void
    {
        $root = $this->mountView($this->view(0));
        $column = $root->childAt(0);
        check(
            'TabView · `build()` 出来的是 `Column`（条与页同层竖向排）',
            $column?->widget() instanceof Column,
            $column === null ? 'childAt(0) 为 null' : $column->widget()::class
        );

        $off0 = $column?->childAt(1);
        $off1 = $column?->childAt(2);
        check('TabView · 子层是「条 + 每页一个 `Offstage`」', $off0?->widget() instanceof Offstage && $off1?->widget() instanceof Offstage);

        // 选中 0 ⇒ 第 0 页在树里、第 1 页不在（不 layout / 不 paint / flush 走不到）
        check('TabView · 选中 0 ⇒ 第 0 页在树里', $off0?->childCount() === 1, 'childCount=' . (string) ($off0?->childCount() ?? -1));
        check('TabView · 选中 0 ⇒ 第 1 页**不在**树里', $off1?->childCount() === 0, 'childCount=' . (string) ($off1?->childCount() ?? -1));

        $state0 = $this->pageState($off0);
        check('TabView · 页面的 State 建出来了', $state0 instanceof CounterState);
        $state0?->bump(); // 攒一次 setState（切走再切回要还在）

        // 切到第 1 页
        $root->update($this->view(1));
        check(
            'TabView · 切到 1 ⇒ 第 0 页被摘出树，但**没被卸载**（State 没 dispose）',
            $off0?->childCount() === 0 && $state0 !== null && !$state0->disposed,
            'childCount=' . (string) ($off0?->childCount() ?? -1) . ' disposed=' . ($state0?->disposed ? 'yes' : 'no')
        );
        check('TabView · 切到 1 ⇒ 第 1 页进树', $off1?->childCount() === 1, 'childCount=' . (string) ($off1?->childCount() ?? -1));

        // ★ "显示的是哪一页"必须单独钉：`Offstage` 全开 / 索引错位这两种错，
        //   画面上都看不出来（第一页照样画出来），只有数"树里现在是哪种控件"才抓得住。
        check(
            'TabView · 切到 1 ⇒ 树里是第 1 页的控件（`Text`），第 0 页的（`CounterWidget`）已不在树里',
            $this->countWidgets($root, Text::class) === 1 && $this->countWidgets($root, CounterWidget::class) === 0,
            \sprintf('Text=%d Counter=%d', $this->countWidgets($root, Text::class), $this->countWidgets($root, CounterWidget::class))
        );

        // 切回来
        $root->update($this->view(0));
        $back = $this->pageState($off0);
        check(
            'TabView · 切回来 ⇒ 是**同一个 State 对象**（滚动位置 / 表格选中 / 表单草稿都还在）',
            $back === $state0 && $state0 !== null,
            $state0 === null ? 'state0 为 null' : ($back === $state0 ? 'same' : 'DIFFERENT object')
        );
        check(
            'TabView · 切回来 ⇒ 期间攒的 `setState` 还在（bumps 仍是 1）',
            $state0?->bumps === 1,
            'bumps=' . (string) ($state0?->bumps ?? -1)
        );

        $this->flushChecks();
    }

    /**
     * ② 只有选中页参与布局 —— 隐藏页**连盒都没有**（不是"画了但看不见"）。
     *
     * ★ 这条是把 `Offstage` 与 `Stack + Opacity(0)` / `IndexedStack` 区分开的那件事：
     *   后两者隐藏页照样 layout，是本仓明确否掉的（`Offstage` 类头）。
     */
    public function testHiddenPagesHaveNoBox(): void
    {
        $root = $this->mountView($this->view(0));
        $column = $root->childAt(0);
        $flex = $column?->renderObject();

        $count = $flex === null ? -1 : \count($flex->children());
        check(
            'TabView · 渲染盒只收到「条 + 选中页」两个（隐藏页没有盒 ⇒ 不参与布局）',
            $count === 2,
            '盒数=' . (string) $count
        );

        $root->update($this->view(1));
        $count2 = $flex === null ? -1 : \count($flex->children());
        check(
            'TabView · 切页后盒数**不变**（恒 = 条 + 1 页 ⇒ 隐藏页从不占布局槽位）',
            $count2 === 2,
            '盒数=' . (string) $count2
        );

        $this->flushChecks();
    }

    /**
     * ③ 内容区吃掉条以外的**全部**高度。
     *
     * ★ 不给的话每页高度跟着内容变 ⇒ 切页签时整个面板会跳。这条钉的是"跳不跳"。
     */
    public function testContentAreaFillsRemainingHeight(): void
    {
        $root = $this->mountView($this->view(0));
        $this->runFrame($root);

        $pageBox = $root->childAt(0)?->childAt(1)?->effectiveBox();
        if ($pageBox === null || $pageBox->size() === null) {
            check('TabView · 内容区拿到盒', false, 'effectiveBox 为 null');

            $this->flushChecks();

            return;
        }

        $height = $pageBox->size()->height;
        $bottom = $pageBox->offsetY() + $height;
        check(
            'TabView · 内容区贴底（吃掉了页签条以外的全部高度）',
            \abs($bottom - 240.0) < 0.5,
            \sprintf('bottom=%.1f（期望 240.0）', $bottom)
        );
        check(
            'TabView · 内容区高度远大于页面自身内容高（是撑满，不是 hug）',
            $height > 150.0,
            \sprintf('h=%.1f（CounterWidget 的自然高约 20 ⇒ hug 的话过不了）', $height)
        );

        $this->flushChecks();
    }

    /**
     * ④ 越界 `selected` ⇒ **全不选**（内容区空），与 `SegmentedControl` 同裁决。
     *
     * ★ 为什么不夹到 0：应用换了页签表、旧下标越界时，"静默选中第一项"是替用户
     *   做决定。全不选是显式的"该设选中了"。
     */
    public function testOutOfRangeSelectionShowsNoPage(): void
    {
        $view = $this->view(99);
        check('TabView · 越界 ⇒ `selectedIndex()` 是 -1（不是夹到 0）', $view->selectedIndex() === -1, 'index=' . (string) $view->selectedIndex());

        $root = $this->mountView($view);
        $column = $root->childAt(0);
        check('TabView · 越界 ⇒ 第 0 页不在树里', $column?->childAt(1)?->childCount() === 0);
        check('TabView · 越界 ⇒ 第 1 页也不在树里（内容区空）', $column?->childAt(2)?->childCount() === 0);

        $flex = $column?->renderObject();
        $count = $flex === null ? -1 : \count($flex->children());
        check(
            'TabView · 越界 ⇒ 渲染盒只剩页签条一个（不是"第一页"）',
            $count === 1,
            '盒数=' . (string) $count
        );

        $this->flushChecks();
    }

    /**
     * ⑤ 受控：点页签 ⇒ 回调收到**新序号**，但画面**不变**（控件不自持选中）。
     *
     * ★ "画面不变"这句最反直觉也最要紧：它看着像 bug（点了没反应），实际是纪律
     *   —— 真相只有应用那一处（`SegmentedControl` / `Checkbox` 同款）。
     */
    public function testTapReportsNewIndexButDoesNotSelfSelect(): void
    {
        $seen = [];
        $root = $this->mountView($this->view(0, static function (int $index) use (&$seen): void {
            $seen[] = $index;
        }));
        $column = $root->childAt(0);

        $segments = $this->collectSegments($root);
        check('TabView · 页签条建出两个 `Segment`', \count($segments) === 2, '段数=' . (string) \count($segments));

        $target = $segments[1] ?? null;
        if ($target instanceof Segment) {
            $target->activate(ActivationContext::none());
        }

        check(
            'TabView · 点第 2 个页签 ⇒ 回调收到 **1**（收新序号，不是"切换了"这个事件）',
            $seen === [1],
            'seen=' . \json_encode($seen)
        );
        check(
            'TabView · 受控 ⇒ 点完画面**没变**（仍显示第 0 页）—— 控件不自持选中',
            $column?->childAt(1)?->childCount() === 1 && $column?->childAt(2)?->childCount() === 0,
            \sprintf('第0页=%d 第1页=%d', $column?->childAt(1)?->childCount() ?? -1, $column?->childAt(2)?->childCount() ?? -1)
        );

        // 应用接手 ⇒ 换了 `selected()` ⇒ 这才切
        $root->update($this->view(1));
        check(
            'TabView · 应用改了 `selected()` ⇒ 这才切过去（真相只有一处）',
            $column?->childAt(1)?->childCount() === 0 && $column?->childAt(2)?->childCount() === 1,
            \sprintf('第0页=%d 第1页=%d', $column?->childAt(1)?->childCount() ?? -1, $column?->childAt(2)?->childCount() ?? -1)
        );

        $this->flushChecks();
    }

    /**
     * ⑥ 不给 `onChanged` ⇒ 页签点不动（不是"点了切不了"，是"没人接手这个变化"）。
     */
    public function testWithoutHandlerTabsAreInert(): void
    {
        $root = $this->mountView($this->view(0));
        $column = $root->childAt(0);
        $segments = $this->collectSegments($root);

        $target = $segments[1] ?? null;
        if ($target instanceof Segment) {
            $target->activate(ActivationContext::none());
        }

        check(
            'TabView · 不给 `onChanged` ⇒ 页签不响应激活（画面不动、也不炸）',
            $column?->childAt(1)?->childCount() === 1 && $column?->childAt(2)?->childCount() === 0
        );

        $this->flushChecks();
    }

    /**
     * ⑦ 空 `TabView` 不炸 + 标签与页面**恒等**（结构上不可能只写一半）。
     */
    public function testEmptyTabViewAndPairInvariant(): void
    {
        $empty = TabView::make();
        check('TabView · 空表 ⇒ `tabCount()` 为 0', $empty->tabCount() === 0);
        check('TabView · 空表 ⇒ `selectedIndex()` 是 -1（不炸）', $empty->selectedIndex() === -1);

        $root = $this->mountView($empty);
        $flex = $root->childAt(0)?->renderObject();
        $count = $flex === null ? -1 : \count($flex->children());
        check('TabView · 空表 ⇒ 只剩页签条（空轨，不崩）', $count === 1, '盒数=' . (string) $count);

        $view = $this->view(0);
        check(
            'TabView · 标签数与页面数恒等（`->tab()` 是唯一写入口 ⇒ 不可能只写一半）',
            $view->tabCount() === \count($view->labels()) && $view->tabCount() === \count($view->pages()),
            \sprintf('tabs=%d labels=%d pages=%d', $view->tabCount(), \count($view->labels()), \count($view->pages()))
        );

        $this->flushChecks();
    }

    // ── 夹具 ────────────────────────────────────────────────────────────

    private function view(int $selected, ?\Closure $onChanged = null): TabView
    {
        // ★ 两页**故意用不同类型**：`CounterWidget`（有 State ⇒ 保活可验）与
        //   `Text`（无 State ⇒ "树里现在是第几页"可数）。两页同一种控件的话，
        //   "切过去显示的其实还是第一页"这个错在画面上完全看不出来。
        $view = TabView::make()
            ->tab('第一页', new CounterWidget())
            ->tab('第二页', Text::make('第二页'))
            ->selected($selected);

        if ($onChanged !== null) {
            $view->onChanged($onChanged);
        }

        return $view;
    }

    private function mountView(TabView $view): Element
    {
        $element = $view->createElement();
        $element->mount(null, smokeContext());

        return $element;
    }

    private function runFrame(Element $root): void
    {
        $surface = new SurfaceProbe();
        $surface->resize(320, 240, 1.0);
        (new Frame($surface))->run($root, BoxConstraints::tight(new Size(320.0, 240.0)), 64.0);
    }

    /** 页的 State：`Offstage` → `Expanded` → 页面（`CounterWidget`） */
    private function pageState(?Element $offstage): ?CounterState
    {
        $page = $offstage?->childAt(0)?->childAt(0);
        if (!$page instanceof StatefulElement) {
            return null;
        }

        $state = $page->state();

        return $state instanceof CounterState ? $state : null;
    }

    /**
     * 数树里某类控件的实例数。
     *
     * ★ 隐藏页**数不到**：`OffstageElement` 把它停在 `$parked`、不在 `children` 里，
     *   而 `visitChildren()` 只走 `children` ⇒ "在不在树里"这件事可以被数出来。
     */
    private function countWidgets(Element $root, string $class): int
    {
        $n = 0;
        $walk = function (Element $el) use (&$walk, &$n, $class): void {
            if ($el->widget() instanceof $class) {
                ++$n;
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $n;
    }

    /** @return list<Segment> */
    private function collectSegments(Element $root): array
    {
        $walk = function (Element $el) use (&$walk, &$found): void {
            $widget = $el->widget();
            if ($widget instanceof Segment) {
                $found[] = $widget;
            }
            $el->visitChildren($walk);
        };
        $walk($root);

        return $found;
    }
}
