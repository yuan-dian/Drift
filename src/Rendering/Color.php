<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 颜色值对象（§4.2 的 `Drift\Rendering`）。
 *
 * 分量范围 0..255，构造即校验 —— 越界不静默截断（G2）。
 *
 * @api
 */
final readonly class Color
{
    public function __construct(
        public int $r,
        public int $g,
        public int $b,
        public int $a = 255,
    ) {
        if ($r < 0 || $r > 255 || $g < 0 || $g > 255 || $b < 0 || $b > 255 || $a < 0 || $a > 255) {
            Errors::report(sprintf('Color 分量越界（应为 0..255）：rgba(%d, %d, %d, %d)', $r, $g, $b, $a));
        }
    }

    public static function fromRgb(int $r, int $g, int $b): self
    {
        return new self($r, $g, $b);
    }

    public static function fromRgba(int $r, int $g, int $b, int $a): self
    {
        return new self($r, $g, $b, $a);
    }

    /** 0xAARRGGBB */
    public static function fromArgb(int $argb): self
    {
        return new self(
            ($argb >> 16) & 0xFF,
            ($argb >> 8) & 0xFF,
            $argb & 0xFF,
            ($argb >> 24) & 0xFF
        );
    }

    /** `#RGB` / `#RRGGBB` / `#RRGGBBAA`（带不带 `#` 都行） */
    public static function fromHex(string $hex): self
    {
        $hex = ltrim(trim($hex), '#');
        $length = strlen($hex);
        if (!in_array($length, [3, 6, 8], true) || !ctype_xdigit($hex)) {
            Errors::report('Color::fromHex 只接受 #RGB / #RRGGBB / #RRGGBBAA，收到：' . $hex);
        }

        if ($length === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = (int) hexdec(substr($hex, 0, 2));
        $g = (int) hexdec(substr($hex, 2, 2));
        $b = (int) hexdec(substr($hex, 4, 2));
        $a = strlen($hex) === 8 ? (int) hexdec(substr($hex, 6, 2)) : 255;

        return new self($r, $g, $b, $a);
    }

    /** `#RRGGBBAA`（恒 8 位十六进制）：调试证据里一行就能认出颜色。 */
    public function __toString(): string
    {
        return \sprintf('#%02X%02X%02X%02X', $this->r, $this->g, $this->b, $this->a);
    }

    public static function white(): self
    {
        return new self(255, 255, 255);
    }

    public static function black(): self
    {
        return new self(0, 0, 0);
    }

    public static function transparent(): self
    {
        return new self(0, 0, 0, 0);
    }

    public function withAlpha(int $alpha): self
    {
        return new self($this->r, $this->g, $this->b, $alpha);
    }

    /** 0xAARRGGBB */
    public function toArgb(): int
    {
        return ($this->a << 24) | ($this->r << 16) | ($this->g << 8) | $this->b;
    }

    /**
     * 两色按 t∈[0,1] 线性插值（渐变色标之间用）。
     *
     * t 越界按端点夹取（插值本就是"段内"语义，不报错也不回绕）。
     */
    public static function lerp(Color $a, Color $b, float $t): self
    {
        if ($t <= 0.0) {
            return $a;
        }
        if ($t >= 1.0) {
            return $b;
        }

        return new self(
            (int) round($a->r + ($b->r - $a->r) * $t),
            (int) round($a->g + ($b->g - $a->g) * $t),
            (int) round($a->b + ($b->b - $a->b) * $t),
            (int) round($a->a + ($b->a - $a->a) * $t),
        );
    }

    public function isOpaque(): bool
    {
        return $this->a === 255;
    }

    public function equals(Color $other): bool
    {
        return $this->toArgb() === $other->toArgb();
    }
}
