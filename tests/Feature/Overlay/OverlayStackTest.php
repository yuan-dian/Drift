<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Overlay;

use Drift\App\OverlayEntry;
use Drift\Dev\Smoke\App\OverlayLabApplication;
use Drift\Dev\Smoke\Overlay\FlatBox;
use Drift\Dev\Smoke\Frame\RecordingSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderPopupPanel;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\PopupPanel;

/**
 * 浮层**栈**：多层同屏、栈序即绘制序、锚点消失自愈、翻转、嵌套基准、焦点回锚、焦点陷阱。
 *
 * ★ 每条都要能区分"栈版"与"槽位版"：单槽只能放一个 ⇒ 三层同屏、逐层路由、
 *   锚点消失自动关这三类判据在旧实现下**根本写不出来**。
 * ★ ② 用**像素**回答而不是 SVG 字符串：两个同色 rect 换个顺序，SVG 里看不出来。
 *   采样点必须在第一层面板内、第二层面板外 —— 否则测的是"第二层盖住第一层"，
 *   与遮罩顺序无关（这坑踩过一次：两层面板都 ≈308 宽，只有**高度**不同
 *   ⇒ 沿 y 挑点，挑第一层面板的底部）。
 */
final class OverlayStackTest extends SmokeTestCase
{
    /** @return array{0: OverlayLabApplication, 1: RecordingSurface, 2: BoxConstraints} */
    private static function boot(): array
    {
        $surface = new RecordingSurface();
        $surface->resize(320, 240, 1.0);
        $app = new OverlayLabApplication();
        $tight = BoxConstraints::tight(new Size(320.0, 240.0));
        $app->attach($surface, $tight);

        return [$app, $surface, $tight];
    }

    private static function button(OverlayLabApplication $app, string $command): ?Element
    {
        return findElement(
            $app->root(),
            static fn(Element $e): bool => $e->widget() instanceof Button && $e->widget()->commandName() === $command,
        );
    }

    public function testThreeLayersCoexist(): void
    {
        [$app] = self::boot();
        $app->openPanel(FlatBox::make(80.0, 30.0));
        $app->openPanel(FlatBox::make(90.0, 30.0));
        $app->openModalBox(60.0, 24.0, '第三层');
        check(
            '浮层栈 · 三层同屏（count=3，栈顶是最新的模态）',
            $app->overlays()->count() === 3
                && $app->overlays()->top()?->scrim === true
                && $app->overlays()->at(0)?->scrim === false,
            'depth=' . $app->overlays()->count()
                . ' top-scrim=' . var_export($app->overlays()->top()?->scrim, true),
        );
    }

    public function testUpperScrimCoversLowerPanel(): void
    {
        [$app, $surface, $tight] = self::boot();
        $app->overlays()->pop();
        $app->overlays()->pop();
        $app->overlays()->pop();
        $app->frame(16.0, $tight);

        $app->openModalBox(200.0, 120.0, '第一层');
        $app->frame(32.0, $tight);
        $firstEl = $app->overlays()->top()?->element;
        $firstBox = $firstEl === null ? null : findElement(
            $firstEl,
            static fn(Element $e): bool => $e->widget() instanceof PopupPanel,
        )?->effectiveBox();
        $rect = $firstBox instanceof RenderPopupPanel ? $firstBox->panelRect() : null;
        [$fx, $fy] = $firstBox === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($firstBox);
        $probeX = (int) ($fx + ($rect?->left ?? 0.0) + 20.0);
        $probeY = (int) ($fy + ($rect?->top ?? 0.0) + ($rect?->height ?? 0.0) - 10.0);
        $one = $surface->raster()->pixelAt($probeX, $probeY);

        $app->openModalBox(60.0, 24.0, '第二层');
        $app->frame(48.0, $tight);
        $two = $surface->raster()->pixelAt($probeX, $probeY);
        check(
            '浮层栈 · 上层 scrim 盖在下层面板上（栈序 = 绘制序，不是"最后画一层遮罩"）',
            $two->r < $one->r && $one->r > 200,
            sprintf('单层 r=%d 两层 r=%d @(%d,%d)', $one->r, $two->r, $probeX, $probeY),
        );
    }

    public function testAnchorUnmountClosesNextFrame(): void
    {
        [$app, , $tight] = self::boot();
        $app->openPanel(FlatBox::make(80.0, 30.0), self::button($app, 'lab.anchor'));
        check('浮层栈 · 前置：锚点还在 ⇒ 浮层在栈里', $app->overlays()->count() === 1);

        $app->root()->unmount();
        $app->frame(16.0, $tight);
        check(
            '浮层栈 · 锚点卸载 ⇒ 下一帧自动关（不悬在左上角冒充别人的框）',
            $app->overlays()->count() === 0,
            'depth=' . $app->overlays()->count(),
        );
    }

    public function testFlipsAboveWhenAnchorHugsBottomEdge(): void
    {
        [$app, , $tight] = self::boot();
        $bg = self::button($app, 'lab.bottom');
        $bgBox = $bg?->effectiveBox();
        [, $bgY] = $bgBox === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($bgBox);

        $app->openPanel(FlatBox::make(80.0, 60.0), $bg, OverlayEntry::BELOW);
        $app->frame(16.0, $tight);
        $top = $app->overlays()->top();
        check(
            '浮层栈 · 锚点贴底边 ⇒ BELOW 翻到上方（只翻一次，不做通用避让）',
            $top !== null && $top->y + 60.0 <= $bgY + 1.0 && $top->y >= 0.0,
            $top === null ? 'no entry' : sprintf('面板底 y=%.6g 锚点 y=%.6g', $top->y + 60.0, $bgY),
        );
    }

