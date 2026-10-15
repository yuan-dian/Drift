<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\State\Cell;
use Drift\State\Subject;
use Drift\State\Subscription;

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

    /** @var list<Subscription> 订阅袋（§11.3 RT-1） */
    private array $bag = [];

    /** @internal A4 守卫：只有 `initState()` 期间允许 `watch()` */
    private bool $initializing = false;

    /** @internal 挂载重入时不要重复订阅 */
    private bool $initialized = false;

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

    /**
     * 只标脏、不改值（§11.3.1）。
     *
     * ★ `Cell::setIfChanged()` 与 `SubjectBuilder` 的回调走这条 —— 它们已经在
     *   自己的上下文里改完值了，不需要再裹一层空闭包。
     *
     * @internal
     */
    public function markDirty(): void
    {
        $this->dirty = true;
    }

    /**
     * 挂载时调一次（§11.3 RT-1 / A4）。
     *
     * ★ **订阅只在这里做。** 在 `build()` 里订阅 ⇒ 每重建一次多订一份，订阅数随
     *   重建次数增长；`watch()` 因此只在 `initializing` 期间放行，build 期
     *   订阅 ⇒ Fatal（A4 的运行期守卫）。
     *
     * @api
     */
    public function initState(): void
    {
    }

    /**
     * 唯一的 `Cell` 创建入口（§11.3.1）。
     *
     * ★ 为什么不能 `new Cell(-1)` 直接放属性初始化器：PHP 的属性初始化器
     *   **拿不到 `$this`**，`Cell` 便无从知道该标脏哪个 `State` —— 于是"
     *   设了值但没人重建"。绑定必须显式发生，`cell()` 就是那一个显式点。
     *
     * @api
     */
    public function cell(mixed $initial): Cell
    {
        $cell = new Cell($initial);
        $cell->bindTo($this);

        return $cell;
    }

    /**
     * 订阅一个 `Subject`，并记进订阅袋（§11.3 RT-1）。
     *
     * @param callable(): void $handler 只在 `initState()` 期间允许
     *
     * @api
     */
    public function watch(Subject $subject, callable $handler): Subscription
    {
        if (!$this->initializing) {
            Errors::report('watch() 只能在 initState() 里调（§11.3 RT-1 / A4：build 期订阅会让订阅数随重建次数增长）');
        }

        $subscription = $subject->subscribeFor($this, $handler);
        $this->bag[] = $subscription;

        return $subscription;
    }

    /**
     * 退掉订阅袋里的全部订阅。
     *
     * ★ 由 `StatefulElement::unmount()` 调，**不依赖用户记得**在 `dispose()` 里
     *   退 —— `dispose()` 是可覆写的，"忘了 `parent::dispose()`"正是 A4 列的
     *   失败模式。兜底放在框架侧才守得住。
     *
     * @internal
     */
    public function dropSubscriptions(): void
    {
        foreach ($this->bag as $subscription) {
            $subscription->cancel();
        }
        $this->bag = [];
    }

    /** @internal `StatefulElement` 挂载时调；把"只准 initState 订阅"收在一处 */
    public function callInit(): void
    {
        if ($this->initialized) {
            return; // 幂等：重复挂载不重复订阅
        }

        $this->initialized = true;
        $this->initializing = true;
        try {
            $this->initState();
        } finally {
            $this->initializing = false;
        }
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
