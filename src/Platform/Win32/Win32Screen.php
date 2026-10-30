<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Platform\Screen;
use Drift\Theme\SystemTheme;

use function drift_win_dpi;
use function drift_win_system_dark;

/**
 * Win32 屏幕端口：DPI / 视口 / 系统主题（§5.1 / §6 / §9.2）。
 *
 * ★ 它**不缓存** `scale()`：窗口跨屏拖动时 DPI 会变，缓存就要配失效逻辑，
 *   而"读一次 DPI"只是一次 `GetDpiForWindow` —— 便宜的调用不该换缓存一致性风险。
 *   也正因为无状态，`Platform::screen($h)` 每次新建一个即可（不需要按句柄缓存）。
 *
 * ★ **句柄是构造参数**（2026-10-30，M1-b）：改动前它问 `Win32WindowHost::handle()`
 *   —— 那是"最后建的窗"，多窗口下两个窗会读到同一份 DPI / 客户区。
 *
 * ★ `viewport()` 是**紧约束**且单位是**逻辑**像素：它是 `attach()` / `frame()`
 *   的第二个参数（§A.1），而物理像素的量化只发生在 `RenderSurface::resize()`
 *   里（§6.1：量化只在一个地方发生）。
 *
 * @internal 平台实现；应用只认 `Drift\Platform\Screen`
 */
final class Win32Screen implements Screen
{
    /**
     * @param Win32WindowHost $windows 只为复用它的 `clientSize()`（已含逻辑像素换算）
     * @param int $handle 这块屏幕属于**哪个窗口**
     */
    public function __construct(
        private readonly Win32WindowHost $windows,
        private readonly int $handle,
    ) {
    }

    public function scale(): float
    {
        if ($this->handle <= 0) {
            return 1.0;   // 还没建窗 ⇒ 96dpi 是唯一安全的默认
        }

        return Scale::fromDpi((float) drift_win_dpi($this->handle))->dpr;
    }

    public function viewport(): BoxConstraints
    {
        if ($this->handle <= 0) {
            return BoxConstraints::tight(Size::zero());
        }

        // clientSize() 已经是逻辑像素（换算在 Win32WindowHost 里走 Scale）
        $size = $this->windows->clientSize($this->handle);

        // 负尺寸不可能来自 Win32，但"最小化 ⇒ 0×0"是真实状态。
        // ★ 这条口径由**平台侧**保证（`drift_win_client_size` 对 `IsIconic` 直接报
        //   0×0）：不能指望 `GetClientRect` 自己给 0 —— 最小化后窗口是 160×28 的
        //   小方块，**无边框窗**的客户区就是整窗 160×28（有标题栏时才被扣成高度 0）。
        //   2026-10-26 真机实证：漏了这一步，无边框窗最小化后按 160×28 布局 ⇒
        //   顶条文字溢出 ⇒ C6 Fatal。
        // 夹到 0 而不是让 BoxConstraints 收到负数（那会让 enforce() 算出反向区间）。
        return BoxConstraints::tight(Size::of(
            max(0.0, $size->width),
            max(0.0, $size->height),
        ));
    }

    public function systemTheme(): SystemTheme
    {
        return drift_win_system_dark() ? SystemTheme::Dark : SystemTheme::Light;
    }
}
