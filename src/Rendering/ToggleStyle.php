<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 开关类控件的**解析后样式**（`Checkbox` / `Radio` / `Switch` 共用）。
 *
 * ★ **为什么是一个值对象而不是 9 个构造参数**：这三类控件的形状不同，但"用哪几个
 *   颜色、多粗、多大间距"完全同构；9 个散装参数既容易传错位，也让"变体 → token →
 *   值只合并一次"（§15.7 纪律 3）无处落地。与 `BoxStyle` 同一思路：样式是数据，
 *   渲染盒只读它。
 *
 * ★ **全部是已解析的值**（颜色 / 长度），没有 `ColorToken` / `MetricToken` ——
 *   `Drift\Layout` 不许碰主题（§4.3）。
 *
 * @api
 */
final readonly class ToggleStyle
{
    public function __construct(
        /** 选中态的填充 / 描边 / 轨道色 */
        public Color $accent,
        /** 未选中态的边框 / 轨道底色 */
        public Color $idle,
        /** 勾与圆点（画在 accent 底上，通常是画布色 ⇒ 明暗自适应） */
        public Color $mark,
        /** 开关滑块（同上，画在轨道上） */
        public Color $thumb,
        /** 标签字色（已按 enabled 选好，渲染盒不再二次判断） */
        public Color $label,
        /** 禁用时的形状色（边框 / 轨道 / 勾底） */
        public Color $disabled,
        /** 方框边长；开关的轨道高（宽度由 `RenderToggle` 按比例算） */
        public float $boxSize,
        /** 控件与标签之间的水平间距 */
        public float $gap,
        /** 标签字号 */
        public float $fontSize,
    ) {
    }
}
