<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;

/**
 * 占位弹性项：`Expanded` 的"没有孩子"形态。
 *
 * `Row::make()->children([... , Spacer::make()])` 把剩下的空间吃掉 —— 这是
 * "把这一行推到左边"的惯用法（§A.1 / §A.2 到处这么写）。
 *
 * @api
 */
final class Spacer extends NullableChildRenderObjectWidget
{
    public function __construct()
    {
        parent::__construct();
        $this->grow(1);
    }

    public static function make(int $flex = 1): self
    {
        return (new self())->flex($flex);
    }

    /** 份额（与 `Expanded` 同一条路：只有 `mods.grow` 一个住处） */
    public function flex(int $share): static
    {
        if ($share < 1) {
            \Drift\Errors::report(\sprintf('flex 份额必须 ≥ 1，收到 %d', $share));
        }

        return $this->grow($share);
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderProxy();
    }
}
