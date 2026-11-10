<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Core\Pipeline;

/** 夹具：**不该响**。Widget 已显式放行 Core —— 撤掉那条放行就该报红。 */
final class UsesCore
{
    public function epoch(): int
    {
        return Pipeline::current()->boxEpoch();
    }
}
