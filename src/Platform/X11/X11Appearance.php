<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Theme\SystemAppearance;
use Drift\Theme\SystemTheme;

use function drift_x11_system_appearance;

/**
 * X11 系统外观快照的一次进程内缓存。
 *
 * ★ 来源不在 PHP 侧拼命令：XSettings 是 X11 桌面的跨 toolkit 单一真相源，
 *   桥解析 `_XSETTINGS_S0` 的 owner 属性；没有 settings daemon 的裸 WM / Xvfb
 *   再用 GTK_THEME / GTK_COLOR_SCHEME 等环境变量兜底。PHP 只做类型归一化。
 *
 * ★ 缓存是为了不让 `appearance()` 每帧跨进程读 X；失效由 X11Platform 收到
 *   `theme` 事件时调用 {@see invalidate()}，不是定时器重试。纯 PHP 单测里桥
 *   函数不存在，返回 Light，与旧口径一致（未实现 ≠ 系统就是浅色）。
 *
 * @internal 平台实现；应用只认 SystemAppearance
 */
final class X11Appearance
{
    private static ?SystemAppearance $snapshot = null;

    public static function current(): SystemAppearance
    {
        if (self::$snapshot === null) {
            /** @var array{dark?: mixed, high_contrast?: mixed} $raw */
            $raw = \function_exists('drift_x11_system_appearance')
                ? (array) drift_x11_system_appearance()
                : [];
            self::$snapshot = self::fromBridge($raw);
        }

        return self::$snapshot;
    }

    public static function invalidate(): void
    {
        self::$snapshot = null;
    }

    /**
     * 桥数组 → 框架类型。纯函数，允许单测直接喂变异体。
     *
     * @param array{dark?: mixed, high_contrast?: mixed} $raw
     */
    public static function fromBridge(array $raw): SystemAppearance
    {
        $dark = (bool) ($raw['dark'] ?? false);
        $highContrast = (bool) ($raw['high_contrast'] ?? false);

        // X11 没有统一系统色 API，不编造用户没选过的颜色；HC 时由 ThemeData
        // 的 forced-colors 兜底配色接住，与 CSS forced-colors 同语义。
        return new SystemAppearance(
            $dark ? SystemTheme::Dark : SystemTheme::Light,
            $highContrast,
        );
    }
}
