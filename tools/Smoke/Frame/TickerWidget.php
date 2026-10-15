<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Frame;

use Drift\Widget\State;
use Drift\Widget\StatefulWidget;

/** @internal 冒烟夹具：`build()` 的产物**随 State 变** —— 端到端可观测。 */
final class TickerWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new TickerState();
    }
}
