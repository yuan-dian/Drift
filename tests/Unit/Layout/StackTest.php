<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Smoke\Layout\ConstraintSpy;
use Drift\Dev\Smoke\Layout\LayoutCountBox;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderStack;
use Drift\Geometry\Alignment;
use Drift\Layout\StackFit;

/**
 * RenderStack 布局引擎（与 `tools/smoke.php` ⑮d 段一致）。
 *
 * 四条口径一起守：尺寸 = 最大子 / Expand 取父给的尺寸（不是 max(子)）/
 * PassThrough 原样透传 / 内禀值 = max。少任何一条都会漏掉一类"看起来实现了"。
 *
 * ★ 纯布局，不依赖帧驱动 ⇒ Unit 层。
 */
final class StackTest extends SmokeTestCase
{
    public function testLooseSizeIsMaxChildAndTopLeft(): void
    {
        $stackA = new SolidBox(new Size(100.0, 20.0), 100.0, 20.0);
        $stackB = new SolidBox(new Size(30.0, 80.0), 30.0, 80.0);
        $looseStack = new RenderStack();
        $looseStack->addChild($stackA);
        $looseStack->addChild($stackB);
        $looseStack->layout(BoxConstraints::loose(new Size(300.0, 300.0)));
        expectBox($looseStack, 0.0, 0.0, 100.0, 80.0, 'Stack · Loose 尺寸 = 最大子（100×20 与 30×80 ⇒ 100×80）');
        check(
            'Stack · 默认 TopLeft ⇒ 两个子都在 (0,0)',
            $stackA->offsetX() === 0.0 && $stackA->offsetY() === 0.0
                && $stackB->offsetX() === 0.0 && $stackB->offsetY() === 0.0,
            sprintf('实际 A(%.1f,%.1f) B(%.1f,%.1f)', $stackA->offsetX(), $stackA->offsetY(), $stackB->offsetX(), $stackB->offsetY())
        );

        $this->flushChecks();
    }

    public function testHitTestFromBackToFront(): void
    {
        $below = new SolidBox(new Size(50.0, 50.0), 50.0, 50.0);
        $above = new SolidBox(new Size(50.0, 50.0), 50.0, 50.0);
        $hitStack = new RenderStack();
        $hitStack->addChild($below);
        $hitStack->addChild($above);
        $hitStack->layout(BoxConstraints::loose(new Size(100.0, 100.0)));
        check(
            'Stack · 命中从后往前（重叠处命中后加的那个）',
            $hitStack->hitTest(10.0, 10.0) === $above,
            '实际命中 ' . (($hitStack->hitTest(10.0, 10.0) ?? $below) === $above ? '后加的' : '先加的')
        );

        $this->flushChecks();
    }

    public function testExpandFitsParentSpaceAndPassesTight(): void
    {
        $expandSpy = new ConstraintSpy(new Size(30.0, 10.0));
        $expandStack = new RenderStack(Alignment::topLeft(), StackFit::Expand);
        $expandStack->addChild($expandSpy);
        $expandStack->layout(BoxConstraints::loose(new Size(100.0, 80.0)));
        expectBox($expandStack, 0.0, 0.0, 100.0, 80.0, 'Stack · Expand 有界轴拉满父给的空间（100×80，不是 max(子) 的 30×10）');
        check(
            'Stack · Expand 给子 tight（min === max === 本盒尺寸）',
            $expandSpy->lastSeen !== null
                && $expandSpy->lastSeen->minWidth === 100.0
                && $expandSpy->lastSeen->maxHeight === 80.0,
            $expandSpy->lastSeen === null
                ? '一次都没量'
                : sprintf('子约束 %.1f..%.1f × %.1f..%.1f', $expandSpy->lastSeen->minWidth, $expandSpy->lastSeen->maxWidth, $expandSpy->lastSeen->minHeight, $expandSpy->lastSeen->maxHeight)
        );

        $this->flushChecks();
    }

