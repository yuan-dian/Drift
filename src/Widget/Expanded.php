<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlexible;

/**
 * 让一个子项参与 flex 分配（§13.1 C4）。
 *
 * ```php
 * Row::make()->children([ Text::make('固定'), Expanded::make($grow) ]);
 * ```
 */
final class Expanded extends RenderBoxWidget
{
    private int $share = 1;

    public function __construct(private readonly Widget $kid)
    {
        parent::__construct();
    }

    public static function make(Widget $kid): self
    {
        return new self($kid);
    }

    /** 份额（默认 1）。同层两个 `Expanded` 一个 1 一个 2 ⇒ 1:2 分 */
    public function flex(int $share): static
    {
        $this->mutate();
        $this->share = $share;

        return $this;
    }

    public function child(): Widget
    {
        return $this->kid;
    }

    public function createRenderObject(): RenderBox
    {
        return new RenderFlexible($this->share);
    }

    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
