<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Geometry\Scale;

use function drift_win_dpi;

/**
 * 窗口 → DPR（设备像素比）。
 *
 * ★★ **全仓唯一入口**。改动前这段换算在三个文件里手写了六遍
 *   （`Win32Platform::dprOf()` / `Win32WindowHost` 四处 / `Win32Screen::scale()`），
 *   而六份的兜底**不一致**：五份写了 `<= 0 ⇒ 1.0`，`Win32Screen::scale()` 没写
 *   ⇒ 桥返回 0 时它把 `0.0` 交给消费者除（`clientSize()` 之外的地方没有第二道防线）。
 *
 * ★ **`0` 真的会发生**：C 侧 `dpiOfWindow()` 问不到 `GetDpiForWindow`、也拿不到 DC
 *   时，退到"这个窗口上一次算出的值"（`slotOf(hwnd).dpi`），而那个槽位在窗口刚建、
 *   还没算过时就是 0。桥不做善意修正（它只报事实），修正落在这里。
 *
 * ★ **不缓存**：跨屏拖动时 DPI 会变，缓存就得配失效逻辑，而 `drift_win_dpi()` 只是
 *   一次 `GetDpiForWindow` —— 便宜的调用不该换缓存一致性风险（与 `Win32Screen` 同一条）。
 *
 * @internal 平台实现；业务代码用 `Screen::scale()`
 */
final class Dpr
{
    /** 某个窗口的 DPR（96 dpi ⇒ `1.0`）。句柄无效 / 读不到 ⇒ `1.0` */
    public static function of(int $windowHandle): float
    {
        if ($windowHandle <= 0) {
            return 1.0;
        }

        return self::fromDpi((float) drift_win_dpi($windowHandle));
    }

    /**
     * DPI → DPR，带兜底。
     *
     * ★ 拆成独立方法只为一件事：**可测**。`drift_win_dpi()` 是 C 桥函数，测试里注入不进去，
     *   而"桥给 0 时不许除零"恰恰是这里最容易写漏、后果最难查（NaN 传遍整棵布局且不报错）
     *   的一条 —— 它必须有一条能红的断言。
     *
     * @internal
     */
    public static function fromDpi(float $dpi): float
    {
        $dpr = Scale::fromDpi($dpi)->dpr;

        return $dpr > 0.0 ? $dpr : 1.0;
    }
}
