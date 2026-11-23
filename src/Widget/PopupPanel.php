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
 *   应用想改就 `->background()` / `->border()`（`Widget` 的通用修饰，写 `$mods`；
 *   本类在 `createRenderObject()` 里从 `$mods->boxStyle()` 派生，S1 / 2026-10-28）。
 *
 * @api
 */
final class PopupPanel extends SingleChildRenderObjectWidget
{
    private EdgeInsets $padding;

    private float $minWidth = 0.0;

    private float $elevation = 6.0;

    /** 内容高度上限（`0` = 不限）；语义见 `maxHeight()` */
    private float $maxHeight = 0.0;

    public function __construct(Widget $content)
    {
        parent::__construct($content);
        $this->padding = EdgeInsets::all(4.0);
    }

    public static function make(Widget $content): self
    {
        return new self($content);
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

    /**
     * 面板**内容**高度上限（逻辑像素；`0` = 不限）。
     *
     * ★ **超长才滚、够短就贴着内容** —— 这个判断在 `RenderPopupPanel` 里用**实测**
     *   做，不在这里猜：先无界排一遍量自然高，超过上限才按上限重排（内容变成可滚
     *   视口），否则按自然高贴着。所以**少选项的面板不会凭空变成一截空白**。
     *
     * ★★ **为什么必须有**：不设上限时，一列 30 项的面板会被
     *   `placeEntry()` 的"超大退化"夹成**整个客户区那么高**，超出部分被面板自己的
     *   `Overflow::Clip` 裁掉 ⇒ **第 N 项之后点不到**。而 `placeEntry()` ⑤ 的夹紧
     *   **只挪 x/y 不改高**，所以那条兜底救不了"点不到"。
     *
     * ★ **已有消费者**（都走同一条"超高才滚"）：`Select`（`PANEL_MAX_HEIGHT = 260`）、
     *   `ComboBox`（260，2026-10-24）、`Menu` / `ContextMenu`（`PANEL_MAX_HEIGHT = 320`，
     *   2026-10-24）。`Modal` / `SnackbarPanel` / `TooltipPanel` 仍不设 —— 它们的内容
     *   由应用自己管（要滚应用自己套 `ScrollView`），面板不替它决定。
     */
    public function maxHeight(float $value): static
    {
        $this->mutate();
        $this->maxHeight = $value;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        $theme = $context->theme();

        // §15.7 纪律 3：token → 值只在这里合并一次；用户显式声明则整体覆盖
        $style = $this->mods()->boxStyle() ?? BoxStyle::none()
            ->withBackground($theme->color(ColorToken::Surface))
            ->withBorder($theme->color(ColorToken::Border), 1.0);

        return new RenderPopupPanel(
            $this->padding,
            $this->minWidth,
            $this->elevation,
            $style,
            $this->maxHeight,
        );
    }

    /** @internal 见 `RenderBoxWidget::paintsBoxChrome()`：`RenderPopupPanel` 自己画底与边 */
    public function paintsBoxChrome(): bool
    {
        return true;
    }
}
