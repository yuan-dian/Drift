<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderFlex;

/**
 * `AppBar` 的 Element：标题 + children 是一行子层（§13.2）。
 *
 * ★ 标题 Text 每次 expand 现造 —— 与 `StatelessWidget::build()` 同一条纪律
 *   （重建即换新描述，diff 按位置复用 Element），不引入缓存字段。
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

        $kids = [];
        if ($bar->titleText() !== '') {
            $kids[] = Text::make($bar->titleText())->title();
        }
        foreach ($bar->kidList() as $kid) {
            $kids[] = $kid;
        }

        return $kids;
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
