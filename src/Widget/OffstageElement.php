<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * `Offstage` 的 Element：把子 Element **停在**框架的常规 diff 之外（§12.3）。
 *
 * ★ 关键机制：隐藏时子 Element **不在 `$this->children` 里**，于是 ——
 *   `Frame::flush` 走不到它（不重建）、`adoptBoxes()` 拉不到它（不 layout）、
 *   `paint` 更到不了（不画）。三件事共用一个原因：**树里没有它**。
 *   但它**也没有被卸载** —— 那样才留得住 State。
 *
 * ★ `expand()` 恒返回空数组：子层刻意不走 `updateChildren()`。走那条路的话，
 *   隐藏 ⇒ children 变空 ⇒ 子 Element 被 unmount ⇒ State 被 dispose，
 *   §12.3 的"保留"档直接没了。
 *
 * @api platform
 */
final class OffstageElement extends Element
{
    /** @internal 停着的子 Element；隐藏时它是唯一还持着子树的东西 */
    private ?Element $parked = null;

    #[\Override]
    protected function onMount(): void
    {
        $child = $this->widget()->child()->createElement();
        $child->mount($this, $this->context);
        $this->parked = $child;
    }

    public function rebuild(): void
    {
        /** @var Offstage $widget */
        $widget = $this->widget;
        $parked = $this->parked;
        if ($parked === null) {
            return;
        }

        // 换了子控件描述 ⇒ 照常规 update（Widget 不可变的既有语义不变）
        if ($parked->widget() !== $widget->child()) {
            $parked->update($widget->child());
        }

        // 可见性只决定"在不在 children 里"——不卸载、不 dispose
        $wasHidden = $this->children === [];
        $this->children = $widget->isVisible() ? [$parked] : [];

        // ★ 隐藏期间攒下的脏标记要在**重新可见时兑现**。否则"切回来"看到的是
        //   切走前那一帧的旧画面 —— 而且是静默的：值明明已经变了。
        if ($wasHidden && $widget->isVisible()) {
            $parked->settle();
            $this->activateTree($parked);
        }

        $this->adoptBoxes();
    }

    /**
     * parked 不在 `children` 里 ⇒ 父层的 `applyContext()` 遍历到不了它。
     * 不补这一句，隐藏页拿到的还是旧主题，切回来才静默换色。
     */
    #[\Override]
    public function applyContext(BuildContext $context): void
    {
        parent::applyContext($context);

        $parked = $this->parked;
        if ($parked !== null) {
            $parked->applyContext($this->widget->childContext($this->context));
        }
    }

    /** 首次可见的页面才跑 `onActivate()`；子树里可能有多个 State */
    private function activateTree(Element $element): void
    {
        if ($element instanceof StatefulElement) {
            $element->state()?->callActivate();
        }

        foreach ($element->children as $child) {
            $this->activateTree($child);
        }
    }

    #[\Override]
    public function unmount(): void
    {
        $parked = $this->parked;
        // 可见时它在 children 里，parent::unmount() 已经走过了 —— 不要 dispose 两次
        $inTree = $parked !== null && in_array($parked, $this->children, true);

        parent::unmount();

        if ($parked !== null && !$inTree) {
            $parked->unmount(); // 隐藏页：不在 children 里，parent 走不到它
        }

        $this->parked = null;
    }
}
