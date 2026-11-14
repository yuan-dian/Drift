<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Rect;

/**
 * 阴影的**唯一实现**：有 `CAP_SHADOW` 就走真模糊（Skia），没有就返回 `false`
 * 让调用方走各自的降级形状。
 *
 * ★ 收成一处是为了防"多份实现漂移"：漏一处就退化成"某个控件还是假阴影"。
 * ★ 两处调用方的留白模型相反（盒外 vs 盒内已扣 `2×elevation`），硬统一降级
 *   会破坏其中一个的布局假设 ⇒ 降级交给调用方。
 *
 * @api
 */
final class ShadowPainter
{
    /**
     * 尝试画一次真阴影（`CAP_SHADOW`）。
     *
     * @param Rect $rect 被投影形状的矩形（**盒坐标系**，与 `drawRect` 同口径）
     * @param float $radius 被投影形状的圆角半径（不是阴影自己的）
     * @param float $blur 模糊半径（逻辑像素；Skia 侧按 `sigma = blur / 2` 落地）
     * @param float $dy 向下偏移（阴影通常在下方）
     * @param int $alpha 0..255（阴影浓度）
     * @return bool 真的画了；`false` ⇒ 调用方必须走降级
     */
    public static function tryPaint(
        Canvas $canvas,
        Rect $rect,
        float $radius,
        float $blur,
        float $dy,
        int $alpha,
    ): bool {
        if (($canvas->capabilities() & CanvasCapabilities::CAP_SHADOW) === 0) {
            return false;
        }

        // `alpha == 0` 在 `Shadow` 里是报错（"透明阴影什么都不会留下"）——
        // 这里当"无事可做"提前返回 true：浓度极小时四舍五入到 0 是合法输入。
        if ($alpha <= 0) {
            return true;
        }

        $canvas->drawShadow(new Shadow(
            $rect,
            new Color(0, 0, 0, $alpha),
            $radius,
            0.0,
            $dy,
            $blur,
        ));

        return true;
    }

    /**
     * 在**盒外**画一层柔和阴影：真阴影优先，软光栅走"三层递减外扩"。
     *
     * ★ 只给"盒不含阴影留白"的调用方；盒内已预留 `2×elevation` 的相反模型不能调它。
     *
     * @param float $alpha01 阴影浓度（0..1）；`<= 0` ⇒ 不画
     */
    public static function paintOutside(
        Canvas $canvas,
        Rect $rect,
        float $radius,
        float $alpha01,
    ): void {
        if ($alpha01 <= 0.0) {
            return;
        }

        $t = \min(1.0, $alpha01);
        $alpha = (int) \round(255.0 * $t);

        // 真软阴影（`CAP_SHADOW`）：blur/dy 按浓度等比放大。0.06 ⇒ blur 3.5、dy 1.2，
        // 与下面三层外扩（±3px）同量级 —— 不会从"贴地的一点点"变成"炸开的大投影"。
        if (self::tryPaint($canvas, $rect, $radius, 3.0 + 9.0 * $t, 1.0 + 3.0 * $t, $alpha)) {
            return;
        }

        // 降级（软光栅 / 未来后端）：三层递减外扩。
        // 单层实心矩形看起来像描边（"生硬"），三层叠出过渡带才像影子。
        $steps = [3 => 0.35, 2 => 0.60, 1 => 1.00];

        foreach ($steps as $grow => $weight) {
            $canvas->drawRect(
                new Rect(
                    $rect->left - $grow,
                    $rect->top - $grow,
                    $rect->width + 2 * $grow,
                    $rect->height + 2 * $grow,
                ),
                Paint::fill(new Color(0, 0, 0, \max(1, (int) \round($alpha * $weight)))),
                $radius + $grow,
            );
        }
    }
}
