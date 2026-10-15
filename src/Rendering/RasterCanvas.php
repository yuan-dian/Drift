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

    /** @var list<array{float, float, Rect}> 状态栈：偏移 + 裁剪 */
    private array $stack = [];

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
        $this->stack[] = [$this->offsetX, $this->offsetY, $this->clip];
    }

    public function restore(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return; // 未配对的 restore 由 W8 在录制侧拦截，光栅侧静默忽略即可
        }
        [$this->offsetX, $this->offsetY, $this->clip] = $frame;
    }

    public function translate(float $dx, float $dy): void
    {
        $this->offsetX += $dx;
        $this->offsetY += $dy;
    }

    public function pushClip(Rect $rect): void
    {
        $device = $this->toDevice($rect);
        $this->clip = $this->clip->intersect($device);
    }

    public function popClip(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return;
        }
        [$this->offsetX, $this->offsetY, $this->clip] = $frame;
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

        // 字形光栅在**设备分辨率**上做（§8.2 坑 2）；步进仍是逻辑单位
        $pixelSize = $paragraph->style->size * $this->scale->dpr;

        foreach ($paragraph->lines as $line) {
            $baseline = $y + $line->baseline;
            $pen = $x + $line->offsetX;

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

        $x0 = max((int) floor($rect->left), 0);
        $y0 = max((int) floor($rect->top), 0);
        $x1 = min((int) ceil($rect->right()), $this->width);
        $y1 = min((int) ceil($rect->bottom()), $this->height);
        $clipRight = $this->clip->right();
        $clipBottom = $this->clip->bottom();

        for ($y = $y0; $y < $y1; $y++) {
            $py = $y + 0.5;
            if ($py < $this->clip->top || $py >= $clipBottom) {
                continue;
            }
            for ($x = $x0; $x < $x1; $x++) {
                $px = $x + 0.5;
                if ($px < $this->clip->left || $px >= $clipRight) {
                    continue;
                }
                if ($radius > 0.0 && !$this->insideRoundRect($px, $py, $rect, $radius)) {
                    continue;
                }
                if ($hole !== null && $this->insideRoundRect($px, $py, $hole, $holeRadius)) {
                    continue;
                }
                $this->blend($x, $y, $color);
            }
        }
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
