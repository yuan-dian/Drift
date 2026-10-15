<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Tree;

use Drift\Widget\State;
use Drift\Widget\StatefulWidget;

/** @internal 冒烟夹具：有 State 的控件。 */
final class CounterWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new CounterState();
    }
}
