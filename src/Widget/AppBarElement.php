<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderFlex;

/**
 * `AppBar` 的 Element：标题 + 导航 + 动作是一行子层（§13.2）。
 *
 * ★ 子序列由 `AppBar::barKids()` 一次算清（含"有动作才插占位"的分枝）——
 *   Element 只负责挂盒，不再自己拼标题 / 判空。
 *
 * @internal
 */
final class AppBarElement extends StatelessElement
{
    #[\Override]
    protected function expand(): array
    {
        $bar = $this->widget;
        if (!$bar instanceof AppBar) {
            return [];
        }

        return $bar->barKids();
    }

    #[\Override]
    protected function adoptBoxes(): void
    {
        $box = $this->renderObject();
        if (!$box instanceof RenderFlex) {
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
