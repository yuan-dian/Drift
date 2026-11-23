<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Geometry\Scale;

use function drift_x11_dpi;

/**
 * 窗口 → DPR。X11 版 Dpr，与 Win32 同构。
 *
 * ★ 全仓唯一换算入口：clientSize / position / setMinSize / scale / imeCaret /
 *   resize 事件都走这里，不许各写 `/ 1.0` 字面量（Win32 曾因此六处兜底不一致）。
 * ★ X11 的 DPI 是**每屏一值**（Xft.dpi / XSETTINGS），不是 per-monitor；
 *   句柄参数只为接口对齐 Win32，桥忽略它取全局值。跨屏拖动在 X11 上不换 DPR。
 * ★ 不缓存：XSETTINGS 的 dpi 可被用户中途改，缓存就得配失效逻辑。
 *
 * @internal 平台实现；业务代码用 Screen::scale()
 */
final class X11Dpr
{
    /** 读不到桥（纯 PHP 单测）/ 无效句柄 ⇒ 1.0 */
    public static function of(int $windowHandle): float
    {
        if ($windowHandle <= 0 || !\function_exists('drift_x11_dpi')) {
            return 1.0;
        }

        return self::fromDpi((float) drift_x11_dpi($windowHandle));
    }

    /** DPI → DPR，带兜底；独立出来只为可测（桥函数注入不进去）。 */
    public static function fromDpi(float $dpi): float
    {
        $dpr = Scale::fromDpi($dpi)->dpr;

        return $dpr > 0.0 ? $dpr : 1.0;
    }
}
