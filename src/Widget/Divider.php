<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderDivider;
use Drift\Rendering\ColorToken;
use Drift\Rendering\DividerStyle;

/**
 * 分割线 —— `Divider::make()`，横贯可用宽度的一根发丝线。
 *
 * ```php
 * Column::make()->children([
 *     Text::make('账号'),
 *     Divider::make(),
 *     Text::make('小张'),
 * ])
 * ```
 *
 * ★ **装饰，不给语义角色**（§9.2 的判据：纯布局 / 装饰不进语义树）——
 *   屏幕阅读器念一条"分割线"没有信息量，反倒把"按角色找控件"的结果搅浑。
 *
 * ★ **宽度默认吃满授予**（`->width(120.0)` 可给固定宽度）：一根线只有横贯
 *   可用宽度时才叫"分割"。放进 `Row` 会吃掉该行剩余宽度（§16.6.1 R3 的警告
 *   症状，通常该配 `Expanded` 或改竖排）。
 *
 * @api
 */
final class Divider extends RenderBoxWidget
{
    private float $thickness = 1.0;

    /** `<= 0` = 吃满授予宽度（见 `RenderDivider::performLayout()`） */
    private float $width = 0.0;

    public static function make(): self
    {
        return new self();
    }

    /** 线粗（逻辑像素，默认 1.0） */
    public function thickness(float $thickness): static
    {
        $this->mutate();
        $this->thickness = $thickness;

        return $this;
    }

    /** 固定宽度；`<= 0`（默认）= 吃满授予宽度 */
    public function width(float $width): static
    {
        $this->mutate();
        $this->width = $width;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderDivider(
            new DividerStyle(
                $context->color(ColorToken::Border),
                $this->thickness,
            ),
            $this->width,
        );
    }
}
