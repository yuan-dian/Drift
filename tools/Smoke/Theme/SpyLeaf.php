<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Theme;

use Drift\Widget\Widget;

/** @internal 冒烟夹具：一个什么都不画的叶子。 */
final class SpyLeaf extends Widget
{
    public static function make(): self
    {
        return new self();
    }
}
