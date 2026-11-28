<?php
declare(strict_types=1);

namespace Drift\DevTools\Inspect;

use Drift\Layout\RenderBox;

/**
 * 一个**活**盒的四层几何：margin / border / padding / content。
 *
 * 与 `GeometryRecord::fieldsOf()` 同源，不是第二套：读的是同一批 `Modifier` getter
 * 与同一个 `absoluteOrigin()`。差别只在时刻 —— 那边在帧末落账（可导出、可回放），
 * 这边是绘制当帧（真机叠加要的是"现在"，而几何记录只留最近 60 帧，空闲的窗口
 * 几秒就跑出窗口，用它画叠加会突然什么都不剩）。
 *
 * ## border 为什么可以向外扩一半
 *
 * 本项目的 border 是**描边**（`BoxChrome` 在盒边线上 stroke），不占布局空间 ——
 * 所以内容原点只减 padding，不减 border。要让"border 层厚度 = `borderWidth`"这个
 * 判据成立，环就得跨在边线上（内外各 `width / 2`）。这与 Chrome 的盒模型语义
 * 不同（那边 border 占空间），但**与这个框架自己画出来的像素一致**，而叠加层的
 * 职责是对上像素，不是对上一套外来语义。
 */
final class BoxLayers
{
    /**
     * @return array{
     *     border: array{x: float, y: float, w: float, h: float},
     *     content: array{x: float, y: float, w: float, h: float},
     *     borderWidth: float,
     *     margin: array{l: float, t: float, r: float, b: float},
     *     padding: array{l: float, t: float, r: float, b: float},
     * }|null `null` = 这个盒这一帧没有尺寸（没排过 / 已卸载）
     */
    public static function of(RenderBox $box): ?array
    {
        if (!$box->hasSize()) {
            return null;
        }

        $size = $box->size();
        if ($size === null) {
            return null;
        }

        $mods = $box->mods();
        $pad = [
            'l' => $mods !== null && $mods->hasPadding() ? $mods->padLeft() : 0.0,
            't' => $mods !== null && $mods->hasPadding() ? $mods->padTop() : 0.0,
            'r' => $mods !== null && $mods->hasPadding() ? $mods->padRight() : 0.0,
            'b' => $mods !== null && $mods->hasPadding() ? $mods->padBottom() : 0.0,
        ];
        $margin = [
            'l' => $mods !== null && $mods->hasInset() ? $mods->insetLeft() : 0.0,
            't' => $mods !== null && $mods->hasInset() ? $mods->insetTop() : 0.0,
            'r' => $mods !== null && $mods->hasInset() ? $mods->insetRight() : 0.0,
            'b' => $mods !== null && $mods->hasInset() ? $mods->insetBottom() : 0.0,
        ];

        $style = $mods?->boxStyle();
        $borderWidth = $style !== null && $style->hasBorder() ? $style->borderWidth : 0.0;

        [$x, $y] = RenderBox::absoluteOrigin($box);

        return [
            'border' => ['x' => $x, 'y' => $y, 'w' => $size->width, 'h' => $size->height],
            'content' => [
                'x' => $x + $pad['l'],
                'y' => $y + $pad['t'],
                'w' => $size->width - $pad['l'] - $pad['r'],
                'h' => $size->height - $pad['t'] - $pad['b'],
            ],
            'borderWidth' => $borderWidth,
            'margin' => $margin,
            'padding' => $pad,
        ];
    }
}
