<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderCard;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\ColorToken;

/**
 * 卡片：**一个内容块**（§15.7 的"需要盒样式的控件"那一类，带内边距）。
 *
 * ```php
 * Card::make(Column::make()->spacing(8.0)->children([
 *     Text::make('Row：主轴排布')->heading(),
 *     Row::make()->spacing(8.0)->children([...]),
 * ]));
 * ```
 *
 * ## 它补的是哪个洞
 *
 * `BoxWidget`（`Button` / `PopupPanel` / `Card` 的共同基类）只有
 * **background / border / radius**，**没有 padding**；而 `Column` / `Row` 是结构
 * 节点，**刻意不带盒样式**（§15.7：布局节点不该有绘制样式）。
 * ⇒ 在这之前，"带内边距的底色块"在 Drift 里**表达不出来** —— 于是所有示例只能
 * 把内容直接铺在白底上，视觉上既没有分组也没有层次（用户原话"看着生硬、
 * 没有质感"）。`Card` 就是补这一个洞：**盒样式 + 内边距 + 柔和阴影**。
 *
 * ## 默认样式取主题 token，显式声明则整体覆盖
 *
 * 不调 `->background()` ⇒ 底 `Pane` / 边 `Border` 1px / 圆角 2（LayUI 卡片形态）；
 * 调了任何一个 ⇒ 整套默认**让位**（§15.7 纪律 3 的既有语义，`BoxWidget` 那层
 * 就是这样实现的）。所以"当背景板用"就 `->background($灰)->paddingAll(0.0)`。
 *
 * @api
 */
final class Card extends BoxWidget
{
    private ?Widget $kid = null;

    private EdgeInsets $padding;

    /**
     * 阴影强度（`0.0` = 不画）。
     *
     * ★ 默认 **0**：LayUI 的卡片靠 1px 边框就立住了，阴影只是"贴地"的一点点。
     *   要"浮起来"的观感再显式 `->shadow(0.08)` —— 值域就是黑色透明度
     *   （0.05 ≈ `rgba(0,0,0,.05)`）。
     */
    private float $shadow = 0.0;

    public function __construct(?Widget $kid = null)
    {
        parent::__construct();
        $this->padding = EdgeInsets::all(16.0);

        if ($kid !== null) {
            $this->kid = $kid;
        }
    }

    public static function make(?Widget $kid = null): self
    {
        return new self($kid);
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

    public function padding(EdgeInsets $value): static
    {
        $this->mutate();
        $this->padding = $value;

        return $this;
    }

    public function paddingAll(float $value): static
    {
        return $this->padding(EdgeInsets::all($value));
    }

    public function shadow(float $value): static
    {
        $this->mutate();
        $this->shadow = $value;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        $theme = $context->theme();

        $style = $this->box() ?? BoxStyle::none()
            ->withBackground($theme->color(ColorToken::Pane))
            ->withBorder($theme->color(ColorToken::Border), 1.0)
            ->withRadius(2.0);

        return new RenderCard($this->padding, $this->shadow, $style);
    }

    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
