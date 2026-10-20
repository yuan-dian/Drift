<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Text\Paragraph;

use function drift_skia_canvas_create;
use function drift_skia_canvas_destroy;
use function drift_skia_draw_glyph;
use function drift_skia_draw_rect;
use function drift_skia_font_ready;
use function drift_skia_pop_clip;
use function drift_skia_push_clip;
use function drift_skia_read_pixels;

/**
 * Skia 光栅画布（§7.2 裁决 1 的第三个实现；§7.3 的 M1 后端之二）。
 *
 * ★ **它与 `RasterCanvas` 是同一个东西的两个光栅器**：命令流、变换、量化、
 *   字形步进与基线**逐条对齐**，差别只在"谁把像素写出来"。所以：
 *   ① 两个后端可以互相做 conformance（§8.2 坑 6 的"一套测量实现，两个后端"）；
 *   ② `DisplayList` 重放两边都成立（G3 的 golden 才有可比性）。
 *
 * ★ **变换与量化留在 PHP**（§6.1：逻辑→物理只走 `Scale::quantizeRect()`）。
 *   桥收到的永远是设备坐标 —— 若让 Skia 也持一份变换，"先加偏移再取整"与
 *   "先取整再加偏移"会差半个像素，而那种账最难查。
 *
 * ★ **与 `RasterCanvas` 的两处已知差异**（有意，不是欠账）：
 *   ① 抗锯齿：软光栅只对圆角/字形做覆盖度，Skia 对所有边做 —— 边缘像素会有差异；
 *   ② 描边：软光栅是"外圈减内圈"的实心法（`RasterCanvas::drawRect`），
 *      Skia 用原生 `kStroke_Style`。宽度一致、圆角一致，但抗锯齿边缘不同。
 *   ⇒ **像素级 golden 不能跨后端比**，只能各自与自己的基线比。
 *
 * ★ 没有可用的 Skia 句柄时（创建失败）**所有绘制静默跳过**：这一帧没画，
 *   不是"应用该崩"（与光标形状同一档）。但 `isValid()` 会如实回答，供上层记 Diagnostic。
 *
 * @api
 */
final class SkiaCanvas implements Canvas
{
    /** 0 = 创建失败（见类注释：静默跳过，不中止） */
    private int $handle = 0;

    private float $offsetX = 0.0;
    private float $offsetY = 0.0;

    /** @var list<array{float, float}> 状态栈：只有偏移（裁剪由 Skia 自己持） */
    private array $stack = [];

    private readonly Scale $scale;

    /** 字体未就绪只报一次 —— 每帧报会把日志刷掉 */
    private bool $fontWarned = false;

    public function __construct(
        private readonly int $width,
        private readonly int $height,
        ?Color $background = null,
        ?Scale $scale = null,
    ) {
        $this->scale = $scale ?? Scale::identity();

        // ★ 先落局部标量再传（AOT 纪律 2：三元分支里的属性读取会被推成 Variant，
        //   与 Int 形参不匹配 ⇒ C2440）。
        $bg = $background ?? Color::white();
        $argb = $bg->toArgb();

        $this->handle = drift_skia_canvas_create($width, $height, $argb);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function isValid(): bool
    {
        return $this->handle !== 0;
    }

    /**
     * 画布句柄 —— 只给**壳侧的直贴路径**用（`drift_win_present_skia()`）。
     *
     * ★ 为什么需要它：贴屏本来要把像素 `toRgba()` 取回 PHP 再交回壳里，那条路每帧
     *   做两次方向相反的 swizzle + 一次 4MB 跨界，实测占端到端 50%+（`26` §5）。
     *   有了句柄，壳可以直接读 Skia 表面贴屏，像素不进 PHP。
     *
     * ★ 0 = 创建失败 ⇒ 调用方（`Win32SkiaSurface`）会回落到"这一帧不贴"，
     *   与"画布无效时所有绘制静默跳过"同一档，不是错误。
     *
     * @api platform 只有 `src/Platform/Win32/Win32SkiaSurface` 会读它
     */
    public function handle(): int
    {
        return $this->handle;
    }

    /** 原始 RGBA 字节流（行优先，每像素 4 字节）—— 与 `RasterCanvas::toRgba()` 同格式 */
    public function toRgba(): string
    {
        if ($this->handle === 0) {
            return '';
        }
        return drift_skia_read_pixels($this->handle);
    }

    /**
     * 释放底层 Skia 画布。**幂等** —— 由 surface 在 `endFrame()` 里显式调用。
     *
     * ★ 不靠 `__destruct`：AOT 下对象析构时机不由框架决定，而 Skia 画布是
     *   `new` 出来的原生资源，漏掉一次就是一张 w×h×4 的常驻内存（每帧一张）。
     *   显式释放是唯一能说清"什么时候还回去"的写法。
     */
    public function dispose(): void
    {
        if ($this->handle !== 0) {
            drift_skia_canvas_destroy($this->handle);
            $this->handle = 0;
        }
    }

    public function save(): void
    {
        $this->stack[] = [$this->offsetX, $this->offsetY];
    }

    public function restore(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return;   // 未配对的 restore 由 W8 在录制侧拦截，光栅侧静默忽略即可
        }
        [$this->offsetX, $this->offsetY] = $frame;
    }

