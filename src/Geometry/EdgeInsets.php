<?php
declare(strict_types=1);

namespace Drift\Geometry;

/**
 * 四边内 / 外边距（DIP，§6.1）。
 *
 * ★ 内边距属于**几何面**（§13.1），不属于盒样式（§15.7 的 `BoxStyle` 只管
 *   "画成什么样"）—— `->padding(8)` 走这里，`->background($c)` 走 `BoxStyle`。
 *
 * @api
 */
final readonly class EdgeInsets
{
    public function __construct(
        public float $left = 0.0,
        public float $top = 0.0,
        public float $right = 0.0,
        public float $bottom = 0.0,
    ) {
    }

    public static function zero(): self
    {
        return new self();
    }

    public static function all(float $value): self
    {
        return new self($value, $value, $value, $value);
    }

    public static function symmetric(float $horizontal = 0.0, float $vertical = 0.0): self
    {
        return new self($horizontal, $vertical, $horizontal, $vertical);
    }

    public static function only(
        float $left = 0.0,
        float $top = 0.0,
        float $right = 0.0,
        float $bottom = 0.0,
    ): self {
        return new self($left, $top, $right, $bottom);
    }

    public function horizontal(): float
    {
        return $this->left + $this->right;
    }

    public function vertical(): float
    {
        return $this->top + $this->bottom;
    }

    public function isZero(): bool
    {
        return $this->left === 0.0 && $this->top === 0.0 && $this->right === 0.0 && $this->bottom === 0.0;
    }

    /** 内容尺寸 + 内边距 = 盒尺寸 */
    public function inflate(Size $size): Size
    {
        return $size->inflate($this);
    }

    /** 盒尺寸 − 内边距 = 内容尺寸（可为负，由约束的 min 兜底） */
    public function deflate(Size $size): Size
    {
        return $size->deflate($this);
    }

    public function equals(EdgeInsets $other): bool
    {
        return $this->left === $other->left
            && $this->top === $other->top
            && $this->right === $other->right
            && $this->bottom === $other->bottom;
    }
}
