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
     * ★★ **2026-10-17 翻成 `'skia'`**（原为 `'soft'`）。理由只有一条，且是实测的：
     *   软光栅的文字来自**位图字体**（CJK `16×16` 二值、拉丁 `5×8` 二值，见
     *   `tools/font-gen-glyphs.py` 与 `DeterministicFontData`）⇒ 14px 上屏必然是
     *   点阵放大/降采样，`Title=18px > unitsPerEm=16` 更是走最近邻放大。**那不是
     *   "调参能好"的问题，是数据形态的上限**。Skia 走真 TTF + `kAntiAlias`，
     *   顺带把"字模表没扫到的字不画"这个缺口也消掉（真字体有全字集）。
     *
     * ★ 代价与前置（部署形态，§4.6）：exe 依赖 `cpp/fonts/NotoSansSC-*.ttf`
     *   （21 MB，OFL，可再分发）。字体查找**先试 exe 所在目录**（见
     *   `cpp-src/drift_skia.cc` 的 `ensureFont()`）⇒ 分发时把 `fonts/` 放到 exe
     *   旁边即可，**不再要求以仓库根为 cwd 启动**。找不到字体时 `SkiaCanvas`
     *   会记一条 Diagnostic 并如实不画（不中止、不画方框）。
     *
     * ★ 软光栅**没有被删**：`new Win32Platform('soft')` 仍可选，它是确定性
     *   测试后端（golden / conformance 的"一套测量、两个光栅器"要靠它）。
     *
     * ★ **后端由组合根决定**（§5.1「平台名只出现在组合根」）：
     *   `new Win32Platform('skia' | 'soft')`。它**不是环境变量** —— §16.7 的
     *   `nano-hygiene` 明禁 `getenv`/`putenv`（Drift 现状"0 处"），不能为调试
     *   开关破这条纪律；也不是 §12.3 的配置系统（那是用户可见的主题/设置，
     *   这个是部署形态）。
     */
    private const DEFAULT_RENDERER = 'skia';

    private ?RenderSurface $surface = null;

    /**
     * @param string|null $renderer `'skia'` = Skia 光栅（默认）；`'soft'` = 软光栅；
     *   `null` / 其它值 = `DEFAULT_RENDERER`
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
     * 选后端：认 `'skia'` / `'soft'`，其余（含 `null`）退回 `DEFAULT_RENDERER`。
     *
     * ★ **`'soft'` 必须被显式认下**（2026-10-17）：默认翻成 `'skia'` 之后，
     *   若这里仍写"只认 `'skia'`、其余退回默认"，`new Win32Platform('soft')`
     *   会被**静默翻成 skia** —— "软光栅仍可选"就成了一句空话，而且症状是
     *   "我要的确定性后端没生效"，最难查。冒烟里 `'soft'` 那条夹具钉住它。
     *
     * ★ 未知值**不报错也不静默选错** —— 退回默认。这里不引 `Errors`：
     *   平台层不该依赖错误出口（§4.3）。
     *
     * ★ 抽成**静态纯函数**而不是留在 `backend()` 里：`backend()` 是 private，
     *   而这条规则的两个失败模式（默认没翻、显式 `'soft'` 被吞）都是**静默**的
     *   ⇒ 必须有夹具钉住。造一个 provider 实例去测要多绕一层（构造 + 反射），
     *   纯函数让 smoke 直接调一行。
     *
     * @internal 公开只为可测；应用不该调它（§5.1：后端由组合根决定）
     */
    public static function resolveBackend(?string $renderer): string
    {
        if ($renderer === 'skia' || $renderer === 'soft') {
            return $renderer;
        }
        return self::DEFAULT_RENDERER;
    }

    /** 实例侧只是转调 —— 逻辑**只有一份**，避免两处实现改漏一处就分叉 */
    private function backend(): string
    {
        return self::resolveBackend($this->renderer);
    }
}
