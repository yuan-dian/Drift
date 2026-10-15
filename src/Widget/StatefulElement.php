<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * `StatefulWidget` 的 Element（§3.2 / §12.3）。
 *
 * 它在 Element 基座上多两件事：① 挂载时造 `State`，**整个生命周期只造一次**；
 * ② 卸载时调 `dispose()`。除此之外 diff / 子层展开全部复用基座那套。
 *
 * @api
 */
final class StatefulElement extends Element
{
    private ?State $state = null;

    public function __construct(StatefulWidget $widget)
    {
        parent::__construct($widget);
    }

    public function state(): ?State
    {
        return $this->state;
    }

    public function mount(?Element $parent, BuildContext $context): void
    {
        $state = $this->widget->createState();
        $state->attach($this, $this->widget);
        $this->state = $state;

        parent::mount($parent, $context);
    }

    protected function didUpdateWidget(Widget $newWidget): void
    {
        // §12.3：换新描述，State 本身不换 —— 所以"跟手输入不丢、展开状态还在"
        $this->state?->didUpdateWidget($newWidget);
    }

    public function rebuild(): void
    {
        $this->state?->consumeDirty();
        $state = $this->state;
        if ($state === null) {
            return; // 未挂载：没有 State 就没有子层
        }

        $this->updateChildren([$state->build($this->context)]);
    }

    public function unmount(): void
    {
        parent::unmount();
        $this->state?->dispose(); // §12.3 回收纪律
    }
}
