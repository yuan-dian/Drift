<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Smoke\Overlay\RecordingSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Platform\Win32\Win32SkiaSurface;
use Drift\Rendering\Color;

/**
 * 表面底色（`RenderSurface::setBackground()`，从 smoke 迁出）。
 *
 * ## 这一组钉什么
 *
 * 底色必须**真的落到像素上**（不是只存字段），而且要在三个后端上口径一致：
 * 软件光栅、Skia、以及浮层取像用的 `RecordingSurface`。
 *
 * - Raster：清屏像素按**预乘**口径（`dst = src·a + dst·(1−a)`，落在全零缓冲上
 *   ⇒ rgb 各乘一次 a/255）。
 * - Skia：底色（含 alpha）原样进 `clear` 参数 —— 那是逐像素透明的**唯一**来源
 *   （画布是 N32Premul，`clear` 等价 `drawColor(kSrc)`）。漏掉 alpha ⇒ 窗口全不透明，
 *   且**不报错**。
 * - RecordingSurface：`raster()` 必须用同一个底色回放，否则半透明浮层的像素断言
 *   全按白底算（那类断言会"看起来对"）。
 *
 * ## 迁移时的有效性审计（2026-10-28 第 25 批）
 *
 * - **有效**：三条都落在"像素/参数真的变了"上，不是字段回读。
 * - ★ **期望值必须算、不能抄**：预乘那组 (51,102,153,128) → (26,51,77,128) 是
 *   `51·128/255 = 25.6 → 26` 等三处算出来的。写直通值会红 —— 而那个红正是
 *   "漏了预乘"这个 bug 的形状。
 * - ★ **ARGB 别照实参顺序抄**：`Color::toArgb()` 是 `0xAARRGGBB`，所以断言写成
 *   `0x44112233`（a=44 r=11 g=22 b=33），照 `new Color($r,$g,$b,$a)` 的实参顺序
 *   抄会"看起来对"。
 */
final class SurfaceBackgroundTest extends SmokeTestCase
{
    public function testRasterClearPixelIsPremultipliedBackground(): void
    {
        $surface = new SurfaceProbe();
        $surface->resize(20, 10, 1.0);
        $surface->setBackground(new Color(0x33, 0x66, 0x99, 0x80));
        $surface->beginFrame(0.0);
        $pixel = $surface->canvas?->pixelAt(0, 0);

        // 51·128/255 = 25.6 → 26；102·128/255 = 51.2 → 51；153·128/255 = 76.8 → 77
        check(
            'RenderSurface · setBackground 落到清屏像素（预乘：51,102,153,128 → 26,51,77,128）',
            $pixel instanceof Color
                && $pixel->r === 26 && $pixel->g === 51 && $pixel->b === 77
                && $pixel->a === 128,
            $pixel instanceof Color
                ? \sprintf('%d,%d,%d,%d', $pixel->r, $pixel->g, $pixel->b, $pixel->a)
                : 'pixel=null',
        );
    }

    public function testSkiaClearGetsBackgroundWithAlpha(): void
    {
        skiaBridgeReset();
        $surface = new Win32SkiaSurface(2);
        $surface->resize(30, 20, 1.0);
        $surface->setBackground(new Color(0x11, 0x22, 0x33, 0x44));
        $surface->beginFrame(0.0);

        $create = null;
        foreach (skiaCalls() as $entry) {
            if ($entry[0] === 'create') {
                $create = $entry;
                break;
            }
        }

        check(
            'Win32SkiaSurface · 底色原样进 Skia 的 clear 参数（含 alpha：a=44 r=11 g=22 b=33）',
            $create !== null && $create[3] === 0x44112233,
            $create === null ? 'no create' : \sprintf('0x%08X', $create[3]),
        );
    }

    public function testRecordingSurfaceRasterUsesBackground(): void
    {
        // `RecordingSurface::raster()` 是浮层像素断言的取像口 —— 它必须用同一个底色，
        // 否则半透明浮层的像素断言全按白底算（差值正好是一个白，且不报错）。
        $surface = new RecordingSurface();
        $surface->resize(4, 4, 1.0);
        $surface->setBackground(new Color(0x10, 0x20, 0x30));
        $surface->beginFrame(0.0);
        $pixel = $surface->raster()->pixelAt(0, 0);

        check(
            'RecordingSurface · raster() 用 setBackground 的底色回放（不是写死白）',
            $pixel->r === 0x10 && $pixel->g === 0x20
                && $pixel->b === 0x30 && $pixel->a === 255,
            \sprintf('%d,%d,%d,%d', $pixel->r, $pixel->g, $pixel->b, $pixel->a),
        );
    }
}
