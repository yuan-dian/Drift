<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderModalHost;
use Drift\Rendering\Color;

/**
 * 模态宿主（`Modal::build()` 的产物，§A.3.7）。
 *
 * ```php
 * ModalHost::make(Center::make(PopupPanel::make($content)))
 * ```
 *
 * 它只做两件事：**盖满客户区** + **画一层遮罩**（`RenderModalHost`）。"面板摆在
 * 哪儿"由子层（`Center`）决定 —— 于是"居中 / 偏上 / 靠右"是组合，不是本控件
 * 的开关。这是 §15 的一贯分工：**形体归控件，落位归组合**。
 *
 * @internal `Modal` 的中间层；应用写 `Modal`，不写这个
 */
final class ModalHost extends RenderBoxWidget
{
    private ?Color $barrier = null;

    public function __construct(private readonly Widget $kid)
    {
        parent::__construct();
    }

    public static function make(Widget $kid): self
    {
        return new self($kid);
    }

    public function child(): Widget
    {
        return $this->kid;
    }

    /**
     * 遮罩色（**必须带 alpha**，否则下面全看不见）。
     *
     * 默认 `rgba(0,0,0,90)` ≈ 35% 黑 —— 与 `RenderPopupPanel` 的投影（48）同一档
     * 观感，但更深一档：投影是"浮起来"，遮罩是"后面不能动"。
     */
    public function barrier(Color $color): static
    {
        $this->mutate();
        $this->barrier = $color;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderModalHost($this->barrier ?? new Color(0, 0, 0, 90));
    }

    #[\Override]
    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
