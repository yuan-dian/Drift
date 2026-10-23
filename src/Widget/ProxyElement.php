<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderProxyBox;

/**
 * 单子控件的 Element：`Padding` / `Center` / `Expanded` / `SizedBox` / `Spacer` 等共用。
 *
 * ★ 这些控件的子层都恰好一个 ⇒ 同一份 diff。开新的单子控件时不用再写 Element。
 * ★ `Card` **不在**这里（W5/M2-5 起）：它改成 `StatelessWidget`，走 `build()`
 *   组合，子层由 `StatelessElement` 自己的 `build()` 产出。
 *
 * @api
 */
final class ProxyElement extends StatelessElement
{
    /**
     * ★ 一行转发：子层由**控件自己**声明（`Widget::expandChildren()`）。
     *   原来这里是 7 个 `instanceof` 分支 —— 每新增一个单子控件都要回来改这一处，
     *   漏了就是"子不挂载、静默空白"（§24.2 #25）。开放-封闭之后不用再碰本文件。
     */
    #[\Override]
    protected function expand(): array
    {
        return $this->widget->expandChildren();
    }

    #[\Override]
    protected function adoptBoxes(): void
    {
        $box = $this->renderObject();
        if ($box === null) {
            return;
        }

        $childBox = $this->childAt(0)?->effectiveBox();
        if ($childBox !== null && $box instanceof RenderProxyBox) {
            $box->setChild($childBox);
        }
    }
}
