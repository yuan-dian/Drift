<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 兜底 face（`.notdef` 的等价物）：**没有任何字形**，但必须给出确定性步进。
 *
 * ★ 它存在的原因是 §8.2 坑 5 的回退链需要一个终点：链上都不含某个码位时，
 *   `FontCollection::faceFor()` 返回它 ⇒ **布局不会因为缺字体而塌成零宽**，
 *   但 `rasterize()` 返回 `null` ⇒ 画不出墨（不静默画方框）。
 *   "缺字形"与"没有位图"因此是两件事，各自有确定答案。
 *
 * ★ 步进按**宽度类**给：CJK / 全角 / emoji 一个 em，其余半个 em，控制字符零宽。
 *
 * @api
 */
final class NotdefFontFace implements FontFace
{
    public function __construct(
        private readonly FontMetrics $metrics = new FontMetrics(0.875, 0.125, 0.0),
        private readonly int $unitsPerEm = 16,
    ) {
    }

    public function familyName(): string
    {
        return 'notdef';
    }

    public function weight(): FontWeight
    {
        return FontWeight::Regular;
    }

    public function slant(): FontSlant
    {
        return FontSlant::Normal;
    }

    public function metrics(): FontMetrics
    {
        return $this->metrics;
    }

    public function unitsPerEm(): int
    {
        return $this->unitsPerEm;
    }

    public function hasGlyph(int $codepoint): bool
    {
        return false;
    }

    public function hasColorGlyphs(): bool
    {
        return false;
    }

    public function glyphAdvance(int $codepoint): float
    {
        if ($codepoint < 0x20 || $codepoint === 0x7F) {
            return 0.0; // 控制字符（含换行）不占宽度
        }
        return Utf8::isWide($codepoint) ? 1.0 : 0.5;
    }

    public function rasterize(int $codepoint, float $pixelSize): ?GlyphBitmap
    {
        return null;
    }
}
