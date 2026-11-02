<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderTable;

/**
 * `TableBox` 的 Element：结构节点的"多子"扩展点（子层在**声明里**，不在 `build()`）。
 *
 * ★ 与 `MultiChildElement` 同形 —— 两处都只做两件事：`expand()` 给子层、
 *   `adoptBoxes()` 把子盒挂进**本节点当前的盒**。diff / 生命周期一行都不重写。
 *   差别只有一处：本类的两个子**有名字**（`setHeader()` / `setBody()`），
 *   不是"按顺序 `addChild()`"。
 *
 * ★ 顺序不能反：`expand()` 先于 `adoptBoxes()`（基座保证），所以这里拿到的
 *   `childAt(0/1)` 就是本次重建后的新 Element —— 挂上的一定是新盒。
 *
 * @internal 应用只认 `Table`
 */
final class TableElement extends StatelessElement
{
    #[\Override]
    protected function expand(): array
    {
        return $this->widget->expandChildren();
    }

    #[\Override]
    protected function adoptBoxes(): void
    {
        /** @var RenderTable|null $box */
        $box = $this->renderObject();
        if ($box === null) {
            return;
        }

        $header = $this->childAt(0)?->effectiveBox();
        if ($header !== null) {
            $box->setHeader($header);
        }

        $body = $this->childAt(1)?->effectiveBox();
        if ($body !== null) {
            $box->setBody($body);
        }
    }
}
