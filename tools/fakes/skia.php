<?php
declare(strict_types=1);

/**
 * Skia 桥的**解释器替身**（smoke 与 PHPUnit 共用一份）。
 *
 * ★ 为什么要有：`SkiaCanvas` 直调全局函数 `drift_skia_*`（ABI 直调，设计选择），
 *   而真桥只存在于 AOT 构建里 —— 解释器面调用会落到这里的假函数，参数进
 *   `$GLOBALS['DRIFT_SMOKE_SKIA_CALLS']`，"Skia 收到了什么"于是成为**可断言的纯数据**，
 *   不必每次构建 exe（构建一次 60s+，而这类断言一天要跑几百遍）。
 * ★ **签名与 `cpp-src/skia/drift_skia.stub.php` 逐字一致** —— stub 注释原话：
 *   "签名不一致时是链接期静默失败"。改这里必须同步改 stub，否则测的就不是 ABI 本身；
 *   一致性由 `tools/abi-check.php` 机器判据保证（V2 `abi` step）。
 * ★ **只留一份**：`tools/smoke.php` 与 `tests/Support/bootstrap.php` 都 require 本文件。
 *   两份副本会漂（一边加了参数另一边没加），而漂了的表现是"smoke 绿、phpunit 红"。
 * ★ `$GLOBALS` 键名保留 `DRIFT_SMOKE_` 前缀：改名要动几十处断言，收益为零。
 *   读取请走 `skiaCalls()` / `skiaCallOps()` 等访问器，不要直接摸 `$GLOBALS`。
 *
 * 两个开关只服务夹具：
 *   `DRIFT_SMOKE_SKIA_FAIL_CREATE` ⇒ 造"句柄 0"（H 用例）；
 *   `DRIFT_SMOKE_SKIA_SABOTAGE`    ⇒ 把 draw_rect 的 x 篡改一格（量具反例自检）。
 */
