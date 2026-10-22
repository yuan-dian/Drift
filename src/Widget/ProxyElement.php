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
    #[\Override]
    protected function expand(): array
    {
        $widget = $this->widget;

        if ($widget instanceof Padding) {
            return [$widget->child()];
        }
        if ($widget instanceof Center) {
            return $widget->kid() === null ? [] : [$widget->kid()];
        }
        if ($widget instanceof Expanded) {
            return [$widget->child()];
        }
        if ($widget instanceof ScrollViewBox) {
            return [$widget->child()];
        }
        if ($widget instanceof PopupPanel) {
            return [$widget->child()];
        }
        if ($widget instanceof ModalHost) {
            return [$widget->child()];
        }
        if ($widget instanceof SizedBox) {
            return $widget->kid() === null ? [] : [$widget->kid()];
        }

        return [];
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