    public function translate(float $dx, float $dy): void
    {
        $this->offsetX += $dx;
        $this->offsetY += $dy;
    }

    public function pushClip(Rect $rect): void
    {
        if ($this->handle === 0) {
            return;
        }
        $device = $this->toDevice($rect);
        drift_skia_push_clip(
            $this->handle,
            $device->left,
            $device->top,
            $device->width,
            $device->height,
        );
    }

    public function popClip(): void
    {
        if ($this->handle === 0) {
            return;
        }
        drift_skia_pop_clip($this->handle);
    }

    public function drawRect(Rect $rect, Paint $paint, float $radius = 0.0): void
    {
        if ($this->handle === 0) {
            return;
        }

        $device = $this->toDevice($rect);
        if ($device->width <= 0.0 || $device->height <= 0.0) {
            return;
        }

        // ★ 描边宽度**不乘 dpr**：`RasterCanvas::drawRect()` 在设备矩形上直接减
        //   `strokeWidth`，两个后端要能互相做 conformance ⇒ 口径必须一致。
        //   （这条口径本身是否该改是另一件事，改了要两个后端一起改。）
        $stroke = $paint->isStroke();
        $width = $paint->strokeWidth;
        $argb = $paint->color->toArgb();

        drift_skia_draw_rect(
            $this->handle,
            $device->left,
            $device->top,
            $device->width,
            $device->height,
            $radius,
            $argb,
            $stroke,
            $width,
        );
    }

