<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Text\GlyphBitmap;
use Drift\Text\Paragraph;

/**
 * 软光栅画布：无头预览、CI、测试（§7.2 裁决 1）。
 *
 * ★ **无 OS 依赖**是它的全部价值 —— §14 确定性的物质基础：布局层零 OS 依赖
 *   ⇒ 可在 `php.exe` 里直接跑完整渲染，不编译、不开窗口。
 *
 * ★ 光栅顺序 = 录制顺序，且只依赖（命令流、裁剪、当前颜色）⇒ 同一份
 *   `DisplayList` 重放必然得到同一张位图（G3）。
 *
 * 覆盖能力：轴对齐矩形（填充 / 描边 / 圆角）、平移、矩形裁剪、source-over 混合、
 * **段落（字形位图按覆盖度混合）**。
 *
 * ★ 逻辑 → 物理只走 `Scale::quantizeRect()`（§6.1 的唯一量化点）。画布本身是
 *   **物理像素**空间，构造尺寸就是像素尺寸。
 *
 * @api
 */
final class RasterCanvas implements Canvas
{
    private string $pixels;
    private float $offsetX = 0.0;
    private float $offsetY = 0.0;
    private Rect $clip;
    private readonly Scale $scale;

    /** @var list<array{float, float}> 变换栈：只有偏移（裁剪独立成栈，与 SkiaCanvas 同口径） */
    private array $stack = [];

    /** @var list<Rect> 裁剪栈：与变换栈独立（两条栈分别配对，W8 分别计深） */
    private array $clipStack = [];

    /**
     * §13.5 的两个纯性能缓存（**不影响输出**——同输入同字节，golden 逐字节比对过）：
     *
     * 1. `opaqueChunks`：`r,g,b` → 四个预计算的单字节串。不透明矩形填充省掉
     *    每像素 4 次 `chr()` 调用（字符串偏移写在 refcount==1 时原地完成，O(1)）。
     * 2. `blendLuts`：`r,g,b,alpha` → 四条 256 项目标的预混合表。半透明填充从
     *    "每像素 8 次 `round()`" 变成 "每像素 4 次查表"。
     *
     * ★ 为什么只缓存"填充"不缓存"字形"：字形的 alpha 每像素都在变（覆盖度
     *   0..255），一个键只摊一个像素 ⇒ 建表比直接算还贵。字形仍走 `blend()`。
     * ★ 两个缓存都设上限、超限整体清零：它们是**纯性能缓存**，清了只回到慢，
     *   不会错（颜色键空间理论上开放，防的是"字号动画扫几千个 alpha"那种）。
     *
     * @var array<string, array<int, string>>
     */
    private array $opaqueChunks = [];

    /** @var array<string, array<int, array<int, string>>> */
    private array $blendLuts = [];

    private const CACHE_MAX = 128;

