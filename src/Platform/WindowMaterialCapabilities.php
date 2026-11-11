<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 当前平台对 `WindowMaterial` 各旋钮的**实际支持情况**（P1 收敛裁决的能力半边）。
 *
 * ★ **为什么需要它**：这套能力反复踩"本机验不了就不发货"的墙 ——
 *   `DWMWA_SYSTEMBACKDROP_TYPE` 要 Win11 22621+（Win10 实测返回失败、画面零变化）、
 *   `DWMWCP_ROUND` 在 Win10 上是**静默 no-op**（API 返回 S_OK 但不裁角，
 *   2026-10-26 真机证伪过"分层窗照样裁角"的误判）。没有能力查询，Win11 分支
 *   的唯一安全策略就是"永远不写"。`capabilities()` 把"平台能不能"变成应用
 *   可问的事实，Win11 分支才写得出来（写了也在不支持的机器上自动关）。
 *
 * ★★ **位的编号就是桥的 ABI**（与 `Backdrop::value` / `CursorShape::value`
 *   同一条纪律）：`drift_win_material_caps(): int` 返回的位掩码按下面各属性
 *   注释里的位值定义。**改位值等于改 ABI**，必须与 `cpp-src/win32/drift_win32.cc`
 *   的 `php_drift_win_material_caps()` 同步。
 *
 * ★ 平台能力、不是正确性：不支持 = 对应旋钮 no-op（与 `setBackdrop` 的
 *   "取不到就静默跳过"同一口径）。应用问它不是为了报错，是为了**自适应**
 *   （比如不支持亚克力时退回 `Blur` 或干脆不设）。
 *
 * @api
 */
final class WindowMaterialCapabilities
{
    private function __construct(
        private readonly bool $opacity,
        private readonly bool $pixelAlpha,
        private readonly bool $backdropBlur,
        private readonly bool $backdropAcrylic,
        private readonly bool $roundedCorners,
    ) {
    }

    /**
     * @param int $bits 桥的位掩码（ABI，见各类注释）；未知位**忽略** ——
     *                  前向兼容：新平台多出的位不该让旧应用崩。
     */
    public static function fromBits(int $bits): self
    {
        return new self(
            ($bits & 1) !== 0,
            ($bits & 2) !== 0,
            ($bits & 4) !== 0,
            ($bits & 8) !== 0,
            ($bits & 16) !== 0,
        );
    }

    /** 整窗不透明度（位 1）：LWA 自 Win2000 起就有，实际恒 true —— 留位是为了跨平台对称 */
    public function opacity(): bool
    {
        return $this->opacity;
    }

    /** 逐像素透明（位 2）：`UpdateLayeredWindow`（XP+）；**仍要求无边框**，见 `WindowMaterial` 类注释 ① */
    public function pixelAlpha(): bool
    {
        return $this->pixelAlpha;
    }

    /** 背后轻模糊（位 4）：Win10 走未公开 accent 策略，探不到入口即 false */
    public function backdropBlur(): bool
    {
        return $this->backdropBlur;
    }

    /** 背后磨砂（位 8）：同上；Win11 的 `DWMWA_SYSTEMBACKDROP_TYPE` 路线接入后并进这一位 */
    public function backdropAcrylic(): bool
    {
        return $this->backdropAcrylic;
    }

    /** 系统圆角（位 16）：Win11 build 22000+；Win10 上 `DWMWCP_ROUND` 静默 no-op ⇒ 如实报 false */
    public function roundedCorners(): bool
    {
        return $this->roundedCorners;
    }
}
