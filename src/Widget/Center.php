<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderCenter;

/**
 * 把唯一的孩子摆在容器正中。
 *
 * ```php
 * Center::make()->child($content);
 * ```
 */
final class Center extends RenderBoxWidget
{
    private ?Widget $kid = null;

    public function __construct()
    {
        parent::__construct();
    }

    public static function make(?Widget $kid = null): self
    {
        $self = new self();
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

    public function createRenderObject(): RenderBox
    {
        return new RenderCenter();
    }

    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
