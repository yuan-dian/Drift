<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\Widget\BuildContext;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 顶栏副标题：从 ThemeScope 下发的 context 里读应用侧扩展。
 *
 * ★ 扩展缝的真实消费点：`ShowcaseBrand` 挂在 ShowcaseRoot 的 ThemeScope 上，
 *   本控件在子树里经 `of($ctx)` 读回，证明挂进去的值真能透传到 build()。
 */
final class BrandCaption extends StatelessWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function build(BuildContext $ctx): Widget
    {
        $theme = $ctx->theme();
        $brand = ShowcaseBrand::of($ctx);
        $mode = ($theme->isDark() ? '深色' : '浅色')
            . ($theme->isHighContrast() ? ' · 高对比' : '');

        return Text::make('LayUI 式布局 · ' . $mode . ' · ' . $brand->name);
    }
}
