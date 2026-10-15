<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Platform\SurfaceProvider;
use Drift\Rendering\RenderSurface;

/**
 * Win32 的表面提供者（§5.1 / §7.1）。
 *
 * ★ **同一窗口只造一个 surface**：`Application` 在 `attach()` 里持有它并每帧
 *   调用 ⇒ 每次 `surface()` 都 new 一个的话，`resize()` 设的尺寸会丢
 *   （谁持有哪一份成了运气），表现为"窗口拉大后画面还是原来的大小"。
 *
 * ★ 句柄在这里**只作为身份**（数组键 / 传给 surface），不解引用 ——
 *   解引用发生在桥里（§5.1 纪律 1）。
 *
 * @internal 平台实现；应用只认 `Drift\Platform\SurfaceProvider`
 */
final class Win32SurfaceProvider implements SurfaceProvider
{
    private ?RenderSurface $surface = null;

    public function __construct(
        private readonly int $windowHandle,
    ) {
    }

    public function surface(): RenderSurface
    {
        return $this->surface ??= new Win32RasterSurface($this->windowHandle);
    }
}
