<?php

declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 渲染表面：每窗口一个，抽象 swapchain / present（§7.1）。
 *
 * ★ 表面与 RenderObject 树**互不认识**（§7.2 裁决 4）：`SurfaceProvider` 由平台
 *   按窗口给出、`Application` 持 surface —— 测试里手搓 `RasterCanvas` 就能跑完整
 *   渲染，这是 §14 确定性的物质基础。
 *
 * @api platform
 */
interface RenderSurface
{
    /** 物理像素尺寸 + dpr（§6.1：量化只在这里发生） */
    public function resize(int $width, int $height, float $dpr): void;

    /**
     * 设置每帧清屏的底色（默认白）。
     *
     * ★ **为什么在表面上而不是 `WindowHost` 上**：`setOpacity()` 改的是**合成器**
     *   怎么合成已经画好的像素（DWM / `LWA_ALPHA` 的事，像素一个不变）；而底色改的是
     *   **画出来是什么像素**。两者分属不同层 —— 底色一旦挪进窗口抽象，`SurfaceProvider`
     *   这层"后端可换"（§7.2 裁决 4）就漏了。
     *
     * ★ **底色带 alpha ⇒ 该窗口逐像素透明**：应用没画到的地方透出桌面。这是应用
     *   表达"窗口半透明"的唯一入口 —— 根 Widget 不自绘背景时，透出来的就是这个颜色。
     *   `a === 255` 的实现可以走"不透明"快路径（见各实现的注释）。
     *
     * ★ 允许运行中改（主题切换 / 用户拖透明度滑杆）：实现必须**每帧生效**，不能只在
     *   构造时读一次。默认值 `Color::white()` 由各实现给（接口不写死，免得
     *   非像素表面被迫造一个假白）。
     */
    public function setBackground(Color $color): void;

    /** 返回本帧画布；`timestampMs` 由 `FrameClock` 注入，不读环境时钟（§14.1） */
    public function beginFrame(float $timestampMs): Canvas;

    /** present */
    public function endFrame(): void;
}
