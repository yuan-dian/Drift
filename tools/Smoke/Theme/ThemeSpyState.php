<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Theme;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\Widget;

/** @internal 这些字段不被 build() 读 —— 断言因此不会自证。 */
final class ThemeSpyState extends State
{
    public bool $sawDark = false;
    public int $builds = 0;

    public function build(BuildContext $ctx): Widget
    {
        $this->builds++;
        $this->sawDark = $ctx->theme()->isDark();

        return new SpyLeaf();
    }
}
