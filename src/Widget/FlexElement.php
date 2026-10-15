<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderFlex;

/**
 * `Column` / `Row` 的 Element：结构节点，子层直接来自 `children()`。
 *
 * ★ `expand()` 返回**多个**子（不是 `build()` 的单子）—— §13.2 说"容器与叶子
 *   共用同一套 diff"，靠的就是 Element 基类这一个扩展点。
 *
 * @api
 */
final class FlexElement extends StatelessElement
{
    protected function expand(): array
    {
        /** @var Flex $widget */
        $widget = $this->widget;

        return $widget->kidList();
    }

    protected function adoptBoxes(): void
    {
        /** @var RenderFlex $flex */
        $flex = $this->renderObject();
        if ($flex === null) {
            return;
        }

        $flex->clearChildren();
        foreach ($this->children as $child) {
            $box = $child->effectiveBox();
            if ($box !== null) {
                $flex->addChild($box);
            }
        }
    }
}
