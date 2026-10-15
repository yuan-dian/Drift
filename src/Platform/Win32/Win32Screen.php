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
 *
 * ★ `viewport()` 是**紧约束**且单位是**逻辑**像素：它是 `attach()` / `frame()`
 *   的第二个参数（§A.1），而物理像素的量化只发生在 `RenderSurface::resize()`
 *   里（§6.1：量化只在一个地方发生）。
 *
 * @internal 平台实现；应用只认 `Drift\Platform\Screen`
 */
final class Win32Screen implements Screen
{
    public function __construct(
        private readonly Win32WindowHost $windows,
    ) {
    }

    public function scale(): float
    {
        $handle = $this->windows->handle();
        if ($handle === null) {
            return 1.0;   // 还没建窗 ⇒ 96dpi 是唯一安全的默认
        }

        return Scale::fromDpi((float) drift_win_dpi($handle))->dpr;
    }

    public function viewport(): BoxConstraints
    {
        $handle = $this->windows->handle();
        if ($handle === null) {
            return BoxConstraints::tight(Size::zero());
        }

        // clientSize() 已经是逻辑像素（换算在 Win32WindowHost 里走 Scale）
        $size = $this->windows->clientSize($handle);

        // 负尺寸不可能来自 Win32，但"最小化 ⇒ 0×0"是真实状态（WM_SIZE 的 lp 为 0）
        // ⇒ 夹到 0 而不是让 BoxConstraints 收到负数（那会让 enforce() 算出反向区间）。
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
