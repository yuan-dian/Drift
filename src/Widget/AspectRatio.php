<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;

/**
 * 定宽高比：盒子保持 `宽 ÷ 高 = $ratio`。
 *
 * ```php
 * // 封面按 16:9，宽度跟着父容器
 * AspectRatio::make(16 / 9, Image::make('cover.png'));
 *
 * // 也可直接贴在任意控件上（同一件事，少一层壳）
 * Image::make('cover.png')->aspect(16 / 9);
 * ```
 *
 * ★ **不是渲染盒**：与 `Center` 同构 —— 比例是 `mods.aspect` 字段，由
 *   `BoxConstraints::applyAspect()` 在 `RenderBox::layout()` 的 ① 步解出，
 *   落到纯转发盒 `RenderProxy` 上（W5 判据"修饰是字段，不是节点"）。
 *
 * ★ 求解口径（详见 `BoxConstraints::applyAspect()`）：两轴都显式定死 ⇒ 比例
 *   无自由度；一轴定死 ⇒ 另一轴按比例推；都松 ⇒ 先铺满有界那轴、超界再回退；
 *   都无界 ⇒ 保持原约束。
 *
 * @api
 */
final class AspectRatio extends RenderBoxWidget
{
    private ?Widget $kid = null;

    public function __construct()
    {
        parent::__construct();
    }

    public static function make(float $ratio, ?Widget $kid = null): self
    {
        $self = new self();
        $self->aspect($ratio);
        if ($kid !== null) {
            $self->kid = $kid;
        }

        return $self;
    }

    public function child(Widget $kid): static
    {
        $this->mutate();
        $this->kid = $kid;

        return $this;
    }

    public function kid(): ?Widget
    {
        return $this->kid;
    }

    #[\Override]
    public function expandChildren(): array
    {
        return $this->kid === null ? [] : [$this->kid];
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderProxy();
    }

    #[\Override]
    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
