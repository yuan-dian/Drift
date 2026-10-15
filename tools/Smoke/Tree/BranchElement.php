<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Tree;

use Drift\Widget\Element;
use Drift\Widget\StatelessElement;

/**
 * @internal 冒烟夹具：结构节点的 Element —— 覆写 `expand()` 给出 children。
 *
 * 冒的是"同一个扩展点既能给 build() 的单子，也能给容器的多子"。
 */
final class BranchElement extends StatelessElement
{
    protected function expand(): array
    {
        /** @var BranchWidget $widget */
        $widget = $this->widget;

        return $widget->kidList();
    }
}
