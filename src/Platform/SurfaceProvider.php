<?php
declare(strict_types=1);

namespace Drift\Platform;

use Drift\Rendering\RenderSurface;

/**
 * 渲染表面的提供者（§5.1 / §7.1）。
 *
 * ★ **为什么多这一层 indirection**：`Platform::surface()` 返回 `?SurfaceProvider`
 *   而不是 `?RenderSurface`，是为了让"**这个平台没有渲染能力**"在类型层面与
 *   "有渲染能力但还没建好表面"分开。前者是 `null`，后者是"provider 在、surface 还没
 *   resize"。GUI 仓的教训是"绘制层硬依赖 ⇒ 换绘制后端要先抽象"，这层就是那个抽象。
 *
 * ★ **表面与应用解耦**（§7.2 裁决 4）：`SurfaceProvider` 由平台按窗口给出、
 *   `Application` 持 `RenderSurface`，而 **`RenderObject` 树不认识 surface**
 *   —— 测试里手搓 `RasterCanvas` 就能跑完整渲染（§14 确定性的物质基础）。
 *
 * ★ 依赖说明：本文件让 `Platform` 层引用了 `Rendering` 的 `RenderSurface`。
 *   §4.3 的 `Platform` 行漏列了这一条，而 §5.1 + §7.1 的签名已经要求它 ⇒
 *   按"写全已有裁决"补正（与 `Theme` 行补 `Rendering` 同一个先例）。
 *
 * @api platform
 */
interface SurfaceProvider
{
    /**
     * 本窗口的渲染表面。同一 provider 重复调用返回**同一个** surface 实例
     * （`Application` 在 `attach()` 里持它，每帧调 `beginFrame()` / `endFrame()`）。
     */
    public function surface(): RenderSurface;
}
