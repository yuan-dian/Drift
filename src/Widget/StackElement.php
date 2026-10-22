<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderStack;

/**
 * `Stack` 的 Element：结构节点，子层直接来自 `children()`。
 *
 * ★ 形状与 `FlexElement` 逐条同构（`expand()` 返回多个子 + `adoptBoxes()` 把子盒
 *   喂给渲染盒）—— 容器与叶子共用同一套 diff（§13.2）靠的就是这两个扩展点。
 *
 * @api
 */
final class StackElement extends StatelessElement
{
    #[\Override]
    protected function expand(): array
    {
        /** @var Stack $widget */
        $widget = $this->widget;

        return $widget->kidList();
    }

    #[\Override]
    protected function adoptBoxes(): void
    {
        /** @var RenderStack $stack */
        $stack = $this->renderObject();
        if ($stack === null) {
            return;
        }

        $stack->clearChildren();
        foreach ($this->children as $child) {
            $box = $child->effectiveBox();
            if ($box !== null) {
                $stack->addChild($box);
            }
        }
    }
}
