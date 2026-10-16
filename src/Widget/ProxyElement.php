<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderProxyBox;

/**
 * 单子控件的 Element：`Padding` / `Center` / `Expanded` 共用。
 *
 * ★ 三个控件的子层都恰好一个 ⇒ 同一份 diff。开第四种单子控件时不用再写 Element。
 *
 * @api
 */
final class ProxyElement extends StatelessElement
{
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
        if ($widget instanceof PopupPanel) {
            return [$widget->child()];
        }

        return [];
    }

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
