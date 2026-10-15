<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 塑形的确定性实现（§8.1 第三层）。
 *
 * ★ 一段文本按**回退链换 face** 切成多个 run（拉丁 → CJK），每 run 一个 face ——
 *   这就是 §8.2 坑 5 在塑形侧的落点。
 *
 * ★ **设备分辨率塑形**（§8.2 坑 4）：步进先乘 dpr 取整再除回来，于是字形原点落在
 *   设备像素上、横向不再有半像素累积误差；返回值仍是**逻辑单位**，取整只发生在
 *   表面量化那一个点（§6.1）。
 *
 * @api
 */
final class DeterministicShaper implements TextShaper
{
    public function shape(string $text, TextStyle $style, FontCollection $collection, float $scale): array
    {
        $device = $scale > 0.0 ? $scale : 1.0;

        $runs = [];
        $face = null;
        $glyphs = [];
        $start = 0;
        $length = 0;

        foreach (Utf8::decode($text) as [$codepoint, $offset, $size]) {
            $current = $collection->faceFor($codepoint, $style->family, $style->weight, $style->slant);
            if ($face !== $current) {
                if ($face !== null) {
                    $runs[] = $this->run($glyphs, $face, $style, $start, $length);
                }
                $face = $current;
                $glyphs = [];
                $start = $offset;
                $length = 0;
            }

            $advance = round($current->glyphAdvance($codepoint) * $style->size * $device) / $device;
            $glyphs[] = new Glyph($codepoint, $advance, 0.0, 0.0, $offset);
            $length += $size;
        }

        if ($face !== null) {
            $runs[] = $this->run($glyphs, $face, $style, $start, $length);
        }

        return $runs;
    }

    /**
     * @param list<Glyph> $glyphs
     */
    private function run(array $glyphs, FontFace $face, TextStyle $style, int $start, int $length): GlyphRun
    {
        $width = 0.0;
        foreach ($glyphs as $glyph) {
            $width += $glyph->advance + $style->letterSpacing;
        }
        return new GlyphRun($glyphs, $face, $style, $width, $start, $length);
    }
}
