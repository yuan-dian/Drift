<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 归一化字体度量（§8.2 坑 3）。
 *
 * ★ **归一化**是关键词：各平台 / 各 face 的 `ascent` / `descent` / `lineGap` /
 *   `unitsPerEm` 单位都不同，这里统一折成 **em 的倍数**，于是"1 个单位 = 1 em"
 *   在任何字体上都成立。原始整数值留在 `fromUnits()` 的入参里。
 *
 * @api
 */
final readonly class FontMetrics
{
    public function __construct(
        public float $ascent,   // em 的倍数，正数（基线以上）
        public float $descent,  // em 的倍数，正数（基线以下）
        public float $lineGap,  // em 的倍数
    ) {
    }

    public static function fromUnits(int $ascent, int $descent, int $lineGap, int $unitsPerEm): self
    {
        $em = $unitsPerEm > 0 ? (float) $unitsPerEm : 1.0;
        return new self($ascent / $em, $descent / $em, $lineGap / $em);
    }

    public function ascentPx(float $size): float
    {
        return $this->ascent * $size;
    }

    public function descentPx(float $size): float
    {
        return $this->descent * $size;
    }

    /**
     * 行高 = `lineGap + (ascent + descent) × lineHeight`（§8.2 坑 3）。
     *
     * ★ **策略显式**：不隐式信任平台默认行距，倍数由 `TextStyle::$lineHeight` 给。
     */
    public function lineHeight(float $size, float $lineHeightFactor): float
    {
        return ($this->lineGap + ($this->ascent + $this->descent) * $lineHeightFactor) * $size;
    }

    /** 从行顶到基线的距离 */
    public function baselineOffset(float $size, float $lineHeightFactor): float
    {
        return $this->lineHeight($size, $lineHeightFactor) / 2.0
            + ($this->ascent - $this->descent) * $size / 2.0;
    }

    public function equals(FontMetrics $other): bool
    {
        return $this->ascent === $other->ascent
            && $this->descent === $other->descent
            && $this->lineGap === $other->lineGap;
    }
}
