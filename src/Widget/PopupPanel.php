<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderPopupPanel;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\ColorToken;

/**
 * **浮层面板**（O-20 A 的必要子项，§5.1 裁决 1 / §4.2 点名）。
 *
 * ```php
 * PopupPanel::make(Column::make()->children($items))
 * ```
 *
 * 它只管**形体**（底 / 边框 / 投影 / 内边距 / 统一宽）——**"浮在哪儿"归框架**
 * （`Application::showMenu()` + `Frame` 的浮层分支）。两者分工是 §5.1 裁决的原话：
 * "Application 管生命周期与落位，控件只管形体"。
 *
 * ★ **默认底与边框取主题 token**（`Pane` / `Border`）—— 菜单 / 模态共用一份形体，
 *   应用想改就 `->background()` / `->border()`（`BoxWidget` 的链式，§15.7）。
 *
 * @api
 */
final class PopupPanel extends BoxWidget
{
    private EdgeInsets $padding;

    private float $minWidth = 0.0;

    private float $elevation = 6.0;

    public function __construct(private readonly Widget $content)
    {
        parent::__construct();
        $this->padding = EdgeInsets::all(4.0);
    }

    public static function make(Widget $content): self
    {
        return new self($content);
    }

    public function child(): Widget
    {
        return $this->content;
    }

    #[\Override]
    public function expandChildren(): array
    {
        return [$this->content];
    }

    public function padding(EdgeInsets $value): static
    {
        $this->mutate();
        $this->padding = $value;

        return $this;
    }

    /** 面板最小宽（菜单给个下限更好看；最终宽 = max(此值, 最宽子项)） */
    public function minWidth(float $value): static
    {
        $this->mutate();
        $this->minWidth = $value;

        return $this;
    }

    /** 投影留白（0 = 不画投影）；夹紧用的是**面板矩形**，不是含留白的盒 */
    public function elevation(float $value): static
    {
        $this->mutate();
        $this->elevation = $value;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        $theme = $context->theme();

        // §15.7 纪律 3：token → 值只在这里合并一次；用户显式声明则整体覆盖
        $style = $this->box() ?? BoxStyle::none()
            ->withBackground($theme->color(ColorToken::Pane))
            ->withBorder($theme->color(ColorToken::Border), 1.0);

        return new RenderPopupPanel($this->padding, $this->minWidth, $this->elevation, $style);
    }

    #[\Override]
    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
