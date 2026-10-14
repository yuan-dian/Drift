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

    /** 返回本帧画布；`timestampMs` 由 `FrameClock` 注入，不读环境时钟（§14.1） */
    public function beginFrame(float $timestampMs): Canvas;

    /** present */
    public function endFrame(): void;
}
