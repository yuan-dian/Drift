<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlexible;

/**
 * 占位弹性项：`Expanded` 的"没有孩子"形态。
 *
 * `Row::make()->children([... , Spacer::make()])` 把剩下的空间吃掉 —— 这是
 * "把这一行推到左边"的惯用法（§A.1 / §A.2 到处这么写）。
 */
final class Spacer extends RenderBoxWidget
{
    private int $share = 1;

    public function __construct()
    {
        parent::__construct();
    }

    public static function make(int $flex = 1): self
    {
        $self = new self();
        $self->share = $flex;

        return $self;
    }

    public function flex(int $share): static
    {
        $this->mutate();
        $this->share = $share;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderFlexible($this->share);
    }

    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
