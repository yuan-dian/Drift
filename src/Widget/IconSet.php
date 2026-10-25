<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Rendering\Path;

/**
 * 内置图标的**图形数据**：归一化（0..1 方格）→ 目标尺寸的 `Path`。
 *
 * ★ 为什么是 `Path` 而不是一堆 `drawLine`：一个图标 = **一个** `DisplayList` op；
 *   且曲线（`Search` 的圆）`drawLine` 表达不了。
 * ★ 为什么坐标在这里乘 `$size` 而不是用画布缩放：`Canvas` **没有 `scale`**
 *   （只有 `save` / `translate` / `restore`）—— 缩放必须在几何层做。
 * ★ 为什么 `Path` 在**构造渲染盒时**生成一次（不是每帧）：`RenderIcon` 持已缩放的
 *   Path，`size` 不变就不重建；`Icon` 是 `RenderBoxWidget`，重建时才走
 *   `createRenderObject()`。
 *
 * ★ 坐标口径：**0..1 的方格**，线宽另给（`RenderIcon` 按 `size` 推）。这样同一份
 *   数据在 12 / 16 / 24 / 32 上形状一致，加图标只改这里。
 *
 * @api
 */
final class IconSet
{
    /** 圆的二次贝塞尔近似系数（`4/3·(√2−1)`，四段逼近误差 < 0.03%） */
    private const KAPPA = 0.5523;

    public static function path(IconName $name, float $size): Path
    {
        $p = Path::create();
        $x = static fn (float $v): float => $v * $size;
        $y = $x;

        if ($name === IconName::Menu) {
            foreach ([0.28, 0.5, 0.72] as $v) {
                $p->moveTo($x(0.2), $y($v))->lineTo($x(0.8), $y($v));
            }

            return $p;
        }

        if ($name === IconName::Close) {
            return $p
                ->moveTo($x(0.26), $y(0.26))->lineTo($x(0.74), $y(0.74))
                ->moveTo($x(0.74), $y(0.26))->lineTo($x(0.26), $y(0.74));
        }

        if ($name === IconName::Check) {
            return $p
                ->moveTo($x(0.22), $y(0.52))->lineTo($x(0.42), $y(0.72))->lineTo($x(0.78), $y(0.3));
        }

        if ($name === IconName::ArrowLeft) {
            return $p
                ->moveTo($x(0.62), $y(0.22))->lineTo($x(0.36), $y(0.5))->lineTo($x(0.62), $y(0.78));
        }

        if ($name === IconName::ArrowRight) {
            return $p
                ->moveTo($x(0.38), $y(0.22))->lineTo($x(0.64), $y(0.5))->lineTo($x(0.38), $y(0.78));
        }

        if ($name === IconName::ChevronDown) {
            return $p
                ->moveTo($x(0.24), $y(0.4))->lineTo($x(0.5), $y(0.64))->lineTo($x(0.76), $y(0.4));
        }

        if ($name === IconName::Plus) {
            return $p
                ->moveTo($x(0.5), $y(0.2))->lineTo($x(0.5), $y(0.8))
                ->moveTo($x(0.2), $y(0.5))->lineTo($x(0.8), $y(0.5));
        }

        if ($name === IconName::Minus) {
            return $p->moveTo($x(0.2), $y(0.5))->lineTo($x(0.8), $y(0.5));
        }

        if ($name === IconName::More) {
            // 三个点画成极短横线（`Path` 无法画"零长描边"的点）
            foreach ([0.28, 0.5, 0.72] as $v) {
                $p->moveTo($x($v - 0.05), $y(0.5))->lineTo($x($v + 0.05), $y(0.5));
            }

            return $p;
        }

        // Search：圆（四段 quadTo）+ 柄
        $cx = 0.43;
        $cy = 0.43;
        $r = 0.21;
        $k = self::KAPPA * $r;
        $p->moveTo($x($cx), $y($cy - $r))
            ->quadTo($x($cx + $k), $y($cy - $r), $x($cx + $r), $y($cy))
            ->quadTo($x($cx + $r), $y($cy + $k), $x($cx), $y($cy + $r))
            ->quadTo($x($cx - $k), $y($cy + $r), $x($cx - $r), $y($cy))
            ->quadTo($x($cx - $r), $y($cy - $k), $x($cx), $y($cy - $r))
            ->moveTo($x(0.58), $y(0.58))->lineTo($x(0.82), $y(0.82));

        return $p;
    }
}
