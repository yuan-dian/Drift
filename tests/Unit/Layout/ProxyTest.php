<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderProxy;
use Drift\Rendering\Modifier;

/**
 * RenderProxy + mods 内距（W5：padding 是**字段**，不是节点）。
 *
 * 守的是"内距走 mods"这条生产路径的几何面：内禀值 = 内边距 + 孩子、
 * 盒尺寸加内距、子偏移住内容盒坐标（absoluteOrigin 补回内容原点）、
 * hitTest 减内容原点。
 *
 * ★ 纯布局，不依赖帧驱动 ⇒ Unit 层。
 */
final class ProxyTest extends SmokeTestCase
{
    public function testModsPaddingIntoIntrinsicAndSize(): void
    {
        $inner = new SolidBox(new Size(10.0, 6.0), 10.0, 6.0);
        $padBox = new RenderProxy();
        $padBox->setMods((new Modifier())->withPadding(4.0, 2.0, 4.0, 2.0));
        $padBox->setChild($inner);
        $padBox->layout(BoxConstraints::loose(new Size(60.0, 60.0)));
        check(
            'Layout · RenderProxy + mods 内距：内禀值 = 内边距 + 孩子（不是孩子本身）',
            $padBox->intrinsicWidth(0.0) === 18.0 && $padBox->intrinsicHeight(0.0) === 10.0,
            sprintf('实际 %.2f × %.2f', $padBox->intrinsicWidth(0.0), $padBox->intrinsicHeight(0.0))
        );
        expectBox($padBox, 0.0, 0.0, 18.0, 10.0, 'Layout · RenderProxy + mods 内距：盒尺寸也加了内距（10+8 × 6+4）');
        check(
            'Layout · 子盒偏移住**内容盒**坐标（padding 不进 offset）',
            $inner->offsetX() === 0.0 && $inner->offsetY() === 0.0,
            sprintf('实际 %.2f, %.2f', $inner->offsetX(), $inner->offsetY())
        );
        expectBox($inner, 4.0, 2.0, 10.0, 6.0, 'Layout · absoluteOrigin 补回内容原点（0 + 4, 0 + 2），尺寸不变');
        check(
            'Layout · hitTest 减内容原点：padding 区不命中孩子、内容区命中',
            $padBox->hitTest(1.0, 1.0) === $padBox && $padBox->hitTest(5.0, 3.0) === $inner,
            sprintf('(1,1) ⇒ %s，(5,3) ⇒ %s', get_class($padBox->hitTest(1.0, 1.0) ?? $padBox), get_class($padBox->hitTest(5.0, 3.0) ?? $padBox))
        );

        $this->flushChecks();
    }
}
