<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Theme;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：把 build() 时看到的主题是不是暗色记下来。 */
final class ThemeSpy extends StatefulWidget
{
    public function createState(): State
    {
        return new ThemeSpyState();
    }
}
