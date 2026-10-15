<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 竖向弹性容器（§13.1）。
 *
 * ```php
 * Column::make()->spacing(16)->paddingAll(24)->children([...]);
 * ```
 */
final class Column extends Flex
{
    public static function make(): self
    {
        return new self();
    }

    protected function isVertical(): bool
    {
        return true;
    }
}
