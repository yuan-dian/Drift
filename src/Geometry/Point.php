<?php
declare(strict_types=1);

namespace Drift\Geometry;

/**
 * 逻辑坐标点（DIP，§6.1）。布局 / 控件 / 主题全程只说逻辑单位。
 *
 * @api
 */
final readonly class Point
{
    public function __construct(public float $x = 0.0, public float $y = 0.0)
    {
    }

    public static function zero(): self
    {
        return new self();
    }

    public static function of(float $x, float $y): self
    {
        return new self($x, $y);
    }

    public function translate(float $dx, float $dy): self
    {
        return new self($this->x + $dx, $this->y + $dy);
    }

    public function plus(Point $delta): self
    {
        return new self($this->x + $delta->x, $this->y + $delta->y);
    }

    public function minus(Point $other): self
    {
        return new self($this->x - $other->x, $this->y - $other->y);
    }

    public function equals(Point $other): bool
    {
        return $this->x === $other->x && $this->y === $other->y;
    }
}
