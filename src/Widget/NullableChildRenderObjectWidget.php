<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 可空子、可改子的单子控件：子可以不给，也可以事后换。
 *
 * 与 `SingleChildRenderObjectWidget` 的差别在 `child()` 的形状：那边是
 * `readonly Widget $kid` + getter `child(): Widget`，这里是 `?Widget $kid` + setter
 * `child(Widget $kid): static`，`expandChildren()` 也按 null 分流。
 *
 * ★ 合成一个类就得把"子必填"的返回类型降级成 `?Widget`，那等于把必填 / 可空
 *   这个真实语义差别从类型里抹掉。两处小重复换契约不动，是划算的。
 *
 * @api
 */
abstract class NullableChildRenderObjectWidget extends RenderBoxWidget
{
    /** 唯一子节点；`null` = 没有子 */
    protected ?Widget $kid = null;

    /**
     * ★ 构造器**不强制收子**：这些控件的子是在 `make()` 里直接赋的（各自的构造
     *   参数是布局参数，不是子）。收成必填参数会把工厂方法全部改写。
     */
    protected function __construct(?Widget $kid = null)
    {
        $this->kid = $kid;

        parent::__construct();
    }

    /** 换子（原本各控件逐字相同的 setter） */
    public function child(Widget $kid): static
    {
        $this->mutate();
        $this->kid = $kid;

        return $this;
    }

    #[\Override]
    final public function expandChildren(): array
    {
        return $this->kid === null ? [] : [$this->kid];
    }

    #[\Override]
    final public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
