<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 一个字形的覆盖位图（`FontFace::rasterize()` 的产物）。
 *
 * `coverage` 是 `width × height` 字节，行优先，取值 0..255 —— 单通道覆盖度，
 * 颜色由调用方决定。这正好把"字形长什么样"与"画成什么颜色"分开，
 * 也让软光栅与 Skia 走同一种中间表示。
 *
 * @api
 */
final readonly class GlyphBitmap
{
    public function __construct(
        public int $width,
        public int $height,
        /** 相对笔位原点的左上角偏移（y 向下为正） */
        public int $bearingX,
        public int $bearingY,
        public string $coverage,
    ) {
    }

    public function coverageAt(int $x, int $y): int
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return 0;
        }
        return ord($this->coverage[$y * $this->width + $x]);
    }

    public function isEmpty(): bool
    {
        return $this->width === 0 || $this->height === 0;
    }
}
