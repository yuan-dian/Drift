<?php
declare(strict_types=1);

namespace Drift\Platform;

use Drift\Geometry\BoxConstraints;
use Drift\Theme\SystemTheme;

/**
 * 屏幕 / DPI / 视口 / 系统主题（§5.1 / §6 / §9.2）。
 *
 * ★ **`viewport()` 是"根约束"的唯一出处**：`Application::attach()` 与
 *   `Application::frame()` 的第二个参数就是它（§A.1 的可执行样例逐字如此）。
 *   它是**逻辑**单位的紧约束（= 窗口客户区 / `scale()`），物理像素的量化只发生在
 *   `RenderSurface::resize()` 里（§6.1：量化只在一个地方发生）。
 *
 * ★ `scale()` 变化 ⇒ `DpiChanged` 事件 ⇒ **全树重建**（不是 relayout）——
 *   它改的是 `build()` 的输入（字号 / 间距 token 都会变），§9.2。
 *
 * @api platform
 */
interface Screen
{
    /**
     * DPI 缩放（1.0 = 96dpi；125% ⇒ 1.25）。
     *
     * ★ 分数缩放必须**全程保留小数**，不许在中途取整 —— 取整点只有
     *   `Scale::quantizeRect()` 一处（§6.1）。
     */
    public function scale(): float;

    /**
     * 主窗口客户区的**逻辑**尺寸，作为根约束（§A.1）。
     *
     * 返回紧约束：窗口尺寸就是应用可用的全部空间，根子树没有"想多大就多大"的余地。
     */
    public function viewport(): BoxConstraints;

    /**
     * 系统深色模式（§9.2）。
     *
     * ★ Win32 来源：`WM_SETTINGCHANGE`（`lParam == "ImmersiveColorSet"`）或启动时
     *   读注册表 `AppsUseLightTheme`。它**复用现有事件通道**，不新增端口方法
     *   （§5.1 裁决 3）。
     */
    public function systemTheme(): SystemTheme;
}