    public function __construct(
        private readonly int $width,
        private readonly int $height,
        ?Color $background = null,
        ?Scale $scale = null,
    ) {
        $this->pixels = str_repeat("\x00", $width * $height * 4);
        $this->clip = new Rect(0.0, 0.0, (float) $width, (float) $height);
        $this->scale = $scale ?? Scale::identity();

        if ($background !== null) {
            $this->clear($background);
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /** 原始 RGBA 字节流（行优先，每像素 4 字节） */
    public function toRgba(): string
    {
        return $this->pixels;
    }

    public function pixelAt(int $x, int $y): Color
    {
        $i = ($y * $this->width + $x) * 4;
        return new Color(
            ord($this->pixels[$i]),
            ord($this->pixels[$i + 1]),
            ord($this->pixels[$i + 2]),
            ord($this->pixels[$i + 3])
        );
    }

    public function clear(Color $color): void
    {
        $this->rasterize(
            new Rect(0.0, 0.0, (float) $this->width, (float) $this->height),
            0.0,
            $color,
            null,
            0.0
        );
    }

    public function save(): void
    {
        $this->stack[] = [$this->offsetX, $this->offsetY];
    }

    public function restore(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return; // 未配对的 restore 由 W8 在录制侧拦截，光栅侧静默忽略即可
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
        $this->clipStack[] = $this->clip;
        $this->clip = $this->clip->intersect($this->toDevice($rect));
    }

    public function popClip(): void
    {
        $previous = array_pop($this->clipStack);
        if ($previous === null) {
            return; // 未配对的 popClip 由 W8 在录制侧拦截，光栅侧静默忽略即可
        }
        $this->clip = $previous;
    }

    public function drawRect(Rect $rect, Paint $paint, float $radius = 0.0): void
    {
        $device = $this->toDevice($rect);

        if (!$paint->isStroke()) {
            $this->rasterize($device, $radius, $paint->color, null, 0.0);
            return;
        }

        // 描边 = 外圈减去内圈：一个循环搞定，圆角自然跟随
        $w = $paint->strokeWidth;
        $hole = new Rect(
            $device->left + $w,
            $device->top + $w,
            $device->width - 2.0 * $w,
            $device->height - 2.0 * $w
        );
        $this->rasterize($device, $radius, $paint->color, $hole, max(0.0, $radius - $w));
    }

    /**
     * 段落绘制：把已排好的字形逐个光栅上屏。
     *
     * ★ 字形位图在**这里**才产生（录制时只存 `Paragraph` 引用）—— 于是同一份
     *   `DisplayList` 在 Skia 后端能用真字体重画，在软光栅这里用 face 的位图，
     *   两侧共用同一套步进与基线（§14.2）。
     *
     * ★ 没有位图的字形**不画**（`rasterize()` 返回 `null`）—— 不是画一个方框顶上。
     */
    public function drawParagraph(Paragraph $paragraph, Paint $paint, float $x = 0.0, float $y = 0.0): void
    {
        if ($paint->color->a === 0) {
            return;
        }

        // ★ 文本**必须**吃画布变换：`$x/$y` 是逻辑坐标，父盒给的位置是靠
        //   `translate()` 传进来的（`RenderProxyBox::paint` 就是这么把子盒偏移
        //   递下去的）。不加上当前偏移 ⇒ `Center` 算好的居中偏移画不出来，
        //   文字永远贴在左上角 —— 而 `drawRect` 走 `toDevice()` 是对的，于是
        //   "矩形跟着变换、文字不跟"这种半边错的状态最难发现。
        $originX = $x + $this->offsetX;
        $originY = $y + $this->offsetY;

        // 字形光栅在**设备分辨率**上做（§8.2 坑 2）；步进仍是逻辑单位
        $pixelSize = $paragraph->style->size * $this->scale->dpr;

        foreach ($paragraph->lines as $line) {
            $baseline = $originY + $line->baseline;
            $pen = $originX + $line->offsetX;

            foreach ($line->runs as $run) {
                foreach ($run->glyphs as $glyph) {
                    $bitmap = $run->face->rasterize($glyph->codepoint, $pixelSize);
                    if ($bitmap !== null) {
                        $this->blendBitmap($bitmap, $paint->color, $pen + $glyph->offsetX, $baseline + $glyph->offsetY);
                    }
                    $pen += $glyph->advance;
                }
            }
        }
    }

    private function toDevice(Rect $rect): Rect
    {
        return $this->scale->quantizeRect(
            new Rect($rect->left + $this->offsetX, $rect->top + $this->offsetY, $rect->width, $rect->height)
        );
    }

    /** 覆盖位图按 source-over 混合（覆盖率即 alpha） */
    private function blendBitmap(GlyphBitmap $bitmap, Color $color, float $penX, float $baselineY): void
    {
        // 位图已在设备像素空间：`bearingX/Y` 是相对笔位的偏移
        $left = (int) round($penX * $this->scale->dpr) + $bitmap->bearingX;
        $top = (int) round($baselineY * $this->scale->dpr) + $bitmap->bearingY;

        $clipLeft = (int) $this->clip->left;
        $clipRight = (int) $this->clip->right();
        $clipTop = (int) $this->clip->top;
        $clipBottom = (int) $this->clip->bottom();

        for ($by = 0; $by < $bitmap->height; $by++) {
            $y = $top + $by;
            if ($y < 0 || $y >= $this->height || $y < $clipTop || $y >= $clipBottom) {
                continue;
            }
            for ($bx = 0; $bx < $bitmap->width; $bx++) {
                $x = $left + $bx;
                if ($x < 0 || $x >= $this->width || $x < $clipLeft || $x >= $clipRight) {
                    continue;
                }
                $coverage = $bitmap->coverageAt($bx, $by);
                if ($coverage === 0) {
                    continue;
                }
                $this->blend($x, $y, $color, $coverage);
            }
        }
    }

    private function rasterize(Rect $rect, float $radius, Color $color, ?Rect $hole, float $holeRadius): void
    {
        if ($color->a === 0) {
            return;
        }

        // ★ 全部区间换算成**整数像素**，与旧的"每像素判谓词"逐点等价：
        //   · `insideRoundRect` 的矩形门是**两端含**（`px >= left && px <= right`）
        //     ⇒ 区间 [ceil(left-0.5), floor(right-0.5)+1)
        //   · `clip` 是**左闭右开**（`px >= left && px < right`）
        //     ⇒ 区间 [ceil(left-0.5), ceil(right-0.5))
        //   px = x + 0.5 是像素中心。含等号的一侧在 .5 恰好对齐时也一致。
        $rx0 = (int) ceil($rect->left - 0.5);
        $rx1 = (int) floor($rect->right() - 0.5) + 1;
        $ry0 = (int) ceil($rect->top - 0.5);
        $ry1 = (int) floor($rect->bottom() - 0.5) + 1;

        $xa = max($rx0, (int) ceil($this->clip->left - 0.5), 0);
        $xb = min($rx1, (int) ceil($this->clip->right() - 0.5), $this->width);
        $ya = max($ry0, (int) ceil($this->clip->top - 0.5), 0);
        $yb = min($ry1, (int) ceil($this->clip->bottom() - 0.5), $this->height);
        if ($xa >= $xb || $ya >= $yb) {
            return;
        }

        $opaque = $color->a >= 255;
        $lut = $opaque ? null : $this->blendLut($color);

        for ($y = $ya; $y < $yb; $y++) {
            $py = $y + 0.5;

            // 圆角的行内区间：中部行整段在内；上下边带行从两端向内扫
            // （谓词仍走 insideRoundRect 逐点判 ⇒ 与旧实现同一个字节；
            //   扫描最多 radius 个像素，远小于整行宽度）。
            $x0 = $xa;
            $x1 = $xb;
            if ($radius > 0.0 && ($py < $rect->top + $radius || $py > $rect->bottom() - $radius)) {
                while ($x0 < $x1 && !$this->insideRoundRect($x0 + 0.5, $py, $rect, $radius)) {
                    $x0++;
                }
                while ($x1 > $x0 && !$this->insideRoundRect($x1 - 0.5, $py, $rect, $radius)) {
                    $x1--;
                }
            }
            if ($x0 >= $x1) {
                continue;
            }

            // 描边的洞：洞自己的行区间（同样两端含）∩ 墨区间，圆角时只在
            // 上下边带行向内扫；挖掉洞后剩两段，各自写。
            if ($hole !== null) {
                $h0 = max($x0, (int) ceil($hole->left - 0.5));
                $h1 = min($x1, (int) floor($hole->right() - 0.5) + 1);
                if ($h0 < $h1 && $holeRadius > 0.0
                    && ($py < $hole->top + $holeRadius || $py > $hole->bottom() - $holeRadius)
                ) {
                    while ($h0 < $h1 && !$this->insideRoundRect($h0 + 0.5, $py, $hole, $holeRadius)) {
                        $h0++;
                    }
                    while ($h1 > $h0 && !$this->insideRoundRect($h1 - 0.5, $py, $hole, $holeRadius)) {
                        $h1--;
                    }
                }
                if ($h0 < $h1) {
                    $this->fillSpan($y, $x0, $h0, $color, $opaque, $lut);
                    $this->fillSpan($y, $h1, $x1, $color, $opaque, $lut);
                } else {
                    // 本行完全在洞里（或洞比墨区大）⇒ 整行都是墨
                    $this->fillSpan($y, $x0, $x1, $color, $opaque, $lut);
                }
                continue;
            }

            $this->fillSpan($y, $x0, $x1, $color, $opaque, $lut);
        }
    }

    /** 写一段水平像素 run（同一行、同一颜色；opaque 逐像素原地写，半透明查表逐像素） */
    private function fillSpan(int $y, int $xa, int $xb, Color $color, bool $opaque, ?array $lut): void
    {
        if ($xa >= $xb) {
            return;
        }

        // 不透明：预计算四个单字节串，逐像素原地写（字符串偏移写在 refcount==1 时
        // 是 in-place 的 O(1)）。★ 试过"整行一次 substr_replace"—— 它对整块像素串
        // 是 O(整串) 拷贝（900×640×4 = 2.3MB/行）⇒ 全幅矩形反而慢一倍，已回退。
        if ($opaque) {
            $key = $color->r . ',' . $color->g . ',' . $color->b;
            $chunk = $this->opaqueChunks[$key] ?? null;
            if ($chunk === null) {
                if (count($this->opaqueChunks) >= self::CACHE_MAX) {
                    $this->opaqueChunks = [];
                }
                $chunk = [chr($color->r), chr($color->g), chr($color->b), "\xFF"];
                $this->opaqueChunks[$key] = $chunk;
            }
            [$c0, $c1, $c2, $c3] = $chunk;

            $base = $y * $this->width;
            for ($x = $xa; $x < $xb; $x++) {
                $i = ($base + $x) * 4;
                $this->pixels[$i] = $c0;
                $this->pixels[$i + 1] = $c1;
                $this->pixels[$i + 2] = $c2;
                $this->pixels[$i + 3] = $c3;
            }

            return;
        }

        // 半透明：目标字节预混合表（alpha 在本 span 内恒定 ⇒ 每像素 4 次查表）
        $base = $y * $this->width;
        for ($x = $xa; $x < $xb; $x++) {
            $i = ($base + $x) * 4;
            $this->pixels[$i] = $lut[0][ord($this->pixels[$i])];
            $this->pixels[$i + 1] = $lut[1][ord($this->pixels[$i + 1])];
            $this->pixels[$i + 2] = $lut[2][ord($this->pixels[$i + 2])];
            $this->pixels[$i + 3] = $lut[3][ord($this->pixels[$i + 3])];
        }
    }

    /**
     * 半透明填充的预混合表：四条 256 项目标字节。
     *
     * 公式与 `blend()` 逐字节相同（`(int) round((c*alpha + old*(255-alpha)) / 255)`）
     * ⇒ 输出与旧逐像素实现完全一致。
     *
     * @return array<int, array<int, string>>
     */
    private function blendLut(Color $color): array
    {
        $key = $color->r . ',' . $color->g . ',' . $color->b . ',' . $color->a;
        $lut = $this->blendLuts[$key] ?? null;
        if ($lut !== null) {
            return $lut;
        }

        $inverse = 255 - $color->a;
        $tables = [];
        foreach ([$color->r, $color->g, $color->b] as $channel) {
            $table = [];
            for ($old = 0; $old < 256; $old++) {
                $table[$old] = chr((int) round(($channel * $color->a + $old * $inverse) / 255));
            }
            $tables[] = $table;
        }
        // alpha 通道：dst_a = min(255, alpha + round(old_a * inverse / 255))
        $alphaTable = [];
        for ($old = 0; $old < 256; $old++) {
            $alphaTable[$old] = chr(min(255, $color->a + (int) round($old * $inverse / 255)));
        }
        $tables[] = $alphaTable;

        if (count($this->blendLuts) >= self::CACHE_MAX) {
            $this->blendLuts = [];
        }
        $this->blendLuts[$key] = $tables;

        return $tables;
    }

    private function insideRoundRect(float $px, float $py, Rect $rect, float $radius): bool
    {
        if ($px < $rect->left || $px > $rect->right() || $py < $rect->top || $py > $rect->bottom()) {
            return false;
        }
        if ($radius <= 0.0) {
            return true;
        }

        $radius = min($radius, min($rect->width, $rect->height) / 2.0);
        $cx = min(max($px, $rect->left + $radius), $rect->right() - $radius);
        $cy = min(max($py, $rect->top + $radius), $rect->bottom() - $radius);
        $dx = $px - $cx;
        $dy = $py - $cy;

        return $dx * $dx + $dy * $dy <= $radius * $radius;
    }

    /**
     * source-over 混合。
     *
     * `$coverage` 是字形位图的覆盖度（0..255）：`alpha = 颜色 alpha × 覆盖率`，
     * 于是**抗锯齿的边缘**与"半透明色"走同一条混合路径，不另开一条。
     */
    private function blend(int $x, int $y, Color $color, int $coverage = 255): void
    {
        $i = ($y * $this->width + $x) * 4;
        $alpha = (int) round($color->a * $coverage / 255);
        if ($alpha <= 0) {
            return;
        }

        if ($alpha === 255) {
            $this->pixels[$i] = chr($color->r);
            $this->pixels[$i + 1] = chr($color->g);
            $this->pixels[$i + 2] = chr($color->b);
            $this->pixels[$i + 3] = "\xFF";
            return;
        }

        $inverse = 255 - $alpha;
        $this->pixels[$i] = chr((int) round(($color->r * $alpha + ord($this->pixels[$i]) * $inverse) / 255));
        $this->pixels[$i + 1] = chr((int) round(($color->g * $alpha + ord($this->pixels[$i + 1]) * $inverse) / 255));
        $this->pixels[$i + 2] = chr((int) round(($color->b * $alpha + ord($this->pixels[$i + 2]) * $inverse) / 255));
        $this->pixels[$i + 3] = chr(min(255, $alpha + (int) round(ord($this->pixels[$i + 3]) * $inverse / 255)));
    }
}
