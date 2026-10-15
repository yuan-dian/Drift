<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 字重（OpenType 的 `usWeightClass` 取值，封闭域）。
 *
 * @api
 */
enum FontWeight: int
{
    case Thin = 100;
    case ExtraLight = 200;
    case Light = 300;
    case Regular = 400;
    case Medium = 500;
    case SemiBold = 600;
    case Bold = 700;
    case ExtraBold = 800;
    case Black = 900;

    public function isBold(): bool
    {
        return $this->value >= self::SemiBold->value;
    }

    /** 与目标字重的"距离"，回退链按它挑最接近的可用 face */
    public function distanceTo(self $other): int
    {
        return abs($this->value - $other->value);
    }
}
