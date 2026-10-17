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
 *   `StatefulWidget`，而 `StatefulWidget` 不产盒 ⇒ 盒由这个 `RenderBoxWidget`
 *   造。三段分工：`ScrollView`（声明）/ `ScrollViewState`（持偏移）/
 *   `ScrollViewBox`（产 `RenderScrollView`）。
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
    ) {
        parent::__construct();
    }

    /** @internal 由 `ScrollViewState::build()` 调 */
    public static function make(float $offset, bool $vertical, Widget $kid): self
    {
        return new self($offset, $vertical, $kid);
    }

    /** 被滚动的内容 */
    public function child(): Widget
    {
        return $this->kid;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // ★ 滚动条色走主题（`Muted`：两主题都清晰、语义"次要信息"）——
        //   由 Widget 侧在造盒时递进构造参数，盒自己不找主题（§13.2 契约）。
        return new RenderScrollView($this->offset, $context->color(ColorToken::Muted), $this->vertical);
    }

    public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
