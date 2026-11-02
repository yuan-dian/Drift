<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;

/**
 * 让一个子项参与 flex 分配（§13.1 C4）。
 *
 * ```php
 * Row::make()->children([ Text::make('固定'), Expanded::make($grow) ]);
 * ```
 *
 * @api
 */
final class Expanded extends SingleChildRenderObjectWidget
{
    public function __construct(Widget $kid)
    {
        parent::__construct($kid);
        $this->grow(1);
    }

    public static function make(Widget $kid): self
    {
        return new self($kid);
    }

    /**
     * 份额（默认 1）。同层两个 `Expanded` 一个 1 一个 2 ⇒ 1:2 分。
     *
     * ★ 份额现在只有**一个**住处：`mods.grow`（`Element::syncBox()` 递给盒）。
     *   `RenderFlexible` 在 M2-4 删掉，它构造器里的守卫搬到这里 —— `< 1` 分不到
     *   空间，静默夹紧只会让"给了 `Expanded` 却不生效"更难查。
     */
    public function flex(int $share): static
    {
        if ($share < 1) {
            \Drift\Errors::report(\sprintf('flex 份额必须 ≥ 1，收到 %d', $share));
        }

        return $this->grow($share);
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // 份额住 `mods`（`__construct()` 里 `grow(1)`）⇒ 纯转发盒就够
        return new RenderProxy();
    }
}
