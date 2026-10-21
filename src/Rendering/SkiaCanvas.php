<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Rendering\DashPattern;
use Drift\Rendering\Gradient;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\Path;
use Drift\Rendering\Shadow;
use Drift\Text\Paragraph;

use function drift_skia_canvas_create;
use function drift_skia_canvas_destroy;
use function drift_skia_clear_source;
use function drift_skia_draw_glyph;
use function drift_skia_draw_image;
use function drift_skia_draw_path;
use function drift_skia_draw_rect;
use function drift_skia_draw_shadow;
use function drift_skia_font_ready;
use function drift_skia_pop_clip;
use function drift_skia_pop_layer;
use function drift_skia_push_clip;
use function drift_skia_push_clip_path;
use function drift_skia_push_layer;
use function drift_skia_read_pixels;
use function drift_skia_set_dash;
use function drift_skia_set_gradient;

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

    /** P3：当前是否真挂着渐变/虚线源（只有挂了才发 clear_source，免得每次 draw 多一次桥调用） */
    private bool $sourceActive = false;

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

        $this->applyPaintSource($paint);
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
        $this->clearPaintSource();
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


    /** Core 两 op + P3 渐变/虚线 + P4 Optional（PATH/IMAGE/SHADOW/LAYER）+ Extended CLIP_PATH。 */
    public function capabilities(): int
    {
        return CanvasCapabilities::CAP_ELLIPSE | CanvasCapabilities::CAP_LINE
            | CanvasCapabilities::CAP_GRADIENT | CanvasCapabilities::CAP_DASH
            | CanvasCapabilities::CAP_PATH | CanvasCapabilities::CAP_IMAGE
            | CanvasCapabilities::CAP_SHADOW | CanvasCapabilities::CAP_LAYER
            | CanvasCapabilities::CAP_CLIP_PATH;
    }

    /**
     * 椭圆：`draw_rect` 的同族调用（桥收到设备坐标；宽度不乘 dpr）。
     * Skia 的 `drawOval` 描边居中 —— 与 `RasterCanvas::drawEllipse` 的
     * "外减内收半宽"同口径（都是居中），两后端可互做 conformance。
     */
    public function drawEllipse(Rect $rect, Paint $paint): void
    {
        if ($this->handle === 0) {
            return;
        }
        $device = $this->toDevice($rect);
        if ($device->width <= 0.0 || $device->height <= 0.0) {
            return;
        }

        $stroke = $paint->isStroke();
        $width = $paint->strokeWidth;
        $argb = $paint->color->toArgb();

        $this->applyPaintSource($paint);
        drift_skia_draw_ellipse(
            $this->handle,
            $device->left,
            $device->top,
            $device->width,
            $device->height,
            $argb,
            $stroke,
            $width,
        );
        $this->clearPaintSource();
    }

    /**
     * 线段：端点叠加当前偏移后一次量化（`drawParagraph` 笔位同口径），
     * 宽度不乘 dpr（`draw_rect` 同款口径）。只支持描边（桥只发 stroke）。
     */
    public function drawLine(float $x1, float $y1, float $x2, float $y2, Paint $paint): void
    {
        if ($this->handle === 0) {
            return;
        }
        CanvasCapabilities::assertStrokePaint($paint, 'drawLine');

        $dpr = $this->scale->dpr;

        $this->applyPaintSource($paint);
        drift_skia_draw_line(
            $this->handle,
            round(($x1 + $this->offsetX) * $dpr),
            round(($y1 + $this->offsetY) * $dpr),
            round(($x2 + $this->offsetX) * $dpr),
            round(($y2 + $this->offsetY) * $dpr),
            $paint->color->toArgb(),
            $paint->strokeWidth,
        );
        $this->clearPaintSource();
    }

    /**
     * 矢量路径填充 / 描边（P4 · Optional）。几何在 `packPath()` 里叠加当前偏移并
     * 乘 dpr（与 `drawLine` / `toDevice()` 同一变换口径）；渐变 / 虚线走画布级
     * source（与 `draw_rect` 同纪律）。
     *
     * ★★ 这里**没有**画布级 translate：`translate()` 只改 PHP 侧 `offsetX/offsetY`，
     *   每个 draw 自己把偏移加进坐标（见 `drawLine` / `toDevice`）。第一版照
     *   "几何走画布的 translate()" 的注释写，路径就落在画布原点 ⇒ 被自绘盒裁掉
     *   ⇒ 真机完全无墨（阴影/层/图像都正常，因为它们各自加了偏移）。
     */
    public function drawPath(Path $path, Paint $paint): void
    {
        if ($this->handle === 0) {
            return;
        }
        if ($path->isEmpty()) {
            return;
        }

        [$count, $verbs, $coords] = $this->packPath($path);
        $argb = $paint->color->toArgb();

        $this->applyPaintSource($paint);
        drift_skia_draw_path(
            $this->handle,
            $count,
            $verbs,
            $coords,
            $argb,
            $paint->isStroke(),
            $paint->strokeWidth,
        );
        $this->clearPaintSource();
    }

    /**
     * 图像（P4 · Optional）。`$dst` 逻辑矩形走 `toDevice()`；`$src` 是图像自身像素
     * 空间的子矩形，不与画布变换叠加 —— 只缩放进 `$dst`，缩放由 C++ 侧消化。
     */
    public function drawImage(ImageHandle $image, ?Rect $src, Rect $dst): void
    {
        if ($this->handle === 0) {
            return;
        }
        if ($image->isEmpty()) {
            return;
        }

        $device = $this->toDevice($dst);
        $hasSrc = $src !== null;
        if ($hasSrc) {
            $sx = $src->left;
            $sy = $src->top;
            $sw = $src->width;
            $sh = $src->height;
        } else {
            $sx = $sy = $sw = $sh = 0.0;
        }

        drift_skia_draw_image(
            $this->handle,
            $image->rgba(),
            $image->width,
            $image->height,
            $sx, $sy, $sw, $sh,
            $device->left, $device->top, $device->width, $device->height,
            $hasSrc,
        );
    }

    /**
     * 阴影（P4 · Optional）。被投影矩形走 `toDevice()`；偏移 / 圆角 / 模糊是长度量
     * ⇒ 只缩放不平移（与渐变半径同纪律），统一乘 dpr 落到设备空间。
     */
    public function drawShadow(Shadow $shadow): void
    {
        if ($this->handle === 0) {
            return;
        }
        $dpr = $this->scale->dpr;
        $device = $this->toDevice($shadow->rect);

        drift_skia_draw_shadow(
            $this->handle,
            $device->left, $device->top, $device->width, $device->height,
            $shadow->color->toArgb(),
            $shadow->cornerRadius * $dpr,
            $shadow->dx * $dpr,
            $shadow->dy * $dpr,
            $shadow->blur * $dpr,
        );
    }

    /** 压入合成层（P4 · Optional）；不透明度无量纲，不与变换叠加 */
    public function pushLayer(float $opacity): void
    {
        if ($this->handle === 0) {
            return;
        }
        drift_skia_push_layer($this->handle, $opacity);
    }

    /** 弹出合成层（P4 · Optional） */
    public function popLayer(): void
    {
        if ($this->handle === 0) {
            return;
        }
        drift_skia_pop_layer($this->handle);
    }

    /**
     * 压入路径裁剪（P4 · Extended）。复用裁剪栈（弹栈仍用 `popClip()`）；几何与
     * `drawPath` 同一条打包路径（偏移 + dpr 在 `packPath()` 里一次施加）。
     */
    public function pushClipPath(Path $path): void
    {
        if ($this->handle === 0) {
            return;
        }
        if ($path->isEmpty()) {
            return;
        }
        [$count, $verbs, $coords] = $this->packPath($path);
        drift_skia_push_clip_path($this->handle, $count, $verbs, $coords);
    }

    /**
     * P4 · 把 Path 的扁平命令表打包成过桥字节流（与 `drift_skia.stub.php` 约定一致）：
     * `verbs` 每命令 1 字节（MOVE/LINE/QUAD/CLOSE），`coords` 每命令 4 个
     * little-endian IEEE754 float（x1,y1,x2,y2）。桥 ABI 只认标量 + string，不引数组。
     *
     * ★★ 坐标在这里**一次**施加 `(逻辑 + 偏移) × dpr` 再量化 —— 与 `drawLine` 同口径。
     *   漏了这一步路径会落在画布原点（被盒裁掉 ⇒ 无墨），而不是"位置偏一点"。
     * ★★ 必须"数组攒 + implode"，不能 `$verbs .= …`：这两个串是**二进制**（含 \0 与
     *   高位字节），AOT 下循环内累加拼接会写坏多字节串 ⇒ C++ 侧读到乱码 verb ⇒
     *   路径为空 ⇒ 真机**完全无墨**而解释器（smoke）全过（§2.4 纪律 2 的实证案例）。
     */
    private function packPath(Path $path): array
    {
        $cmds = $path->commands();
        $dpr = $this->scale->dpr;
        $ox = $this->offsetX;
        $oy = $this->offsetY;
        $verbParts = [];
        $coordParts = [];
        foreach ($cmds as $c) {
            $verb = (int) $c[0];
            $verbParts[] = \chr($verb);
            if ($verb === Path::CMD_QUAD) {
                $coordParts[] = \pack(
                    'f4',
                    round(($c[1] + $ox) * $dpr),
                    round(($c[2] + $oy) * $dpr),
                    round(($c[3] + $ox) * $dpr),
                    round(($c[4] + $oy) * $dpr),
                );
            } elseif ($verb === Path::CMD_MOVE || $verb === Path::CMD_LINE) {
                $coordParts[] = \pack(
                    'f4',
                    round(($c[1] + $ox) * $dpr),
                    round(($c[2] + $oy) * $dpr),
                    0.0,
                    0.0,
                );
            } else {
                // CLOSE 不带参数（四槽留 0，与 `Path` 的命令表格式一致）
                $coordParts[] = \pack('f4', 0.0, 0.0, 0.0, 0.0);
            }
        }
        return [\count($cmds), \implode('', $verbParts), \implode('', $coordParts)];
    }

    /**
     * P3 · 把当前 Paint 的渐变/虚线源写到 Skia 画布（设备坐标），供紧接着的 draw 调用消费。
     *
     * ★ 渐变端点/圆心是**逻辑坐标**（与形状同空间），转设备空间 = (logical + offset) × dpr
     *   （与 `toDevice()` 同一变换）；径向半径 x2 是长度量 ⇒ 只缩放不平移。
     */
    private function applyPaintSource(Paint $paint): void
    {
        $dpr = $this->scale->dpr;
        $g = $paint->gradient;
        if ($g !== null) {
            if ($g->isRadial()) {
                $gx1 = ($g->x1 + $this->offsetX) * $dpr;
                $gy1 = ($g->y1 + $this->offsetY) * $dpr;
                $gx2 = $g->x2 * $dpr; // 半径：长度量，只缩放
                $gy2 = 0.0;
            } else {
                $gx1 = ($g->x1 + $this->offsetX) * $dpr;
                $gy1 = ($g->y1 + $this->offsetY) * $dpr;
                $gx2 = ($g->x2 + $this->offsetX) * $dpr;
                $gy2 = ($g->y2 + $this->offsetY) * $dpr;
            }
            [$n, $colors, $stops] = $this->packGradient($g);
            drift_skia_set_gradient(
                $this->handle,
                $g->isRadial() ? 1 : 0,
                $gx1, $gy1, $gx2, $gy2,
                $n, $colors[0], $colors[1], $colors[2], $colors[3],
                $stops[0], $stops[1], $stops[2], $stops[3],
            );
            $this->sourceActive = true;
        }
        $dash = $paint->dash;
        if ($dash !== null) {
            // ★ DashPattern 的 on/off/phase 是**逻辑像素**（与 RasterCanvas::drawLine 同口径：
            //   Raster 用 `sqrt(len2) / dpr` 把弧长换算回逻辑单位再取模）。这里喂的是设备
            //   空间 ⇒ 必须乘 dpr，否则 dpr=2 时虚线视觉上只剩一半长，两个后端对不上。
            drift_skia_set_dash($this->handle, $dash->on * $dpr, $dash->off * $dpr, $dash->phase * $dpr);
            $this->sourceActive = true;
        }
    }

    /** 渐变打包成桥要的 4 色标 / 4 位置（空位置按均匀分布补，长度量升序） */
    private function packGradient(Gradient $g): array
    {
        $src = $g->colors;
        $srcStops = $g->stops;
        $n = count($src);
        $colors = [0, 0, 0, 0];
        $stops = [0.0, 0.0, 0.0, 0.0];
        for ($i = 0; $i < 4; $i++) {
            // ★ 补位槽一律 clamp 到最后一个真实色标：直接按 i/(n-1) 算会在 n<4 时
            //   补出 2.0、3.0 这种越界位置（Skia 色标位置必须落在 0..1，越界的表现
            //   是**静默错色**而不是报错 —— 只能靠"补位不许越界"这条判据守住）。
            $k = min($i, $n - 1);
            $colors[$i] = $src[$k]->toArgb();
            $stops[$i] = $srcStops === [] ? ($n > 1 ? (float) ($k / ($n - 1)) : 0.0) : $srcStops[$k];
        }
        return [$n, $colors, $stops];
    }

    private function clearPaintSource(): void
    {
        if (!$this->sourceActive) {
            return;
        }
        drift_skia_clear_source($this->handle);
        $this->sourceActive = false;
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
