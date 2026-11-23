<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsRole;

/**
 * 可滚动视口（§13.1 / P1，2026-10-17 落地）。
 *
 * ```php
 * ScrollView::make(Column::make()->children($longItems))            // 纵向（默认）
 * ScrollView::make($row, vertical: false)                           // 横向
 * ```
 *
 * ★ **它自己不是盒**：滚动偏移是纯 UI 态（跨 build 活、只影响这一棵子树，§11.5
 *   / §12.3 —— 页面级滚动位置放 `State` 完全没问题，判据是"承载它的 Element 会不会
 *   被回收"，本容器不做虚拟化 ⇒ 不会回收）。于是 `ScrollView` 只能是
 *   `StatefulWidget`，而 `StatefulWidget` 不产盒 ⇒ 三段分工：
 *   `ScrollView`（声明）/ `ScrollViewState`（持偏移）/ `ScrollViewBox`（产盒）。
 *   形态同 `TextField → TextFieldState → TextFieldBox`。
 *
 * ★ **滚动偏移进坐标链**（2026-10-17 padding 缺陷的同族）：偏移由 `ScrollViewState`
 *   经重建递进 `RenderScrollView` 的构造参数，盒在**布局期**把它写进子盒的
 *   `offset`（不是 paint 期 translate）⇒ 命中区与绘制区永远一致（见
 *   `RenderScrollView` 类注释）。
 *
 * ★ **滚轮怎么进来**：`ScrollEvent`（`Drift\Input`）由平台层翻译，`Application`
 *   命中后沿命中链找最近的 `ScrollViewState` 调 `scrollBy()` —— 应用与 Widget
 *   都不需要接线，指针停在哪个视口里就滚哪个（多个滚动容器各自滚动）。
 *
 * @api
 */
final class ScrollView extends StatefulWidget
{
    private ?ScrollController $controller = null;

    private function __construct(
        private readonly Widget $child,
        private readonly bool $vertical = true,
    ) {
        parent::__construct();
        // §9 无障碍：可滚动视口进语义树（"这是一个可滚动区域"；名字留给内容本身）
        $this->semantics(SemanticsRole::Scroll);
    }

    /** 唯一入口：`$child` 是被滚动的内容，`$vertical` 默认纵向滚动 */
    public static function make(Widget $child, bool $vertical = true): self
    {
        return new self($child, $vertical);
    }

    /** 外部句柄：读偏移 / 滚到底（不传 ⇒ 只由滚轮与焦点驱动） */
    public function controller(ScrollController $value): static
    {
        $this->mutate();
        $this->controller = $value;

        return $this;
    }

    public function controllerValue(): ?ScrollController
    {
        return $this->controller;
    }

    /** 被滚动的内容（原样挂进 `ScrollViewBox`，不进包装） */
    public function child(): Widget
    {
        return $this->child;
    }

    /** true = 纵向滚动（内容沿 y 平移） */
    public function isVertical(): bool
    {
        return $this->vertical;
    }

    public function createState(): State
    {
        return new ScrollViewState();
    }
}
