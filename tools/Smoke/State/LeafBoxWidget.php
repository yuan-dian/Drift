<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：走 `createRenderObjectFromState()` 的"叶子态控件"。
 *
 * 存在的唯一目的：**数 `State::build()` 被调了几次** —— 叶子路径下一次都不该调。
 * 顺带证明"有态 + 有盒 + 无子"这条路真的通（有盒、且没有子节点）。
 *
 * ★ 计数器是**静态**的：`LeafBoxWidget::$buildCalls` 在每条断言前 `reset()`，
 *   跨 Element 实例也能读（Element 是不可变的，重建会换新对象）。
 */
final class LeafBoxWidget extends StatefulWidget
{
    /** @internal 测试读 */
    public static int $buildCalls = 0;

    public static function resetBuildCalls(): void
    {
        self::$buildCalls = 0;
    }

    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new LeafBoxState();
    }

    #[\Override]
    public function createRenderObjectFromState(BuildContext $context, State $state): ?RenderBox
    {
        return new SolidBox(new Size(12.0, 12.0), 12.0, 12.0);
    }
}
