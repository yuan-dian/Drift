<?php
declare(strict_types=1);

namespace Drift\Core;

use Drift\Widget\Widget;

/** 夹具：**该响**。Core 只认 Logging，不得反向拽 Widget。 */
final class UsesWidget
{
    public function accept(Widget $widget): void
    {
    }
}
