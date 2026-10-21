<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderPadding;

/**
 * 内边距（§13.1 几何面 —— 不是盒样式，所以它不继承 `BoxWidget`）。
 *
 * ```php
 * Padding::make($child)->paddingAll(8);
 * ```
 *
 * @api
 */
final class Padding extends RenderBoxWidget
{
    private EdgeInsets $insets;

    public function __construct(private readonly Widget $kid)
    {
        parent::__construct();
        $this->insets = EdgeInsets::zero();
    }

    public static function make(Widget $kid): self
    {
        return new self($kid);
    }

    public function padding(EdgeInsets $value): static
    {
        $this->mutate();
        $this->insets = $value;

        return $this;
    }

    public function all(float $value): static
    {
        return $this->padding(EdgeInsets::all($value));
    }

    public function symmetric(float $horizontal = 0.0, float $vertical = 0.0): static
    {
        return $this->padding(EdgeInsets::symmetric($horizontal, $vertical));
    }

    public function only(float $left = 0.0, float $top = 0.0, float $right = 0.0, float $bottom = 0.0): static
    {
        return $this->padding(EdgeInsets::only($left, $top, $right, $bottom));
    }

    public function child(): Widget
    {
        return $this->kid;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderPadding($this->insets);
    }

    #[\Override]
    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
