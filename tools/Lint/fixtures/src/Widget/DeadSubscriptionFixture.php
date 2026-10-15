<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\State\Cell;
use Drift\State\SubscribingWidget;

/**
 * 夹具：`dead-subscription` 的已知错误（§16.6.3 #4 / A16）。
 *
 * - `ReadsCellUnmarked`：`build()` 里读自己的 `Cell`，但没实现 `SubscribingWidget`
 *   ⇒ 死订阅（error）
 * - `ReadsCellMarked`：同一件事，但标了 `SubscribingWidget` ⇒ **不该响**
 */
final class ReadsCellUnmarked extends StatelessWidget
{
    private Cell $hovered;

    public function build(): Widget
    {
        return Text::make((string) $this->hovered->value());
    }
}

final class ReadsCellMarked extends StatelessWidget implements SubscribingWidget
{
    private Cell $hovered;

    public function build(): Widget
    {
        return Text::make((string) $this->hovered->value());
    }
}
