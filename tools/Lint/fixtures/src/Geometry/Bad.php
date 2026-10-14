<?php
declare(strict_types=1);

namespace Drift\Geometry;

use Drift\Widget\Widget;

/** 夹具：Geometry → Widget 违反 §4.3（Geometry 只许依赖 Errors）—— 该响 */
final class Bad
{
    public function accept(Widget $widget): void
    {
    }
}
