<?php
declare(strict_types=1);

namespace Drift\Geometry;

/**
 * 逻辑尺寸（DIP，§6.1）。
 *
 * ★ 尺寸域内没有"无界"这个概念 —— 无界只属于**约束**（`BoxConstraints`，C2）。
 *   所以这里不需要哨兵，`0` 就是"零尺寸"这个合法取值本身。
 *
 * @api
 */
final readonly class Size
{
    public function __construct(public float $width = 0.0, public float $height = 0.0)
    {
    }

    public static function zero(): self
    {
        return new self();
    }

    public static function square(float $side): self
    {
        return new self($side, $side);
    }

    public static function of(float $width, float $height): self
    {
        return new self($width, $height);
    }

    /** 零或负尺寸 = 看不见 */
    public function isEmpty(): bool
    {
        return $this->width <= 0.0 || $this->height <= 0.0;
    }

    public function inflate(EdgeInsets $insets): self
    {
        return new self(
            $this->width + $insets->horizontal(),
            $this->height + $insets->vertical()
        );
    }

    public function deflate(EdgeInsets $insets): self
    {
        return new self(
            $this->width - $insets->horizontal(),
            $this->height - $insets->vertical()
        );
    }

    public function contains(Point $point): bool
    {
        return $point->x >= 0.0 && $point->y >= 0.0 && $point->x < $this->width && $point->y < $this->height;
    }

    public function equals(Size $other): bool
    {
        return $this->width === $other->width && $this->height === $other->height;
    }
}
