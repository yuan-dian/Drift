<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 横向弹性容器（§13.1）。
 *
 * ```php
 * Row::make()->spacing(8)->children([...]);
 * ```
 *
 * @api
 */
final class Row extends Flex
{
    public static function make(): self
    {
        return new self();
    }

    protected function isVertical(): bool
    {
        return false;
    }
}
