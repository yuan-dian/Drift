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

    /**
     * 本节点的盒是不是 `State` 直接产的（决定 `rebuild()` 走哪条路）。
     *
     * @internal `02` §6.2
     */
    private bool $boxFromState = false;

    public function __construct(StatefulWidget $widget)
    {
        parent::__construct($widget);
    }

    public function state(): ?State
    {
        return $this->state;
    }

    #[\Override]
    public function mount(?Element $parent, BuildContext $context): void
    {
        $state = $this->widget->createState();
        $state->attach($this, $this->widget);
        $this->state = $state;

        parent::mount($parent, $context);
    }

    /**
     * 造盒：**先问 State，再走基座**（`02` §6.2）。
     *
     * @internal 由 `Element::mount()` / `syncChildren()` 调
     */
    #[\Override]
    protected function syncBox(): void
    {
        $box = null;
        $state = $this->state;
        $widget = $this->widget;
        if ($state !== null && $widget instanceof StatefulWidget) {
            $box = $widget->createRenderObjectFromState($this->context, $state);
        }

        if ($box !== null) {
            $this->box = $box;
            $this->boxFromState = true;
            // 与基座同两句（`Element::syncBox()` 的同形注释）：光标是 Widget 的
            // 声明、修饰是一个引用递给盒。**`setMods()` 一句不能省** —— 少了它
            // `Spinner->padding(8)` 会撞 §11.7 的"修饰无处可去"守卫。
            $box->setCursor($widget->cursorShape());
            $box->setMods($widget->mods());

            return;
        }

        $this->boxFromState = false;
        parent::syncBox();
    }

    #[\Override]
    protected function didUpdateWidget(Widget $newWidget): void
    {
        // ★★ 顺序不能反：**先**由框架落新描述，**再**交给用户钩子。
        //   这样覆写者永远不必记得 `parent::didUpdateWidget($widget)` ——
        //   漏调不再导致 `$this->widget` 停在旧值（`State::adoptWidget()` 的注释）。
        $this->state?->adoptWidget($newWidget);

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

        // ★ 叶子：只换盒，**不产子、不调 `build()`**（`02` §6.2）。
        //   盒里带的是 State 的当前值 ⇒ `setState()` 后这一步把新相位递进去。
        //   注意这里**不能**省 `consumeDirty()`（上面那句）—— 不然 settle() 的
        //   "脏了就重建"判据永远为真，收敛不了。
        if ($this->boxFromState) {
            $this->syncBox();

            return;
        }

        $state->noteRebuilt();     // ★ 收敛循环的"有没有重建"判据（settle() 用前后差）
        // ★ build() 包进 enter/leave：build 里对自己标脏 ⇒ markDirty() 判 E-FLUSH-CYCLE
        $state->enterBuild();
        try {
            $this->updateChildren([$state->build($this->context)]);
        } finally {
            $state->leaveBuild();
        }
    }

    #[\Override]
    protected function onMount(): void
    {
        // §11.3 RT-1 / A4：订阅只在 initState 里做，且必须在首次 build 之前
        $this->state?->callInit();
    }

    #[\Override]
    public function unmount(): void
    {
        parent::unmount();
        $state = $this->state;
        if ($state !== null) {
            $state->dispose();             // §12.3 回收纪律
            $state->dropSubscriptions();   // §11.3 RT-1：框架侧兜底全退
            $state->dropCommands();        // 同上：命令持有的在途任务一并停掉
        }
    }
}
