<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\ChildContainer;

/**
 * 多子控件的 Element（§DEBT D2）—— `Flex` / `Stack` / `Wrap` / `AppBar` /
 * `SegmentedControl` 共用。
 *
 * 原来这五个控件各有一个 Element 类（`FlexElement` / `StackElement` / `WrapElement` /
 * `AppBarElement` / `SegmentedControlElement`），每个都只做两件事，且 `adoptBoxes()`
 * 的 13 行**逐字相同**（只差局部变量名）。五个文件合起来 200 多行，删掉零损失。
 *
 * `TableBox` **不在**这里：它的两个子有名字（header / body），挂法不是"按顺序 add"，
 * 继续用自己的 `TableElement`。抽象只覆盖同形的多数。
 *
 * @api
 */
final class MultiChildElement extends StatelessElement
{
    #[\Override]
    protected function expand(): array
    {
        $widget = $this->widget;

        // ★ 用 `instanceof` 而不是 `@var` 转型：本仓踩过"调一个静态类型上没有的方法"
        //   （build 成功 + smoke 全绿、真机崩）的坑，见 MEMORY「$this->不存在的方法()」。
        //   本类只会被 `MultiChildRenderObjectWidget` 造出来 ⇒ 这个分支永不落空。
        if (!$widget instanceof MultiChildRenderObjectWidget) {
            return [];
        }

        return $widget->kidList();
    }

    #[\Override]
    protected function adoptBoxes(): void
    {
        $box = $this->renderObject();
        if (!$box instanceof ChildContainer) {
            return;
        }

        $box->clearChildren();
        foreach ($this->children as $child) {
            $childBox = $child->effectiveBox();
            if ($childBox !== null) {
                $box->addChild($childBox);
            }
        }
    }
}
