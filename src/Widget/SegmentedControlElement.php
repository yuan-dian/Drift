<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderSegmentedControl;

/**
 * `SegmentedControl` 的 Element：结构节点，子层来自 `kidList()`（段）。
 *
 * ★ 形状与 `StackElement` 逐条同构（`expand()` 返回多个子 + `adoptBoxes()` 把
 *   子盒喂给渲染盒）—— 容器与叶子共用同一套 diff（§13.2）靠的就是这两个扩展点。
 *
 * @api
 */
final class SegmentedControlElement extends StatelessElement
{
    #[\Override]
    protected function expand(): array
    {
        /** @var SegmentedControl $widget */
        $widget = $this->widget;

        return $widget->kidList();
    }

    #[\Override]
    protected function adoptBoxes(): void
    {
        /** @var RenderSegmentedControl $box */
        $box = $this->renderObject();
        if ($box === null) {
            return;
        }

        $box->clearChildren();
        foreach ($this->children as $child) {
            $kidBox = $child->effectiveBox();
            if ($kidBox !== null) {
                $box->addChild($kidBox);
            }
        }
    }
}
