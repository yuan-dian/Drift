<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Rect;

/**
 * 阴影的**唯一实现**：有 `CAP_SHADOW` 就走真模糊（Skia），没有就返回 `false`
 * 让调用方走各自的降级形状。
 *
 * ★★ **为什么收成一个静态方法**：改写前阴影有**三份**手写实现 ——
 *   `Modifier::paintBehind()`（三层递减外扩，Card 用）、
 *   `RenderPopupPanel::paintContent()`（整盒垫一层 alpha=48，Popup/Modal/Menu/Select 用）、
 *   `Canvas::drawShadow()`（真 mask blur，**零消费者**）。三份各自加
 *   `if (capabilities())` 就是三处要同步，且漏一处就退化成"某个控件还是假阴影"
 *   —— 正是本仓反复警惕的"多份实现漂移"。
 *
 * ★ **为什么返回 `bool`、不自己降级**：两处的"留白模型"相反 ——
 *   `paintBehind` 的盒**不含**阴影（阴影画在盒外，父布局靠间距让位）；
 *   `RenderPopupPanel` 的盒**含**投影留白（`2 × elevation`，`performLayout()` 里已扣）。
 *   硬统一降级形状会破坏其中一个的布局假设 ⇒ 降级留给调用方，这里只管"真阴影"。
 *
 * ★ **为什么不叫 `paint()`**：它不是一个"完整的画阴影"动作，而是"能画就画"的
 *   一次尝试。名字必须让调用方知道**必须**处理 `false`。
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

        // `alpha == 0` 在 `Shadow` 里是**报错**（"透明阴影什么都不会留下"）——
        // 这里当"无事可做"提前返回 true：浓度极小（`shadow = 0.001`）时
        // 四舍五入到 0 是合法输入，不该让框架报契约错误。
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
}
