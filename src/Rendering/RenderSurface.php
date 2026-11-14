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
     * ★ 底色改的是"画出来是什么像素"，归表面；整窗 alpha / 背后材质改的是合成器
     *   怎么合成已画好的像素，归 `WindowMaterial`。挪进窗口抽象会让"后端可换"漏掉。
     * ★ 底色带 alpha ⇒ 该窗口逐像素透明，应用没画到的地方透出桌面；`a === 255`
     *   可以走不透明快路径。模式声明仍归 `WindowMaterial`。
     * ★ 允许运行中改（主题切换 / 拖透明度滑杆）：实现必须每帧生效，不能只在构造时读一次。
     */
    public function setBackground(Color $color): void;

    /** 返回本帧画布；`timestampMs` 由 `FrameClock` 注入，不读环境时钟（§14.1） */
    public function beginFrame(float $timestampMs): Canvas;

    /** present */
    public function endFrame(): void;
}
