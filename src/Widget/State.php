<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * `StatefulWidget` 的**纯 UI 态**（§11.5 / §12.3）。
 *
 * ★ 只持"影响这一棵子树、必须跨 build 活"的东西。被判据赶去 Store 的：
 *   业务数据、跨页共享、**被虚拟化回收的容器的滚动位置**（§12.3 的措辞限定：
 *   问的是"承载它的 Element 会不会被回收"，不是"是不是滚动位置"）。
 *
 * @api
 */
abstract class State
{
    private Widget $widget;
    private ?StatefulElement $element = null;
    private bool $dirty = false;

    /** @internal StatefulElement 建状态时调用一次 */
    public function attach(StatefulElement $element, Widget $widget): void
    {
        $this->element = $element;
        $this->widget = $widget;
    }

    public function widget(): Widget
    {
        return $this->widget;
    }

    /** @internal 帧末 flush 从这里拿到该重建的元素 */
    public function element(): ?StatefulElement
    {
        return $this->element;
    }

    /**
     * 改纯 UI 态（§11.3.1 / §5.2）。
     *
     * ★ **只标脏，不立即重建** —— 事件处理中途的 `setState()` 只置标；统一重建
     *   发生在帧末 flush。所以回调里连点十次只重建一次，"build 期 setState
     *   触发重入"这类问题**结构上不存在**。
     *
     * ★ **不比较值**：想"设同值不重建"就把那个值用 `Cell` 装（§11.3.1），
     *   别裸放在 `State` 里。
     */
    public function setState(callable $change): void
    {
        $change();
        $this->dirty = true;
    }

    public function needsBuild(): bool
    {
        return $this->dirty;
    }

    /** @internal 帧末消费脏标记 */
    public function consumeDirty(): bool
    {
        $dirty = $this->dirty;
        $this->dirty = false;

        return $dirty;
    }

    /**
     * 换新描述（§13.2）：Widget 不可变 ⇒ 参数**整体换新**。
     *
     * @internal 别在这里做脏活 —— 那是 `rebuild()` 的事。
     */
    public function didUpdateWidget(Widget $widget): void
    {
        $this->widget = $widget;
    }

    /**
     * 被回收（§12.3）：虚拟化滚出视口 / 条件分支不渲染 ⇒ 卸载前调。
     *
     * 清理对外引用（控制器、订阅、定时器）在这里做。空实现不是占位 ——
     * 没有东西要清理就是没有。
     */
    public function dispose(): void
    {
    }

    abstract public function build(BuildContext $ctx): Widget;
}
