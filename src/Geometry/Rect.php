<?php
declare(strict_types=1);

namespace Drift\Geometry;

/**
 * 逻辑矩形（DIP，§6.1）。
 *
 * ★ 存储是 `left/top/width/height`，但"右边界"永远由 `right()` 现算 ——
 *   不缓存第二个真相源（A9 的同类问题）。
 *
 * @api
 */
final readonly class Rect
{
    public function __construct(
        public float $left,
        public float $top,
        public float $width,
        public float $height,
    ) {
    }

    public static function fromLtrb(float $left, float $top, float $right, float $bottom): self
    {
        return new self($left, $top, $right - $left, $bottom - $top);
    }

    public static function fromOrigin(Point $origin, Size $size): self
    {
        return new self($origin->x, $origin->y, $size->width, $size->height);
    }

    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0);
    }

    public function right(): float
    {
        return $this->left + $this->width;
    }

    public function bottom(): float
    {
        return $this->top + $this->height;
    }

    public function origin(): Point
    {
        return new Point($this->left, $this->top);
    }

    public function size(): Size
    {
        return new Size($this->width, $this->height);
    }

    public function isEmpty(): bool
    {
        return $this->width <= 0.0 || $this->height <= 0.0;
    }

    public function translate(float $dx, float $dy): self
    {
        return new self($this->left + $dx, $this->top + $dy, $this->width, $this->height);
    }

    public function inflate(EdgeInsets $insets): self
    {
        return new self(
            $this->left - $insets->left,
            $this->top - $insets->top,
            $this->width + $insets->horizontal(),
            $this->height + $insets->vertical()
        );
    }

    public function deflate(EdgeInsets $insets): self
    {
        return new self(
            $this->left + $insets->left,
            $this->top + $insets->top,
            $this->width - $insets->horizontal(),
            $this->height - $insets->vertical()
        );
    }

    public function contains(Point $point): bool
    {
        return $point->x >= $this->left
            && $point->y >= $this->top
            && $point->x < $this->right()
            && $point->y < $this->bottom();
    }

    public function overlaps(Rect $other): bool
    {
        return $this->left < $other->right()
            && $other->left < $this->right()
            && $this->top < $other->bottom()
            && $other->top < $this->bottom();
    }

    public function intersect(Rect $other): self
    {
        $left = max($this->left, $other->left);
        $top = max($this->top, $other->top);
        $right = min($this->right(), $other->right());
        $bottom = min($this->bottom(), $other->bottom());

        if ($right <= $left || $bottom <= $top) {
            return self::zero();
        }
        return self::fromLtrb($left, $top, $right, $bottom);
    }

    public function equals(Rect $other): bool
    {
        return $this->left === $other->left
            && $this->top === $other->top
            && $this->width === $other->width
            && $this->height === $other->height;
    }
}
