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
    /**
     * 默认后端（编译进产物）。`'soft'` = PHP 软光栅，`'skia'` = Skia 光栅。
     *
     * ★ **当前默认仍是 `'soft'`**：Skia 后端（`SkiaCanvas`）的**几何**已就绪，
     *   但文字仍用"Drift 的度量 + Skia 的字形"（`SkiaTextMetricsProvider` 归 M4）
     *   ⇒ 拉丁字母的步进会有肉眼可见的偏差。等 M4 那条链落地再翻默认。
     *
     * ★ **后端由组合根决定**（§5.1「平台名只出现在组合根」）：
     *   `new Win32Platform('skia')`。它**不是环境变量** —— §16.7 的 `nano-hygiene`
     *   明禁 `getenv`/`putenv`（Drift 现状"0 处"），不能为调试开关破这条纪律；
     *   也不是 §12.3 的配置系统（那是用户可见的主题/设置，这个是部署形态）。
     */
    private const DEFAULT_RENDERER = 'soft';

    private ?RenderSurface $surface = null;

    /**
     * @param string|null $renderer `'skia'` = Skia 光栅；`null` / 其它值 = 软光栅（默认）
     */
    public function __construct(
        private readonly int $windowHandle,
        private readonly ?string $renderer = null,
    ) {
    }

    public function surface(): RenderSurface
    {
        return $this->surface ??= $this->create();
    }

    private function create(): RenderSurface
    {
        if ($this->backend() === 'skia') {
            return new Win32SkiaSurface($this->windowHandle);
        }
        return new Win32RasterSurface($this->windowHandle);
    }

    /**
     * 选后端：只认 `'skia'`，其余（含 `null`）一律退回 `DEFAULT_RENDERER`。
     *
     * ★ 未知值**不报错也不静默选错** —— 退回默认。这里不引 `Errors`：
     *   平台层不该依赖错误出口（§4.3）。
     */
    private function backend(): string
    {
        return $this->renderer === 'skia' ? 'skia' : self::DEFAULT_RENDERER;
    }
}