// ── Skia 桥的假实现（⑨+1 conformance 夹具用）──────────────────────
// ★ 签名与 cpp-src/skia/drift_skia.stub.php 逐字一致 —— 夹具测的必须就是 ABI 本身
//   （stub 注释原话："签名不一致时是链接期静默失败"）。
// ★ `use function` 是运行期解析：真桥只存在于 AOT 构建，解释器面从不加载
//   ⇒ SkiaCanvas 的调用落到这些假函数，参数进 $GLOBALS 记录 ——
//   "Skia 收到了什么"成为可断言的纯数据，不必每次构建 exe。
// ★ 两个开关只服务夹具：FAIL_CREATE 造"句柄 0"（H 用例）；
//   SABOTAGE 把 draw_rect 的 x 篡改一格（量具反例自检）。
if (!function_exists('drift_skia_canvas_create')) {
    function drift_skia_canvas_create(int $width, int $height, int $backgroundRgba): int
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['create', $width, $height, $backgroundRgba];
        return empty($GLOBALS['DRIFT_SMOKE_SKIA_FAIL_CREATE']) ? 1 : 0;
    }

    function drift_skia_canvas_destroy(int $canvas): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['destroy'];
    }

    function drift_skia_push_clip(int $canvas, float $x, float $y, float $w, float $h): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['push_clip', $x, $y, $w, $h];
    }

    function drift_skia_pop_clip(int $canvas): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['pop_clip'];
    }

    function drift_skia_draw_rect(
        int $canvas,
        float $x,
        float $y,
        float $w,
        float $h,
        float $radius,
        int $rgba,
        bool $stroke,
        float $strokeWidth,
    ): void {
        if (!empty($GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'])) {
            $x += 1.0; // 已知违规件：只服务量具反例自检（判据恒真 = 夹具空转）
        }
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_rect', $x, $y, $w, $h, $radius, $rgba, $stroke, $strokeWidth];
    }

    function drift_skia_draw_glyph(
        int $canvas,
        string $family,
        bool $bold,
        float $fontSize,
        float $penX,
        float $baselineY,
        int $codepoint,
        int $rgba,
    ): void {
        if (!empty($GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'])) {
            $penX += 1.0; // 已知违规件：只服务量具反例自检（判据恒真 = 夹具空转）
        }
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_glyph', $family, $bold, $fontSize, $penX, $baselineY, $codepoint, $rgba];
    }

    function drift_skia_draw_ellipse(
        int $canvas,
        float $x,
        float $y,
        float $w,
        float $h,
        int $rgba,
        bool $stroke,
        float $strokeWidth,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_ellipse', $x, $y, $w, $h, $rgba, $stroke, $strokeWidth];
    }

    function drift_skia_draw_line(
        int $canvas,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        int $rgba,
        float $strokeWidth,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_line', $x1, $y1, $x2, $y2, $rgba, $strokeWidth];
    }

    function drift_skia_set_gradient(
        int $canvas,
        int $radial,
        float $x1, float $y1, float $x2, float $y2,
        int $n, int $c0, int $c1, int $c2, int $c3,
        float $s0, float $s1, float $s2, float $s3,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['set_gradient', $radial, $x1, $y1, $x2, $y2, $n, $c0, $c1, $c2, $c3, $s0, $s1, $s2, $s3];
    }

    function drift_skia_set_dash(int $canvas, float $on, float $off, float $phase): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['set_dash', $on, $off, $phase];
    }

    function drift_skia_clear_source(int $canvas): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['clear_source'];
    }

    // ── P4：Optional / Extended 复合 op 的假桥（记录调用，不真画）──
    function drift_skia_draw_path(
        int $canvas,
        int $count,
        string $verbs,
        string $coords,
        int $rgba,
        bool $stroke,
        float $strokeWidth,
    ): int {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_path', $count, $verbs, $coords, $rgba, $stroke, $strokeWidth];
        return $count * 3;
    }

    function drift_skia_draw_image(
        int $canvas,
        string $rgba,
        int $w,
        int $h,
        float $sx, float $sy, float $sw, float $sh,
        float $dx, float $dy, float $dw, float $dh,
        bool $hasSrc,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_image', strlen($rgba), $w, $h, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh, $hasSrc];
    }

    function drift_skia_draw_shadow(
        int $canvas,
        float $x, float $y, float $w, float $h,
        int $argb, float $radius, float $dx, float $dy, float $blur,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_shadow', $x, $y, $w, $h, $argb, $radius, $dx, $dy, $blur];
    }

    function drift_skia_push_layer(int $canvas, float $opacity): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['push_layer', $opacity];
    }

    function drift_skia_pop_layer(int $canvas): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['pop_layer'];
    }

    /** 毛玻璃：真桥只存在于 AOT ⇒ 这里记下"Skia 收到什么"（sigma 是否乘了 dpr、矩形是否进了设备空间） */
    function drift_skia_blur_backdrop(
        int $canvas,
        float $sigma,
        float $x,
        float $y,
        float $w,
        float $h,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['blur_backdrop', $sigma, $x, $y, $w, $h];
    }

    function drift_skia_push_clip_path(
        int $canvas,
        int $count,
        string $verbs,
        string $coords,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['push_clip_path', $count, $verbs, $coords];
    }

    function drift_skia_read_pixels(int $canvas): string
    {
        return '';
    }

    function drift_skia_font_ready(): bool
    {
        return true;
    }
}

// ── 假桥记录的访问器与换算（⑨+1 用）──────────────────────────────

/** 清空跨边界调用记录（每个用例前必调，否则用例互相污染） */
function skiaBridgeReset(): void
{
    $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] = [];
}

/** @return list<string> 按序取出调用操作名（create / draw_rect / push_clip …） */
function skiaCallOps(): array
{
    $ops = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        $ops[] = $call[0];
    }
    return $ops;
}

/** @return list<array> draw_rect 记录：[op, x, y, w, h, radius, rgba, stroke, strokeWidth] */
function skiaDrawRectCalls(): array
{
    $out = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        if ($call[0] === 'draw_rect') {
            $out[] = $call;
        }
    }
    return $out;
}

/** @return list<array> push_clip 记录：[op, x, y, w, h] */
function skiaClipCalls(): array
{
    $out = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        if ($call[0] === 'push_clip') {
            $out[] = $call;
        }
    }
    return $out;
}

/** @return list<array> draw_glyph 记录：[op, family, bold, fontSize, penX, baselineY, codepoint, rgba] */
function skiaGlyphCalls(): array
{
    $out = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        if ($call[0] === 'draw_glyph') {
            $out[] = $call;
        }
    }
    return $out;
}

/** 原始调用记录（按序）。新断言优先用下面几个按操作名筛过的访问器。 */
function skiaCalls(): array
{
    return $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [];
}
