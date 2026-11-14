<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 系统色槽位（`drift_win_system_color()` 的入参）。
 *
 * ★ 值是 Drift 自己的槽号，不是 Win32 的 `COLOR_*` 宏值：C++ 侧 switch 把槽号
 *   映射到 `GetSysColor()` 的索引，换平台时 PHP 侧不用改数字。
 *
 * @api platform
 */
final class SystemColor
{
    public const int WINDOW = 0;
    public const int WINDOW_TEXT = 1;
    public const int BUTTON_FACE = 2;
    public const int HOTLIGHT = 3;
}
