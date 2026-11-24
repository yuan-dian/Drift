<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Overlay;

use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\App\MenuApplication;
use Drift\Dev\Smoke\ErrorBoundary\BoomPage;
use Drift\Dev\Smoke\ErrorBoundary\BoomState;
use Drift\Dev\Smoke\ErrorBoundary\LayoutBoomBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderErrorBoundary;
use Drift\Layout\RenderPopupPanel;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\ErrorBoundaryElement;
use Drift\Widget\ErrorPlaceholder;
use Drift\Widget\PopupPanel;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Throwable;

/**
 * A46：浮层是独立根，页面边界够不着 —— 默认边界接在唯一入栈漏斗。
 *
 * ★ 三条判据各管一段，不许互相替代：
 *   ① 根身份：一个浮层仍只有 `$e->element` 一个根，面板在子树里（双根会让
 *      settle / paint / 命中各选一个根，静默分叉）。
 *   ② build 异常：坏面板就地换占位，兄弟浮层与主树照常，ESC 仍响应。
 *   ③ 两处透明代理：`panelRect()` 给落位算法，`markNeedsBuild()` 给跨树签名同步。
 *      这两处漏了都不报错 —— 前者面板位置偏一个 elevation，后者浮层活着但内容哑了。
 */
final class OverlayErrorBoundaryTest extends SmokeTestCase
{
    private const SIZE = [320.0, 240.0];

    /** @return array{0: MenuApplication, 1: BoxConstraints} */
    private function boot(): array
    {
        [$w, $h] = self::SIZE;
        $surface = new SvgSurface();
        $surface->resize((int) $w, (int) $h, 1.0);
        $tight = BoxConstraints::tight(new Size($w, $h));
        $app = new MenuApplication();
        $app->attach($surface, $tight);

        return [$app, $tight];
    }

    public function testOverlayRootIsErrorBoundary(): void
    {
        [$app, $tight] = $this->boot();
        $app->showMenu(PopupPanel::make(Text::make('健康面板')));
        $app->frame(16.0, $tight);

        $entry = $app->overlays()->top();
        $root = $entry?->element;
        check(
            '浮层边界 · 入栈漏斗把面板包成根（唯一 root Element，不是双根）',
            $root instanceof ErrorBoundaryElement,
        );
        check(
            '浮层边界 · 面板在边界的孩子层（entry 仍只认一个根身份）',
            $root !== null && $root->childAt(0)?->widget() instanceof PopupPanel,
        );

        $this->flushChecks();
    }

    public function testOverlayBuildFailureIsIsolatedToThatLayer(): void
    {
        [$app, $tight] = $this->boot();
        $app->showMenu(PopupPanel::make(Text::make('健康面板')));
        $app->showMenu(BoomPage::make());
        $app->frame(16.0, $tight);

        [$good, $bad] = [$app->overlays()->at(0), $app->overlays()->at(1)];
        check('浮层边界 · 前置：两层浮层都在栈上（健康层在下、坏层在上）', $good !== null && $bad !== null);

        $badRoot = $bad?->element;
        $badChild = $badRoot?->childAt(0);
        $badState = $badChild instanceof StatefulElement ? $badChild->state() : null;
        check(
            '浮层边界 · 前置：坏面板 State 在册（能触发第二次 build）',
            $badRoot instanceof ErrorBoundaryElement
                && $badChild?->widget() instanceof BoomPage
                && $badState instanceof BoomState,
        );

        if ($badState instanceof BoomState) {
            $badState->boom();   // 标脏 ⇒ 下一次 settle 的 build 抛
        }
        $threw = null;
        try {
            $app->frame(17.0, $tight);
        } catch (Throwable $e) {
            $threw = $e->getMessage();
        }

        check(
            '浮层边界 · build 异常没冒到帧循环（坏层就地降级，不是整窗 Fatal）',
            $threw === null,
            $threw ?? '',
        );
        check(
            '浮层边界 · 异常被本层捕获且留原文（G2：兜底不等于静默）',
            $badRoot instanceof ErrorBoundaryElement
                && $badRoot->hasCaught()
                && str_contains((string) $badRoot->caughtMessage(), 'build boom'),
        );
        check(
            '浮层边界 · 坏层换成可见占位（不是留一块空白）',
            $badRoot?->childAt(0)?->widget() instanceof ErrorPlaceholder,
        );
        check(
            '浮层边界 · 兄弟浮层仍健康（隔离粒度是层，不是整栈）',
            $good?->element instanceof ErrorBoundaryElement
                && !$good->element->hasCaught()
                && $good->element->childAt(0)?->widget() instanceof PopupPanel,
        );
        check('浮层边界 · 主树还在（没被坏浮层带走）', $app->root() instanceof Element);

        // 坏层之后仍响应输入：ESC 只关栈顶，健康层不受牵连。
        $app->dispatchInput(KeyEvent::down(KeyChord::escape()));
        check(
            '浮层边界 · 坏层之后 ESC 仍响应（只关栈顶，兄弟层留下）',
            $app->overlays()->count() === 1 && $app->overlays()->top() === $good,
            'depth=' . $app->overlays()->count(),
        );

        $this->flushChecks();
    }

