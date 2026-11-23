<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 窗口背后透出的材质。
 *
 * 应用声明意图，平台决定机制；与模糊应用自身内容的 `BackdropFilter` 不同。
 * 只有逐像素 alpha 才能看见它：不透明窗口会盖住 DWM 在背后画的模糊层。
 * `value` 就是 `drift_win_apply_material()` 的 ABI 编号，改值必须同步 C++ 侧 switch；
 * `null` = 不声明 / 关掉背景，所以没有 None case。
 *
 * @api
 */
enum Backdrop: int
{
    /** 轻模糊（Win10 `ACCENT_ENABLE_BLURBEHIND`）—— 只压高频，背后轮廓仍可辨 */
    case Blur = 1;

    /** 磨砂玻璃：重模糊 + 系统色调层；值同步 C++ switch。 */
    case Acrylic = 2;
}