    public function testExpandInfiniteAxisDegradesToLoose(): void
    {
        $halfSpy = new ConstraintSpy(new Size(40.0, 10.0));
        $halfStack = new RenderStack(Alignment::topLeft(), StackFit::Expand);
        $halfStack->addChild($halfSpy);
        $halfStack->layout(new BoxConstraints(0.0, INF, 0.0, 40.0));
        expectBox($halfStack, 0.0, 0.0, 40.0, 40.0, 'Stack · Expand 的无界轴退化为 Loose（宽按子 40、高拉满 40）');

        $this->flushChecks();
    }

    public function testAlignmentsBottomRightAndCenter(): void
    {
        $brKid = new SolidBox(new Size(30.0, 10.0), 30.0, 10.0);
        $brStack = new RenderStack(Alignment::bottomRight(), StackFit::Loose);
        $brStack->addChild($brKid);
        $brStack->layout(BoxConstraints::tight(new Size(100.0, 80.0)));
        check(
            'Stack · BottomRight ⇒ 子落右下（100−30, 80−10 = 70, 70）',
            $brKid->offsetX() === 70.0 && $brKid->offsetY() === 70.0,
            sprintf('实际 %.1f, %.1f', $brKid->offsetX(), $brKid->offsetY())
        );

        $cKid = new SolidBox(new Size(30.0, 10.0), 30.0, 10.0);
        $cStack = new RenderStack(Alignment::center(), StackFit::Loose);
        $cStack->addChild($cKid);
        $cStack->layout(BoxConstraints::tight(new Size(100.0, 80.0)));
        check(
            'Stack · Center ⇒ 子居中（(100−30)/2, (80−10)/2 = 35, 35）',
            $cKid->offsetX() === 35.0 && $cKid->offsetY() === 35.0,
            sprintf('实际 %.1f, %.1f', $cKid->offsetX(), $cKid->offsetY())
        );

        $this->flushChecks();
    }

    public function testPassThroughTransmitsConstraints(): void
    {
        $ptSpy = new ConstraintSpy(new Size(20.0, 20.0));
        $ptStack = new RenderStack(Alignment::topLeft(), StackFit::PassThrough);
        $ptStack->addChild($ptSpy);
        $ptStack->layout(new BoxConstraints(10.0, 50.0, 10.0, 50.0));
        check(
            'Stack · PassThrough 原样透传（子看到 10..50 × 10..50）',
            $ptSpy->lastSeen !== null
                && $ptSpy->lastSeen->minWidth === 10.0
                && $ptSpy->lastSeen->maxWidth === 50.0,
            $ptSpy->lastSeen === null
                ? '一次都没量'
                : sprintf('子约束 %.1f..%.1f', $ptSpy->lastSeen->minWidth, $ptSpy->lastSeen->maxWidth)
        );

        $this->flushChecks();
    }

    public function testIntrinsicIsMaxNotSum(): void
    {
        $intrStack = new RenderStack();
        $intrStack->addChild(new SolidBox(new Size(10.0, 10.0), 70.0, 30.0));
        $intrStack->addChild(new SolidBox(new Size(10.0, 10.0), 20.0, 90.0));
        check(
            'Stack · 内禀值 = max（不是和）：宽 70、高 90',
            $intrStack->intrinsicWidth(0.0) === 70.0 && $intrStack->intrinsicHeight(0.0) === 90.0,
            sprintf('实际 %.1f / %.1f', $intrStack->intrinsicWidth(0.0), $intrStack->intrinsicHeight(0.0))
        );

        $this->flushChecks();
    }

    public function testEachChildLaidOutOnce(): void
    {
        $onceA = new LayoutCountBox(new Size(30.0, 10.0));
        $onceB = new LayoutCountBox(new Size(50.0, 20.0));
        $onceStack = new RenderStack(Alignment::center(), StackFit::Expand);
        $onceStack->addChild($onceA);
        $onceStack->addChild($onceB);
        $onceStack->layout(BoxConstraints::loose(new Size(100.0, 80.0)));
        check(
            'Stack · 每个子只 layout 一次（Expand 也没有第二遍）',
            $onceA->layoutCalls === 1 && $onceB->layoutCalls === 1,
            sprintf('实际 %d / %d 次', $onceA->layoutCalls, $onceB->layoutCalls)
        );

        $this->flushChecks();
    }
}