    public function testBoundaryBoxForwardsPanelRect(): void
    {
        $child = new RenderPopupPanel();
        $boundary = new RenderErrorBoundary();
        $boundary->setChild($child);
        $boundary->layout(BoxConstraints::capWidth(320.0));

        $fromChild = $child->panelRect();
        $fromRoot = $boundary->panelRect();
        check(
            '浮层边界 · 健康时根盒回答的面板矩形与孩子逐值相同（落位拿得到投影留白）',
            $fromChild !== null && $fromRoot !== null
                && $fromRoot->left === $fromChild->left
                && $fromRoot->top === $fromChild->top
                && $fromRoot->width === $fromChild->width
                && $fromRoot->height === $fromChild->height,
        );

        $broken = new RenderErrorBoundary();
        $broken->setChild(new LayoutBoomBox());
        $broken->layout(BoxConstraints::capWidth(320.0));
        check(
            '浮层边界 · 渲染期坏了 ⇒ 不再谎报面板矩形（回落整盒尺寸）',
            $broken->panelRect() === null,
        );

        $this->flushChecks();
    }

    public function testBoundaryElementForwardsMarkNeedsBuild(): void
    {
        [$app, $tight] = $this->boot();
        $app->showMenu(CountingPanel::make());
        $app->frame(16.0, $tight);

        $root = $app->overlays()->top()?->element;
        $panel = $root?->childAt(0);
        $state = $panel instanceof StatefulElement ? $panel->state() : null;
        $before = $state instanceof CountingPanelState ? $state->builds : -1;
        check(
            '浮层边界 · 前置：面板是有态节点且只 build 过一次',
            $root instanceof ErrorBoundaryElement && $state instanceof CountingPanelState && $before === 1,
            'builds=' . $before,
        );

        // 模拟 OverlayLayout::syncPanelSignatures() 对着根调的那一句。
        $root?->markNeedsBuild();
        $app->frame(17.0, $tight);
        $after = $state instanceof CountingPanelState ? $state->builds : -1;
        check(
            '浮层边界 · markNeedsBuild 转给孩子 State（下次 settle 真重建，不是哑浮层）',
            $after > $before,
            sprintf('before=%d after=%d', $before, $after),
        );

        $this->flushChecks();
    }
}

/** @internal 测试夹具：build 次数可观测的有态浮层面板 */
final class CountingPanel extends StatefulWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new CountingPanelState();
    }
}

/** @internal 见 `CountingPanel` */
final class CountingPanelState extends State
{
    public int $builds = 0;

    public function build(BuildContext $context): Widget
    {
        ++$this->builds;

        return Text::make('计数 ' . $this->builds);
    }
}
