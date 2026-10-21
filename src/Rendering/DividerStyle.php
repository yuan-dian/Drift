<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 分割线的**解析后样式**（`Divider` 用）。
 *
 * ★ 样式对象只有一个色 + 一个粗 —— 渲染盒要读的东西就这么多，不预先发明
 *   "九件套"（§11.4.3 A15：没有消费者不造抽象）。`ToggleStyle` 六色九值是因为
 *   那三种形状真的各自要一套色与几何。
 *
 * ★ 全是已解析的值，没有 `ColorToken` / `MetricToken`（§4.3：`Drift\Layout`
 *   不许碰主题）。合并发生在 Widget 层（§15.7 纪律 3）。
 *
 * @api
 */
final readonly class DividerStyle
{
    public function __construct(
        /** 线色（主题的 `Border` token） */
        public Color $color,
        /** 线粗（逻辑像素；1.0 = 常见发丝线） */
        public float $thickness,
    ) {
    }
}
