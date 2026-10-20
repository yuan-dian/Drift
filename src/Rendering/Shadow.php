<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;
use Drift\Geometry\Rect;

/**
 * 阴影（19 op 的 `SHADOW`，Optional 层，P4 实现后端）。
 *
 * **为什么收成值对象而不是六个参数的方法签名**：① `drawShadow` 在
 * `DisplayList` 里要录制成**一个**操作数（扁平命令表的槽位是有限的）；
 * ② `BoxStyle.shadow` 字段（将来的 `RenderCard` 升级）与这个原语共享同一
 * 份数据 —— BACKPORT §8 R4 的"先原语后字段"中间就隔着一个值对象；
 * ③ CustomPainter 里阴影是要反复微调的参数簇，六个散装 float 最容易传错位。
 *
 * ★ `color` 的 alpha **就是阴影浓度**（半透明黑 = 常见阴影），不是额外乘算。
 * ★ `blur = 0` ⇒ 硬边阴影（等价于偏移的不透明矩形），实现可以走捷径。
 * ★ `cornerRadius` 是**被投影形状**的圆角，不是阴影自己的圆角 —— 与
 *   `drawRect` 的 `radius` 同语义。
 */
final readonly class Shadow
{
    public function __construct(
        public Rect $rect,
        public Color $color,
        public float $cornerRadius = 0.0,
        public float $dx = 0.0,
        public float $dy = 0.0,
        public float $blur = 0.0,
    ) {
        if ($blur < 0.0) {
            Errors::report('Shadow：模糊半径不能为负，收到 ' . (string) $blur);
        }
        if ($cornerRadius < 0.0) {
            Errors::report('Shadow：圆角半径不能为负，收到 ' . (string) $cornerRadius);
        }
        if ($color->a === 0) {
            Errors::report('Shadow：颜色透明 ⇒ 这块阴影什么都不会留下，请直接去掉它');
        }
    }
}
