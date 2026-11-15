<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Platform\Screen;
use Drift\Theme\SystemAppearance;
use Drift\Theme\SystemTheme;

/** `Screen` 的最小第二实现：固定 DPI / 视口 / Light，只为证明接口可替换。 */
final class HeadlessScreen implements Screen
{
    public function scale(): float
    {
        return 1.0;
    }

    public function viewport(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(800.0, 600.0));
    }

    public function systemTheme(): SystemTheme
    {
        return SystemTheme::Light;
    }

    public function appearance(): SystemAppearance
    {
        return SystemAppearance::light();
    }
}
