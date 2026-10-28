<?php
declare(strict_types=1);

/**
 * Win32 桥的**解释器替身**（smoke 与 PHPUnit 共用一份）。
 *
 * ★ smoke / phpunit 从不构造真窗口，只验"PHP 侧把意图翻成了哪个 ABI 编号 / 哪个 bool"。
 * ★ 签名与 `cpp-src/drift_win32.stub.php` 逐字一致（stub 原话：签名不一致是
 *   链接期静默失败）。收敛后三个散落入口（set_opacity / set_pixel_alpha /
 *   set_backdrop）合成一个 `apply_material`，外加能力查询 `material_caps`。
 * ★ `set_frameless` / `dpi` 也假：像素 alpha 要无边框，`applyMaterial()` 的
 *   正路径测试需要先 `setFrameless()`，而它内部会读 `dpi` 折物理像素。
 *
 * ★ `drift_win_material_caps()` **可配**（`$GLOBALS['DRIFT_SMOKE_WIN_CAPS']`）：
 *   恒返"全能力"的话，"五个 getter 各对应哪一位"这条断言**证伪不了**
 *   —— 把位 1 和位 2 的解析写反，五项仍然全 true（2026-10-28 第 25 批实测）。
 *   默认仍是全能力（Win11 形态），旧断言不受影响。
 */
// ── Win32 桥的假实现（窗口外观材质，P1 收敛 2026-10-26）────────────────
// ★ smoke 从不构造真窗口，只验"PHP 侧把意图翻成了哪个 ABI 编号 / 哪个 bool"。
// ★ 签名与 `cpp-src/drift_win32.stub.php` 逐字一致（stub 原话：签名不一致是
//   链接期静默失败）。收敛后三个散落入口（set_opacity / set_pixel_alpha /
//   set_backdrop）合成一个 `apply_material`，外加能力查询 `material_caps`。
// ★ `set_frameless` / `dpi` 也假：像素 alpha 要无边框，`applyMaterial()` 的
//   正路径测试需要先 `setFrameless()`，而它内部会读 `dpi` 折物理像素。
if (!function_exists('drift_win_apply_material')) {
    function drift_win_apply_material(int $hwnd, bool $pixelAlpha, float $opacity, int $backdropKind): void
    {
        $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'][] = [$hwnd, $pixelAlpha, $opacity, $backdropKind];
    }
}

/** @return list<array{int, bool, float, int}> 材质调用记录：[hwnd, pixelAlpha, opacity, backdropKind] */
function winMaterialCalls(): array
{
    return $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'] ?? [];
}

if (!function_exists('drift_win_set_frameless')) {
    function drift_win_set_frameless(int $hwnd, int $dragHeight): void
    {
        $GLOBALS['DRIFT_SMOKE_WIN_FRAMELESS'][] = [$hwnd, $dragHeight];
    }
}

if (!function_exists('drift_win_dpi')) {
    function drift_win_dpi(int $hwnd): int
    {
        return 96;   // 假成 96 DPI ⇒ dpr 1.0；真实 Win32 的一致性由真机闸门保证
    }
}

if (!function_exists('drift_win_material_caps')) {
    function drift_win_material_caps(): int
    {
        // 默认"全能力"（Win11 形态）：opacity | pixelAlpha | blur | acrylic | 圆角。
        // ★ 夹具可以覆盖成**部分能力**，否则"位 ↔ getter 名字"这条断言证伪不了。
        return $GLOBALS['DRIFT_SMOKE_WIN_CAPS'] ?? (0x01 | 0x02 | 0x04 | 0x08 | 0x10);
    }
}

/** 清空材质调用记录（每个用例前必调，否则用例互相污染） */
function winMaterialReset(): void
{
    $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'] = [];
}

/**
 * 设定假桥报告的能力位掩码；`null` 恢复默认（全能力 = Win11 形态）。
 *
 * 位定义见 `WindowMaterialCapabilities::fromBits()`：1 opacity / 2 pixelAlpha /
 * 4 blur / 8 acrylic / 16 圆角。
 */
function winMaterialCapsFake(?int $bits = null): void
{
    if ($bits === null) {
        unset($GLOBALS['DRIFT_SMOKE_WIN_CAPS']);

        return;
    }
    $GLOBALS['DRIFT_SMOKE_WIN_CAPS'] = $bits;
}
