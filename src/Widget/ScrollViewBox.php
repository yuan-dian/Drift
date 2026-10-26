<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderScrollView;
use Drift\Rendering\ColorToken;

/**
 * `ScrollView` 的盒（§13.2 的 Widget → RenderBox 构造契约）。
 *
 * ★ 存在理由同 `TextFieldBox`：`ScrollView` 要持滚动偏移（纯 UI 态）⇒ 只能是
 *   `StatefulWidget`。三段分工：`ScrollView`（声明）/ `ScrollViewState`（持偏移）/
 *   `ScrollViewBox`（产 `RenderScrollView`）。
 *   ★ 2026-10-23 起**这是"有子"的特例，不是框架要求**（`02` §6.4）：
 *   `createRenderObjectFromState()` 让"有态 + 有盒 + **无子**"的控件两段就够
 *   （`Spinner` 已改造），而 `ScrollView` 有子（滚动内容）⇒ 走不了叶子路径。
 *
 * ★ **孩子原样透传**：本盒是 `RenderProxyBox` 的代理盒，孩子由 `ProxyElement`
 *   在挂接期挂上（`ProxyElement::expand()` 认 `ScrollViewBox`）—— 与 `Padding`
 *   同一个机制，不在 `createRenderObject()` 里手动挂（子盒挂接是 Element 的事）。
 *
 * @internal 应用只认 `ScrollView`
 */
final class ScrollViewBox extends RenderBoxWidget
{
    private function __construct(
        private readonly float $offset,
        private readonly bool $vertical,
        private readonly Widget $kid,
        private readonly ?float $virtualExtent = null,
        private readonly float $sliceOrigin = 0.0,
        private readonly float $barOpacity = 1.0,
    ) {
        parent::__construct();
    }

    /**
     * @internal 由 `ScrollViewState::build()` / `LazyListState::build()` 调
     *
     * ★ 后两个参数是**虚拟列表**的（`LazyColumn`）：`$virtualExtent` = 完整内容的
     *   主轴长度、`$sliceOrigin` = 这扇窗在完整内容里的起点。`ScrollView` 不传
     *   ⇒ `null` / `0.0` ⇒ 走 `RenderScrollView` 的旧路径（逐字节不变）。
     */
    public static function make(
        float $offset,
        bool $vertical,
        Widget $kid,
        ?float $virtualExtent = null,
        float $sliceOrigin = 0.0,
        float $barOpacity = 1.0,
    ): self {
        return new self($offset, $vertical, $kid, $virtualExtent, $sliceOrigin, $barOpacity);
    }

    /** 被滚动的内容 */
    public function child(): Widget
    {
        return $this->kid;
    }

    #[\Override]
    public function expandChildren(): array
    {
        return [$this->kid];
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // ★ 滚动条色走主题（`Muted`：两主题都清晰、语义"次要信息"）——
        //   由 Widget 侧在造盒时递进构造参数，盒自己不找主题（§13.2 契约）。
        return new RenderScrollView(
            $this->offset,
            $context->color(ColorToken::Muted),
            $this->vertical,
            $this->virtualExtent,
            $this->sliceOrigin,
            $this->barOpacity,
        );
    }

    #[\Override]
    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
