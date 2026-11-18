<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Platform\Screen;
use Drift\Theme\SystemAppearance;
use Drift\Theme\SystemTheme;

/**
 * X11 屏幕端口。缩放在这一版固定 1.0（无 per-monitor DPI 桥），视口按客户区现读。
 *
 * ★ 主题**显式降级**为 Light：X11 没有统一的系统主题 API，正确形态是
 *   Portal Settings → gsettings → kreadconfig → xfconf-query 的级联探测
 *   （见 UniDesktop/SDK 的 appearance_specs）。没接之前返回 Light 是"未实现"，
 *   不是"系统就是浅色"—— 这条纪律写在这里，防止后来者把它当完整支持。
 *
 * @internal 平台实现；应用只认 Drift\Platform\Screen
 */
final class X11Screen implements Screen
{
    public function __construct(
        private readonly X11WindowHost $windows,
        private readonly int $handle,
    ) {
    }

    public function scale(): float
    {
        return 1.0;
    }

    public function viewport(): BoxConstraints
    {
        if ($this->handle <= 0) {
            return BoxConstraints::tight(Size::zero());
        }

        $size = $this->windows->clientSize($this->handle);

        return BoxConstraints::tight(Size::of(
            max(0.0, $size->width),
            max(0.0, $size->height),
        ));
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