    /**
     * 段落绘制：把已排好的字形逐个交给 Skia（位置由 `Paragraph` 给，本类不重算）。
     *
     * ★ 与 `RasterCanvas::drawParagraph()` 逐行对齐：同样的偏移叠加、同样的
     *   设备像素取整、同样的"笔位 += advance"。
     */
    public function drawParagraph(Paragraph $paragraph, Paint $paint, float $x = 0.0, float $y = 0.0): void
    {
        if ($this->handle === 0 || $paint->color->a === 0) {
            return;
        }

        $this->warnIfFontMissing();

        // ★ 文本必须吃画布变换：父盒给的位置是靠 translate() 递下来的
        //   （与 RasterCanvas 同一条注释，同一处坑）。
        $originX = $x + $this->offsetX;
        $originY = $y + $this->offsetY;

        $dpr = $this->scale->dpr;
        $pixelSize = $paragraph->style->size * $dpr;
        $argb = $paint->color->toArgb();

        foreach ($paragraph->lines as $line) {
            $baselineLogical = $originY + $line->baseline;
            $pen = $originX + $line->offsetX;

            foreach ($line->runs as $run) {
                $family = $run->face->familyName();
                $bold = $run->face->weight()->isBold();

                foreach ($run->glyphs as $glyph) {
                    // 与 `RasterCanvas::blendBitmap()` 同口径：字形原点量化到设备像素
                    // （§8.2 坑 4 的显式策略，不是权宜之计）
                    // ★ 字形的 offsetX/offsetY 在**逻辑空间**叠加后**一次**量化
                    //   （与 Raster 逐行对齐：Raster 的 blendBitmap 入参已是
                    //   `pen + offsetX`，round 在函数内做）。
                    //   ★ 踩过：第一版只发 `round($pen * $dpr)` —— offset 被丢。
                    //     今天所有生产者 offset 恒 0.0（grep 实证）⇒ 潜伏；
                    //     M4 真塑形（HarfBuzz 标记附加/字距调整）会活。
                    //     2026-10-17 修复，smoke ⑨+1 P4 钉住。
                    $penDevice = round(($pen + $glyph->offsetX) * $dpr);
                    $baselineDevice = round(($baselineLogical + $glyph->offsetY) * $dpr);

                    drift_skia_draw_glyph(
                        $this->handle,
                        $family,
                        $bold,
                        $pixelSize,
                        $penDevice,
                        $baselineDevice,
                        $glyph->codepoint,
                        $argb,
                    );

                    $pen += $glyph->advance;
                }
            }
        }
    }


    /**
     * P0：桥（`cpp-src/drift_skia.cc`）还没有椭圆/线段的原生入口 —— Core
     * 两 op 在 P1 落桥后这里换成真调用。在那之前同样**响亮失败**。
     */
    public function capabilities(): int
    {
        return 0;
    }

    public function drawEllipse(Rect $rect, Paint $paint): void
    {
        $this->unsupportedOp('drawEllipse', 'Core');
    }

    public function drawLine(float $x1, float $y1, float $x2, float $y2, Paint $paint): void
    {
        $this->unsupportedOp('drawLine', 'Core');
    }

    public function drawPath(Path $path, Paint $paint): void
    {
        $this->unsupportedOp('drawPath', 'Optional');
    }

    public function drawImage(ImageHandle $image, ?Rect $src, Rect $dst): void
    {
        $this->unsupportedOp('drawImage', 'Optional');
    }

    public function drawShadow(Shadow $shadow): void
    {
        $this->unsupportedOp('drawShadow', 'Optional');
    }

    public function pushLayer(float $opacity): void
    {
        $this->unsupportedOp('pushLayer', 'Optional');
    }

    public function popLayer(): void
    {
        $this->unsupportedOp('popLayer', 'Optional');
    }

    public function pushClipPath(Path $path): void
    {
        $this->unsupportedOp('pushClipPath', 'Extended');
    }

    private function unsupportedOp(string $op, string $tier): void
    {
        Errors::report(CanvasCapabilities::unsupportedMessage($op, 'SkiaCanvas', $tier));
    }
    /**
     * 字体未就绪时记一条 Diagnostic（只记一次）。
     *
     * ★ 必须可观测：字体加载失败的现象是"矩形全对、字全没了"，看起来像排版 bug。
     */
    private function warnIfFontMissing(): void
    {
        if ($this->fontWarned) {
            return;
        }
        $this->fontWarned = true;

        if (!drift_skia_font_ready()) {
            Errors::report(
                'Skia 后端找不到目录字体（NotoSansSC-Regular.ttf）⇒ 文字不绘制；'
                . '把 fonts/（或 cpp/fonts/）放到 exe 所在目录，或从仓库根启动',
                Errors::DIAGNOSTIC,
            );
        }
    }

    /** 逻辑 → 设备（§6.1 的唯一量化点） */
    private function toDevice(Rect $rect): Rect
    {
        return $this->scale->quantizeRect(
            new Rect(
                $rect->left + $this->offsetX,
                $rect->top + $this->offsetY,
                $rect->width,
                $rect->height,
            ),
        );
    }
}
