<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：根是**组合节点**（无盒）的应用。 */
final class CompositeRootApp extends Application
{
    public function buildRoot(): Widget
    {
        return new CompositePage();
    }

    public function title(): string
    {
        return '组合根应用';
    }
}
