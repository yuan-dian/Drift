<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderProxyBox;

/**
 * 单子控件的 Element：`Padding` / `Center` / `Expanded` / `SizedBox` / `Spacer` 等共用。
 *
 * ★ 这些控件的子层都恰好一个 ⇒ 同一份 diff。开新的单子控件时不用再写 Element。
 * ★ `Card` 也走这里：它继承 `NullableChildRenderObjectWidget`，可空子槽复用
 *   同一份 diff，不需要单独的 Element。
 *
 * @api
 */
final class ProxyElement extends StatelessElement
{
    /**
     * ★ 一行转发：子层由**控件自己**声明（`Widget::expandChildren()`）。
     *   新增单子控件不需要改本文件；回到按类分派时漏一个分支就是"子不挂载、
     *   静默空白"。
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
