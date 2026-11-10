<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Net\Rogue;

/** 夹具：**该响**。引用未登记段 —— 验 to 侧同样 fail-closed，不只是 from 侧。 */
final class UsesRogue
{
    public function accept(Rogue $rogue): void
    {
    }
}
