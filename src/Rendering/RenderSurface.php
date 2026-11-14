<?php

declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 渲染表面：每窗口一个，抽象 swapchain / present。
 *
 * 表面与 RenderObject 树互不认识：测试里手搓 `RasterCanvas` 就能跑完整渲染。
 *
 * @api platform
 */
interface RenderSurface
{
    /** 物理像素尺寸 + dpr（量化只在这里发生） */
    public function resize(int $width, int $height, float $dpr): void;

    /**
     * 设置每帧清屏的底色（默认白）。
     *
     * ★ 底色改的是"画出来是什么像素"，归表面；整窗 alpha / 背后材质改的是合成器
     *   怎么合成已画好的像素，归 `WindowMaterial`。挪进窗口抽象会让"后端可换"漏掉。
     * ★ 允许运行中改（主题切换 / 拖透明度滑杆）：实现必须每帧生效，不能只在构造时读一次。
     */
    public function setBackground(Color $color): void;

    /** 返回本帧画布；`timestampMs` 由 `FrameClock` 注入，不读环境时钟 */
    public function beginFrame(float $timestampMs): Canvas;

    /** present */
    public function endFrame(): void;
}
