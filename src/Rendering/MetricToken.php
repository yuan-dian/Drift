<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 语义度量角色（§11.5）。
 *
 * ★ **语义角色的字号也在这里**（`FontSizeTitle` / `FontSizeHeading`），所以
 *   `Text::make('x')->title()` 取的是**这张表** —— 换肤只改表，调用点不必
 *   逐个改字号（GUI 仓 P3.3 的实测结论）。
 *
 * ★ 单位是**逻辑像素**（96 dpi 基准，§6.1）；与明暗无关 ⇒ 只有一张表。
 *   到物理像素的换算是 `Scale` 的事（§6.1 唯一量化点），不在这里做。
 *
 * @api
 */
enum MetricToken
{
    case Padding;
    case Gap;
    case Radius;
    case BorderWidth;
    case FontSize;
    case FontSizeSmall;
    case FontSizeTitle;
    case FontSizeHeading;
    case RowHeight;

    public function baselineValue(): float
    {
        return match ($this) {
            self::Padding         => 12.0,
            self::Gap             => 8.0,
            self::Radius          => 6.0,
            self::BorderWidth     => 1.0,
            self::FontSize        => 14.0,
            self::FontSizeSmall   => 12.0,
            self::FontSizeTitle   => 18.0,
            self::FontSizeHeading => 15.0,
            self::RowHeight       => 28.0,
        };
    }
}
