<?php
declare(strict_types=1);

namespace Drift\Text;

use Drift\Errors;

/**
 * 点阵字模的 `FontFace` 实现（确定性参考实现的一部分）。
 *
 * ★ **位图即 em 盒**：每个字形的行数 == `unitsPerEm`，行数内的墨迹位置就是它相对
 *   基线的位置（基线在自上而下第 `ascent` 行处）。于是 `rasterize()` 不需要任何
 *   bearing 推导 —— `bearingY = -ascent × pixelSize`，纵向定位一处算清。
 *
 * ★ **步进 = (位图宽 + 1) / unitsPerEm**，单位是 em 的倍数（§8.2 坑 3 的归一化）。
 *   宽度不一的字模（如 `…`）自然得到不同步进，不需要单独的 advance 表。
 *
 * @api
 */
final class BitmapFontFace implements FontFace
{
    /**
     * @param array<int, list<string>> $glyphs 码位 → 行位图（`#` 墨 / `.` 空）
     */
    public function __construct(
        private readonly string $family,
        private readonly FontWeight $weight,
        private readonly FontSlant $slant,
        private readonly FontMetrics $metrics,
        private readonly int $unitsPerEm,
        private readonly array $glyphs,
    ) {
        if ($unitsPerEm <= 0) {
            Errors::report('BitmapFontFace：unitsPerEm 必须为正，收到 ' . $unitsPerEm);
        }
    }

    /**
     * @param array<int, list<string>> $glyphs
     */
    public static function fromGlyphs(
        string $family,
        FontWeight $weight,
        FontSlant $slant,
        int $unitsPerEm,
        int $ascent,
        int $descent,
        int $lineGap,
        array $glyphs,
    ): self {
        return new self(
            $family,
            $weight,
            $slant,
            FontMetrics::fromUnits($ascent, $descent, $lineGap, $unitsPerEm),
            $unitsPerEm,
            $glyphs,
        );
    }

    public function familyName(): string
    {
        return $this->family;
    }

    public function weight(): FontWeight
    {
        return $this->weight;
    }

    public function slant(): FontSlant
    {
        return $this->slant;
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
        return isset($this->glyphs[$codepoint]);
    }

    public function hasColorGlyphs(): bool
    {
        return false;
    }

    public function glyphAdvance(int $codepoint): float
    {
        $rows = $this->glyphs[$codepoint] ?? null;
        if ($rows === null) {
            // 回退链本应先问 hasGlyph；走到这里说明选 face 的逻辑错了 —— 不静默
            Errors::report(
                sprintf('%s 未覆盖 U+%04X，步进按 0 计', $this->family, $codepoint),
                Errors::DIAGNOSTIC
            );
            return 0.0;
        }
        return (strlen($rows[0]) + 1) / $this->unitsPerEm;
    }

    public function rasterize(int $codepoint, float $pixelSize): ?GlyphBitmap
    {
        $rows = $this->glyphs[$codepoint] ?? null;
        if ($rows === null || $pixelSize <= 0.0) {
            return null;
        }

        $sourceHeight = count($rows);
        $sourceWidth = strlen($rows[0]);
        $scale = $pixelSize / $this->unitsPerEm;
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        // §2.4 纪律 2：循环里不许 .= 累加（增长累加器在多字节下会**堆损坏**，
        // 且崩在无关代码）⇒ 先落数组，出循环一次 implode。
        // ★ 故意用两个变量名（`$coverage` 保持 array、`$bitmap` 是 string）：
        //   同一个变量从 array 改判成 string 会让 AOT 的类型钉死自找麻烦。
        $coverage = [];

        if ($scale >= 1.0) {
            // 放大 / 1:1：最近邻（整数倍时逐像素复制，非整数倍时块状放大）
            for ($y = 0; $y < $height; $y++) {
                $row = $rows[min($sourceHeight - 1, (int) floor($y * $sourceHeight / $height))];
                for ($x = 0; $x < $width; $x++) {
                    $column = min($sourceWidth - 1, (int) floor($x * $sourceWidth / $width));
                    $coverage[] = $row[$column] === '#' ? "\xFF" : "\x00";
                }
            }
        } else {
            // ★ 降采样：**面积覆盖度平均 + 0.5 阈值**。
            //   最近邻降采样会**整行/整列丢笔画**（16→12 恰好丢 4/16 行），12px 的说明
            //   文字因此糊成一团（2026-10-16 由预览抓出）。覆盖度平均把"1px 细线"折算
            //   成 1/(16/12)=0.75 的覆盖度 ⇒ 过 0.5 阈值 ⇒ 保住笔画。
            //   ★ 放大路径完全不动 ⇒ 既有位图逐字节不变（零回归）。
            for ($y = 0; $y < $height; $y++) {
                $sy0 = $y * $sourceHeight / $height;
                $sy1 = ($y + 1) * $sourceHeight / $height;
                $syLast = (int) ceil($sy1);
                if ($syLast > $sourceHeight) {
                    $syLast = $sourceHeight;
                }
                for ($x = 0; $x < $width; $x++) {
                    $sx0 = $x * $sourceWidth / $width;
                    $sx1 = ($x + 1) * $sourceWidth / $width;
                    $sxLast = (int) ceil($sx1);
                    if ($sxLast > $sourceWidth) {
                        $sxLast = $sourceWidth;
                    }

                    $ink = 0.0;
                    for ($sy = (int) floor($sy0); $sy < $syLast; $sy++) {
                        $overY = min($sy1, $sy + 1.0) - max($sy0, (float) $sy);
                        if ($overY <= 0.0) {
                            continue;
                        }
                        $row = $rows[$sy];
                        for ($sx = (int) floor($sx0); $sx < $sxLast; $sx++) {
                            if ($row[$sx] !== '#') {
                                continue;
                            }
                            $overX = min($sx1, $sx + 1.0) - max($sx0, (float) $sx);
                            if ($overX > 0.0) {
                                $ink += $overX * $overY;
                            }
                        }
                    }

                    $area = ($sx1 - $sx0) * ($sy1 - $sy0);
                    $coverage[] = ($area > 0.0 && $ink >= $area * 0.5) ? "\xFF" : "\x00";
                }
            }
        }
        $bitmap = implode('', $coverage);

        return new GlyphBitmap(
            $width,
            $height,
            0,
            -(int) round($this->metrics->ascent * $pixelSize),
            $bitmap,
        );
    }
}
