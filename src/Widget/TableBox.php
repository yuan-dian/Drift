<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Rect;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTable;
use Drift\Rendering\ColorToken;

/**
 * `Table` 的盒（§13.2 的 Widget → RenderBox 构造契约）。
 *
 * ★ 存在理由同 `ScrollViewBox`：`Table` 要持滚动偏移与滚动条淡出（纯 UI 态）
 *   ⇒ 只能是 `StatefulWidget`；而它**有子**（表头 + 行区）⇒ 走不了
 *   `createRenderObjectFromState()` 的叶子路径。三段分工：
 *   `Table`（声明）/ `TableState`（持偏移 + 算窗口）/ `TableBox`（产盒）。
 *
 * ★ **两个孩子**由 `TableElement::adoptBoxes()` 挂：`RenderTable` 不是
 *   `RenderProxyBox`（单子），`ProxyElement` 的 `setChild()` 挂不上第二个 ——
 *   所以本控件有自己的 Element。这是"子有名字"的那个形态（无名的多子走
 *   `MultiChildElement`）。
 *
 * ★ 字段全 readonly：Widget 不可变 ⇒ 参数只会整体换新，不存在半同步。
 *
 * @internal 应用只认 `Table`
 */
final class TableBox extends RenderBoxWidget
{
    private function __construct(
        private readonly float $offsetX,
        private readonly float $offsetY,
        private readonly float $contentWidth,
        private readonly float $headerHeight,
        private readonly float $contentHeight,
        private readonly float $sliceOrigin,
        private readonly Widget $headerKid,
        private readonly Widget $bodyKid,
        private readonly float $barOpacity,
        private readonly ?Rect $focusRect,
        private readonly ?Rect $headerFocusRect,
    ) {
        parent::__construct();
    }

    /** @internal 由 `TableState::build()` 调 */
    public static function make(
        float $offsetX,
        float $offsetY,
        float $contentWidth,
        float $headerHeight,
        float $contentHeight,
        float $sliceOrigin,
        Widget $headerKid,
        Widget $bodyKid,
        float $barOpacity,
        ?Rect $focusRect = null,
        ?Rect $headerFocusRect = null,
    ): self {
        return new self(
            $offsetX,
            $offsetY,
            $contentWidth,
            $headerHeight,
            $contentHeight,
            $sliceOrigin,
            $headerKid,
            $bodyKid,
            $barOpacity,
            $focusRect,
            $headerFocusRect,
        );
    }

    /** 表头（固定 y）+ 行区（窗），顺序与 `RenderTable::visitChildren()` 一致 */
    #[\Override]
    public function expandChildren(): array
    {
        return [$this->headerKid, $this->bodyKid];
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // ★ 主题色由 Widget 侧查、递进构造 —— 盒不找主题（§13.2 契约）。
        //   `Surface` 是表格底、`Chrome` 比它深一档（与 `AppBar` 同色系）、
        //   `Border` 画表头下那条线、`Muted` 画滚动条（与 `ScrollView` 同一个）。
        return new RenderTable(
            $this->offsetX,
            $this->offsetY,
            $this->contentWidth,
            $this->headerHeight,
            $this->contentHeight,
            $this->sliceOrigin,
            $context->color(ColorToken::Surface),
            $context->color(ColorToken::Chrome),
            $context->color(ColorToken::Border),
            $context->color(ColorToken::Muted),
            $context->color(ColorToken::Accent),
            $this->focusRect,
            $this->barOpacity,
            $this->headerFocusRect,
        );
    }

    #[\Override]
    public function createElement(): Element
    {
        return new TableElement($this);
    }
}
