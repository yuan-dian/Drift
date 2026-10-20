<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Commands\CommandArg;
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

    /** @internal 挂载重载时不要重复订阅 */
    private bool $initialized = false;

    /** @internal 帧时钟幂等：本帧已 `tickFrame()` 过的状态，重跑一遍时不再被推进第二次 */
    private int $lastTickedFrame = -1;

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
     * 每帧推进动画（§17.4.9）—— 默认"这一帧不动"。
     *
     * ★ 由 `Application::frame()` 在 settle **之前**遍历树调用：`tickFrame()` 里的
     *   `setState()` 由紧随的 settle 段消费 ⇒ 同一帧生效（放到 settle 之后会慢一帧）。
     * ★ 返回 `true` = "**我还要下一帧**"。`Application` 把整棵树的返回值聚合起来喂
     *   `wantsFrame()`，帧时钟据此决定要不要继续被叫醒 ⇒ 动画跑完（或控件卸载）就
     *   没人返回 true，定时器停摆、CPU 归零 —— **不需要谁记得 stop()**。
     * ★ 用"返回值"而不是"注册 / 注销回调"：卸载的元素不在树里，天然不再被调用；
     *   注册表则必须配对注销，漏一次就是永久空转 + 泄漏。
     *
     * @param float $timestampMs 注入的帧时间戳（不读环境时钟，§14.1 / G3）
     * @return bool 还要下一帧吗
     * @api
     */
    public function tickFrame(float $timestampMs): bool
    {
        return false;
    }

    /**
     * 帧时钟每帧"要不要下一帧"遍历的入口（@internal）。
     *
     * ★ **同帧幂等**：本帧已经 `tickFrame()` 过的状态，重跑一遍（见
     *   `Application::frame()` 的迟到挂载补 tick）时**不再推进** —— 否则既有状态
     *   一帧被推进两次、动画快进一帧。`tickFrame()` 的真实消费者（测试）不走这里，
     *   不受影响。
     *
     * @return bool 这一遍是否真的推进了（= 还要下一帧）
     */
    public function tickFrameStamped(float $timestampMs, int $serial): bool
    {
        if ($this->lastTickedFrame === $serial) {
            return false;
        }
        $this->lastTickedFrame = $serial;

        return $this->tickFrame($timestampMs);
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
     * 冒泡链的第 ① 环（§11.4.3）：本控件接到了命令。
     *
     * @return bool true = "我处理了，停止冒泡"；false = "不是我管，继续往上"
     *
     * ★ 默认 false：**不接是常态**。做成抽象的会让每个 State 都白写一遍
     *   `return false`，而那正是 A12 的样板代码。
     *
     * @api
     */
    public function handleCommand(string $name, CommandArg $arg): bool
    {
        return false;
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
