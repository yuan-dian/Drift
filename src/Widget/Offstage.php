<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 隐藏但**保留挂载**（§12.1 第 4 条 / §12.3 三档里的"保留"档）。
 *
 * 跳过 layout / paint / hitTest，但 **Element 与 State 全部保留** ⇒ "切走再切回
 * 要不要保留状态"的答案是**保留**，而且代价是零（隐藏页根本不参与排版）。
 *
 * ★ 为什么不是 `IndexedStack`：它"全部 layout、只 paint 一个"，而 layout 正是
 *   性能瓶颈，隐藏页也排一遍是反方向。
 * ★ 为什么不是 `Stack + Opacity(0)`：隐藏页照样参与 layout，且 Stack 取最大子
 *   节点 ⇒ 隐藏页能把可见页撑歪。
 *
 * ★ **结构节点**（§15.7）：继承裸 `Widget`，没有 `createRenderObject()` ——
 *   隐藏时它什么都不画，可见时子控件自己画，所以它自己不需要盒。
 *
 * @api
 */
final class Offstage extends Widget
{
    private Widget $child;
    private bool $shown;

    private function __construct()
    {
        parent::__construct();
    }

    /**
     * @api
     */
    public static function make(Widget $child, bool $visible = true): self
    {
        $offstage = new self();
        $offstage->child = $child;
        $offstage->shown = $visible;

        return $offstage;
    }

    /**
     * 改可见性。★ 隐藏**不卸载** —— 子树连 Element 带 State 原样挂着。
     *
     * @api
     */
    public function visible(bool $value = true): static
    {
        $this->mutate();
        $this->shown = $value;

        return $this;
    }

    public function child(): Widget
    {
        return $this->child;
    }

    public function isVisible(): bool
    {
        return $this->shown;
    }

    #[\Override]
    public function createElement(): Element
    {
        return new OffstageElement($this);
    }
}
