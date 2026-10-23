<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;

/**
 * 把唯一的孩子摆在容器正中。
 *
 * ```php
 * Center::make()->child($content);
 * ```
 *
 * @api
 */
final class Center extends RenderBoxWidget
{
    private ?Widget $kid = null;

    public function __construct()
    {
        parent::__construct();

        // ★ 居中是**字段**（`mods.center`），不是盒子里的开关 —— `RenderCenter`
        //   在 M2-4 删除，由 `RenderBox::layout()` 的 ③（松下界）与 ⑦（算偏移）施加。
        $this->centerContent();
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
