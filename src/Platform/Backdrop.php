<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 窗口背后"透出来的是什么"（P1 §质感 / Stage 3）。
 *
 * 应用声明**意图**（"我要磨砂"），机制留给平台：Win10 走未公开的
 * `SetWindowCompositionAttribute`（accent 策略），Win11 有 `DWMWA_SYSTEMBACKDROP_TYPE`。
 * 与本框架的应用内毛玻璃（`BackdropFilter` / `Canvas::blurBackdrop`）**不是一回事**：
 * 那个模糊的是**应用自己的内容**，这个模糊的是**窗口背后的桌面/别的窗口**。
 *
 * ★★ **只有逐像素 alpha 才能看见它**：`setBackdrop()` 让 DWM 在窗口**背后**画一层
 *   模糊，而应用画的像素在它上面。不透明窗口的每个像素都由应用画满 ⇒ 模糊被完全
 *   盖住，设了也看不出来（不是没生效，是没有观察通道）。所以它的正常用法是
 *   搭配 `RenderSurface::setBackground()` 给一个带 alpha 的底色。
 *
 * ★★ **`value` 就是桥的 ABI 编号** —— `drift_win_set_backdrop(int $hwnd, int $kind)`
 *   收的就是它；`0` 保留给"关掉"（PHP 侧由 `null` 表达）。**改这里的 value 等于
 *   改 ABI**，必须与 `cpp-src/drift_win32.cc` 的 switch 同步。
 *
 * ★ **`null` 才是"不要背景"**（与 `CursorShape` 同一套约定：`null` = 不声明，
 *   枚举 case = 一个明确的决定）。所以这里**没有** `None` 这个 case ——
 *   `setBackdrop(null)` 就是关。
 *
 * @api
 */
enum Backdrop: int
{
    /** 轻模糊（Win10 `ACCENT_ENABLE_BLURBEHIND`）—— 只压高频，背后轮廓仍可辨 */
    case Blur = 1;

    /**
     * 磨砂玻璃（Win10 `ACCENT_ENABLE_ACRYLICBLURBEHIND`）—— 重模糊 + 系统的色调层。
     *
     * ★ 真机实测（Win10 19045）：`detail` 从 6.83 掉到 1.64（桌面基准 6.83），
     *   背后窗口只剩柔和的明暗团块。
     */
    case Acrylic = 2;
}
