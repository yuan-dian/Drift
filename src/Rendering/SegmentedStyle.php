<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 分段控件（`SegmentedControl`）的**解析后样式**。
 *
 * ★ 与 `ToggleStyle` / `BoxStyle` 同一条纪律：样式是**已解析的值**（颜色 / 长度），
 *   没有 `ColorToken` / `MetricToken` —— `Drift\Layout` 不许碰主题（§4.3）。
 *   "变体 → token → 值"只合并一次，落点在 Widget 层（`SegmentedControl::
 *   createRenderObject()` 经 `BuildContext`，§15.7 纪律 3）。
 *
 * ★ **轨道的圆角不住这里**：它是 `min(轨宽, 轨高) / 2`（胶囊），由
 *   `RenderSegmentedControl` 按自己的盒尺寸现算 —— 写死一个数会在"标签字号变化
 *   ⇒ 轨高变化"时对不上。选中块的圆角同理（`min(段宽, 段高) / 2`，夹半宽是硬纪律：
 *   软光栅内部会夹、Skia 桥不夹，双端必须自己夹才能 conformance）。
 *
 * @api
 */
final readonly class SegmentedStyle
{
    /** 段间距默认值（`MetricToken::Gap` 的 1/4 —— 段间缝只要"分得开"，不是布局间隙） */
    public const DEFAULT_GAP = 2.0;

    /** 选中块四边内缩默认值（让块与轨道描边之间永远有一缝） */
    public const DEFAULT_INSET = 2.0;

    public function __construct(
        /** 轨道底（`ColorToken::Chrome`） */
        public Color $track,
        /** 轨道描边（`ColorToken::Border`） */
        public Color $trackBorder,
        /** 轨道描边粗（`MetricToken::BorderWidth`；0 = 不描边） */
        public float $trackBorderWidth,
        /** 段与段之间的水平间距 */
        public float $gap,
        /** 选中块相对段盒的内缩（四边同值） */
        public float $inset,
        /** 选中段填充（`Accent`；整组禁用时降级为 `Muted`） */
        public Color $fillSelected,
        /** 选中段字色（画在填充上，取 `Pane` ⇒ 明暗自适应，与 `ToggleStyle::$mark` 同口径） */
        public Color $labelOnFill,
        /** 未选中段字色（`Text`；整组禁用时降级为 `Muted`） */
        public Color $labelIdle,
        /** 标签字号（`TextRole::Body`） */
        public float $fontSize,
    ) {
    }
}
