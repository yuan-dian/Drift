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
 * ★ 主题走 XSettings（暗色 / 高对比度两轴）+ 环境变量兜底，变化由
 *   X11Platform 的 `theme` 事件触发失效；详见 {@see X11Appearance}。
 *   没有系统色 API 时不伪造颜色，HC 时由 ThemeData 的 forced-colors 兜底配色接住。
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
        return X11Appearance::current()->theme;
    }

    public function appearance(): SystemAppearance
    {
        return X11Appearance::current();
    }
}
