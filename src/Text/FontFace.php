<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 一个可用的字体面（§8.1 第一层：`FontManager` 注册的实体）。
 *
 * ★ **度量归一化**：`glyphAdvance()` 一律返回 **em 的倍数**，`metrics()` 同理 ——
 *   于是"1 个单位 = 1 em"在任何字体上都成立，上层不必知道 `unitsPerEm`。
 *
 * @api extension
 */
interface FontFace
{
    public function familyName(): string;

    public function weight(): FontWeight;

    public function slant(): FontSlant;

    /** 归一化度量（em 的倍数） */
    public function metrics(): FontMetrics;

    public function unitsPerEm(): int;

    public function hasGlyph(int $codepoint): bool;

    /** 步进宽度，**em 的倍数** */
    public function glyphAdvance(int $codepoint): float;

    /** 光栅成覆盖位图；无此字形 ⇒ null（不静默画方框） */
    public function rasterize(int $codepoint, float $pixelSize): ?GlyphBitmap;
}
