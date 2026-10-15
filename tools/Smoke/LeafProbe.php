<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke;

use Drift\Widget\Widget;

/** @internal 冒烟夹具：叶子控件，带一个宽字段用来验证 `build()` 拿到了环境。 */
final class LeafProbe extends Widget
{
    public function __construct(public readonly float $width)
    {
        parent::__construct();
    }
}