    public function testNestedOverlayUsesParentBase(): void
    {
        [$app, , $tight] = self::boot();
        $bg = self::button($app, 'lab.bottom');

        $app->openPanel(FlatBox::make(120.0, 90.0), $bg, OverlayEntry::ABOVE);
        $app->frame(32.0, $tight);
        $parent = $app->overlays()->top();
        $innerEl = $parent?->element;
        $innerBox = $innerEl?->effectiveBox();
        [$innerX, $innerY] = $innerBox === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($innerBox);

        $app->openPanel(FlatBox::make(40.0, 20.0), $innerEl, OverlayEntry::BELOW);
        $app->frame(48.0, $tight);
        $child = $app->overlays()->top();
        check(
            '浮层栈 · 嵌套浮层的锚点坐标系（父层 base + 相对偏移，不是客户区绝对坐标）',
            $parent !== null && $child !== null
                && ($parent->x !== 0.0 || $parent->y !== 0.0)
                && abs($child->x - $innerX) < 1.0
                && abs($child->y - ($innerY + ($innerBox?->size()?->height ?? 0.0))) < 1.0,
            $parent === null || $child === null ? 'no entry' : sprintf(
                '父=(%.6g,%.6g) 内层绝对=(%.6g,%.6g) 子=(%.6g,%.6g)',
                $parent->x,
                $parent->y,
                $innerX,
                $innerY,
                $child->x,
                $child->y,
            ),
        );
    }

    /** barrier=true ⇒ 点浮层外不穿透到主树按钮；false ⇒ 穿透（Tooltip 不吃点击） */
    public function testBarrierDecidesWhetherClickPassesThrough(): void
    {
        [$blocked, , $t1] = self::boot();
        $blocked->openPanel(FlatBox::make(40.0, 20.0), self::button($blocked, 'lab.anchor'));
        $blocked->frame(16.0, $t1);
        [$bx, $by] = self::bottomCenter($blocked);
        $blocked->seen = [];
        $blocked->dispatchInput(PointerEvent::down($bx, $by));
        check(
            '浮层栈 · barrier=true 点浮层外 ⇒ 主树按钮未被激活（吞掉，不穿透）',
            $blocked->seen === [],
            'seen=' . implode(',', $blocked->seen),
        );

        [$passThrough, , $t2] = self::boot();
        $passThrough->openPanel(
            FlatBox::make(40.0, 20.0),
            self::button($passThrough, 'lab.anchor'),
            OverlayEntry::BELOW,
            false,
        );
        $passThrough->frame(16.0, $t2);
        [$px, $py] = self::bottomCenter($passThrough);
        $passThrough->seen = [];
        $passThrough->dispatchInput(PointerEvent::down($px, $py));
        check(
            '浮层栈 · barrier=false 点浮层外 ⇒ 穿透到主树按钮（Tooltip 不吃点击）',
            $passThrough->seen === ['lab.bottom'],
            'seen=' . implode(',', $passThrough->seen),
        );
    }

    /** @return array{0: float, 1: float} 「底部」按钮的中心 */
    private static function bottomCenter(OverlayLabApplication $app): array
    {
        $box = self::button($app, 'lab.bottom')?->effectiveBox();
        [$x, $y] = $box === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($box);

        return [
            $x + ($box?->size()?->width ?? 0.0) / 2.0,
            $y + ($box?->size()?->height ?? 0.0) / 2.0,
        ];
    }

    public function testFocusReturnsToAnchorAfterClose(): void
    {
        [$app, , $tight] = self::boot();
        $anchor = self::button($app, 'lab.anchor');
        $app->focusForTest($anchor);
        $app->openModalBox(60.0, 24.0, '回锚');
        $app->frame(16.0, $tight);
        $app->closeModal();
        $app->frame(32.0, $tight);
        check(
            '浮层栈 · 关闭后焦点回到打开前的节点（restoreFocus 真被消费）',
            $app->focusedForTest() === $anchor,
            $app->focusedForTest() === null ? '焦点被清空' : '焦点在别处',
        );
    }

    public function testFocusTrapKeepsMainTreeOutOfTabOrder(): void
    {
        [$app, , $tight] = self::boot();
        $app->openModalBox(60.0, 24.0, '陷阱', true);
        $app->frame(16.0, $tight);

        $order = $app->focusOrderForTest();
        $inMainTree = 0;
        foreach ($order as $el) {
            if ($el->widget() instanceof Button
                && in_array($el->widget()->commandName(), ['lab.anchor', 'bg.hit'], true)) {
                $inMainTree++;
            }
        }
        check(
            '浮层栈 · 模态开着 ⇒ Tab 序只剩浮层（focusTrap：主树按钮进不来）',
            $order !== [] && $inMainTree === 0,
            '链长=' . count($order) . ' 主树按钮=' . $inMainTree,
        );
    }
}
