<?php

declare(strict_types=1);

namespace Drift\Rendering;

/**
 * GPU 上下文：设备 / 队列所有权，后端无关（§7.1）。
 *
 * ★ **`Paragraph` 不在这里**（§7.1 的说明）：排版不是 GPU 的事，把
 *   `createParagraph` 放进来会让文本栈的所有权被渲染层绑架。Skia 后端若需要缓存
 *   `skia::textlayout::Paragraph` 对象，那是 `SkiaCanvas` / `SkiaTextMetricsProvider`
 *   的私有实现细节，不上公共接口。
 *
 * ★ **诚实的分期**（§7.3）：M1 是 Skia **CPU 光栅**，本接口可以是"直通 CPU"的空
 *   实现 —— 接口先行、后端后补，避免重演"硬连线 ⇒ 换后端要先抽象"。
 *
 * @api platform
 */
interface GpuContext
{
    /** @return int 纹理句柄 */
    public function uploadBitmap(string $rgba, int $width, int $height): int;

    /** 释放 GPU 侧资源 */
    public function release(int $handle): void;
}
