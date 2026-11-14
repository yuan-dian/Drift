<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * `Slider` 的**已解析**样式（W8-2）。
 *
 * ★ 与 `SegmentedStyle` 同一条纪律：只放**已解析的值**（`Color` / `float`），
 *   不放 token —— 盒不认识主题，换主题靠 Widget 重建（§13.2）。
 * ★ 圆角不在这里：轨道与拇指的圆角都是**按盒尺寸现算**的（`min(w,h)/2`），
 *   写死一个数就会在细轨道 / 小拇指上错。
 */
final readonly class SliderStyle
{
    /** 轨道粗细（逻辑像素）—— 4 是"看得见但不像进度条"的常见取值 */
    public const float DEFAULT_TRACK_HEIGHT = 4.0;

    /** 拇指半径 —— 8 ⇒ 直径 16，与按钮的圆角同量级 */
    public const float DEFAULT_THUMB_RADIUS = 8.0;

    /** 拇指上下留白（盒高 = 2*半径 + 这个 ×2） */
    public const float DEFAULT_THUMB_GAP = 2.0;

    public function __construct(
        /** 轨道底色（未选段） */
        public Color $track,
        /** 已选段（min → value）与拇指的填充色 */
        public Color $accent,
        /** 拇指描边（与填充同色时视觉上更"实"；禁用态改灰） */
        public Color $thumbBorder,
        public float $trackHeight,
        public float $thumbRadius,
        public float $thumbBorderWidth,
    ) {
    }
}
