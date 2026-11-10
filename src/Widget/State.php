<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Commands\CommandArg;
use Drift\Core\Pipeline;
use Drift\Core\PipelineAccess;
use Drift\Errors;
use Drift\State\AsyncCommand;
use Drift\State\Subject;
use Drift\State\Subscription;

/**
 * `StatefulWidget` 的纯 UI 态：只持"影响这一棵子树、必须跨 build 活"的东西；
 * 业务数据、跨页共享、被虚拟化回收容器的滚动位置应判据赶去 Store。
 *
 * @api
 */
abstract class State
{
    use PipelineAccess;

    /* 跨节点记账数字（`pendingDirty` / `markedCount` / `rebuiltCount` / `mountedThisFrame` / `frameWantedCount`）不住本类，都住 `Pipeline`，一个应用一份。 */
    /**
     * @internal 正在 `build()` 的 State —— `markDirty()` 用它识别"build 期写自己"
     *
     * 唯一故意留静态的字段：build 是同步的，两个应用不可能交错进入。
     */
    private static ?State $buildingState = null;

    /* `$mountedThisFrame` 与 `$frameWantedCount` 也住 `Pipeline`。 */

    private Widget $widget;
    private ?StatefulElement $element = null;
    private bool $dirty = false;

    /** @var array<int, \Closure(): void> 卸载时必跑的清理，键 = 自增 id（可替换） */
    private array $disposers = [];

    /** @var array<string, int> 命名订阅槽 → disposer id（同名再挂即替换） */
    private array $watchSlots = [];

    private int $nextDisposerId = 1;

    /** @internal 只有 `initState()` 期间允许 `watch()` */
    private bool $initializing = false;

    /** @internal 挂载重载时不要重复订阅 */
    private bool $initialized = false;

    /** @internal 帧时钟幂等：本帧已 `tickFrame()` 过的状态，重跑一遍时不再被推进第二次 */
    private int $lastTickedFrame = -1;

    /**
     * 本帧 `tickFrame()` 的回答 —— 同帧重入时返回它，而不是 `false`。
     *
     * 少了它就是一个静默的动画冻结：同帧第二次调用必然返回 false（幂等早退），
     * 正在跑的动画会被踢出活跃表，画面停在中途。两种语义必须区分：
     * "这一帧不需要下一帧" vs "已经问过"。
     */
    private bool $lastTickedMore = false;

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
     * 改纯 UI 态。
     *
     * 只标脏，不立即重建：统一重建发生在帧末 flush，连点十次只重建一次。
     * 不比较值：`setState()` 收的是"改字段"的闭包，框架必须假定变了；
     * "设同值不重建"的门控写在调用点（比较放在闭包外）。
     */
    public function setState(callable $change): void
    {
        $change();
        $this->markDirty();
    }

    /**
     * 只标脏、不改值。与 `setState()` 同一条时间线：重建发生在帧末 settle 段，
     * 连标十次只重建一次；已经脏就直接返回，不重复计数。
     *
     * @api
     */
    public function markDirty(): void
    {
        // ★ build() 里标脏自己 = build 期写（E-FLUSH-CYCLE）：build 本该是纯函数，
        //   却改了自己的脏标记 ⇒ 下一次 settle 又重建 ⇒ 活锁。放在早退之前，确保
        //   "重复标脏自己"也判得到。`applyContext` 的标脏在 build 之后，不会误杀。
        if (self::$buildingState === $this) {
            Errors::report(sprintf(
                'build() 里改了自身的状态（%s 在 build 中对自己标脏）：这是 build 期写，会从当帧活锁成每帧重建（E-FLUSH-CYCLE）',
                get_class($this),
            ));
        }
        if ($this->dirty) {
            return;                  // ★ 已经脏 ⇒ 不重复计数
        }
        $this->dirty = true;

        $pipeline = Pipeline::current();
        $pipeline->markDirty();

        // 登记到动效活跃表：帧时钟只 tick 在动的节点，"开始动"最常见的现场就是这里。
        // 只入队、不置 `animationActive`：那个位由收表的人写，在这里置会让
        // "已在表里就跳过"把自己刚报的上来这条跳过（第一版就这么错的）。
        $element = $this->element;
        if ($element !== null && !$element->isAnimationActive()) {
            $pipeline->noteActiveAnimation($element);
        }
    }

    /**
     * 每帧推进动画 —— 默认"这一帧不动"。
     *
     * 由 `Application::frame()` 在 settle 之前遍历树调用，内部 `setState()` 由紧随
     * 的 settle 段消费，同一帧生效。返回 `true` = 还要下一帧，聚合喂 `wantsFrame()`；
     * 动画跑完（或卸载）就没人返回 true，定时器停摆，无需谁记得 stop()。
     *
     * @param float $timestampMs 注入的帧时间戳（不读环境时钟）
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
     * 同帧幂等：本帧已 `tickFrame()` 过的状态重跑一遍时不再推进，否则既有状态
     * 一帧被推进两次、动画快进一帧。
     *
     * @return bool 这一遍是否真的推进了（= 还要下一帧）
     */
    public function tickFrameStamped(float $timestampMs, int $serial): bool
    {
        if ($this->lastTickedFrame === $serial) {
            return $this->lastTickedMore;
        }
        $this->lastTickedFrame = $serial;

        return $this->lastTickedMore = $this->tickFrame($timestampMs);
    }

    /**
     * 挂载时调一次。订阅只在这里做：在 `build()` 里订阅会让订阅数随重建增长，
     * `watch()` 因此只在 `initializing` 期间放行，build 期订阅 ⇒ Fatal。
     *
     * @api
     */
    public function initState(): void
    {
    }

    /**
     * 焦点进入 / 离开本控件时（`FocusManager::enter/leave` 调）。默认空：
     * 绝大多数 State 不关心焦点，覆写即可。焦点态必须跨重建存活，`State` 是
     * 唯一生命期与 Element 对齐的对象。
     *
     * @api
     */
    public function didChangeFocus(bool $focused): void
    {
    }

    /**
     * 命令创建 + 绑定，页面侧唯一的造命令入口。必须在 `initState()` 里调
     * （内部走 `watch()`，订阅只允许在那里）。
     *
     * 生命周期由框架收，不由页面收：`dispose()` 可覆写，命令在 `unmount()`
     * 时由 {@see self::dropDisposers()} 统一停掉。
     *
     * "住页面还是住 Store"判据：离开这个页面之后，这个请求还该跑完吗？该 ⇒
     * 住 `Store`（生命周期 = 应用）；不该 ⇒ 住页面（本方法）。选错不会报错。
     *
     * @param \Closure(mixed): \Drift\Async\Future $task 组合根接线："怎么发起一次请求"
     * @param null|\Closure(mixed, mixed): mixed $combine 追加（`append()`）时的合并规则；
     *        不给 ⇒ 这个命令只能 `run()`（替代语义）。见 {@see AsyncCommand::append()}
     *
     * @api
     */
    public function asyncCommand(\Closure $task, ?\Closure $combine = null): AsyncCommand
    {
        $command = AsyncCommand::make($task, $combine);

        $this->watch($command, function (): void {
            $this->markDirty();
        });

        $this->onDispose(static function () use ($command): void {
            $command->dispose();
        });

        return $command;
    }

    /**
     * 订阅一个 `Subject`，并登记进清理袋。
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
        $this->onDispose(static function () use ($subscription): void {
            $subscription->cancel();
        });

        return $subscription;
    }

    /**
     * 登记一条卸载时必跑的清理，返回 id（供 {@see self::replaceDisposer()} 替换）。
     *
     * ★ 兜底在框架侧 `unmount()`，不依赖用户覆写 `dispose()` 记得 `parent::`。
     *
     * @api
     */
    public function onDispose(callable $cleanup): int
    {
        $id = $this->nextDisposerId++;
        $this->disposers[$id] = \Closure::fromCallable($cleanup);

        return $id;
    }

    /**
     * 替换一条清理：**先跑旧的，再登记新的**（React effect cleanup 的顺序）。
     * `$cleanup === null` ⇒ 只移除。
     *
     * @internal
     */
    public function replaceDisposer(int $id, ?callable $cleanup): void
    {
        if (!\array_key_exists($id, $this->disposers)) {
            Errors::report('replaceDisposer() 收到未登记的 id：' . $id);
        }

        $old = $this->disposers[$id];
        $old();

        if ($cleanup === null) {
            unset($this->disposers[$id]);

            return;
        }

        $this->disposers[$id] = \Closure::fromCallable($cleanup);
    }

    /**
     * 命名订阅槽：同名再调 ⇒ 先退旧订阅，再挂新订阅。
     *
     * ★ `watch()` 只允许在 `initState()` 调（A4 防 build 期订阅）；update 阶段
     *   要重绑就走这里，槽名保证「替换」而不是「追加」。
     *
     * @internal 框架内 State（如 `SubjectBuilderState`）用
     */
    public function watchSlot(string $slot, Subject $subject, callable $handler): Subscription
    {
        $subscription = $subject->subscribeFor($this, $handler);
        $cleanup = static function () use ($subscription): void {
            $subscription->cancel();
        };

        if (\array_key_exists($slot, $this->watchSlots)) {
            $this->replaceDisposer($this->watchSlots[$slot], $cleanup);

            return $subscription;
        }

        $this->watchSlots[$slot] = $this->onDispose($cleanup);

        return $subscription;
    }

    /**
     * 退掉本 State 登记的全部清理。由 `StatefulElement::unmount()` 调，不依赖
     * 用户记得在 `dispose()` 里退。
     *
     * ★ 按登记序（FIFO）跑：先退订阅、再停命令，与旧两袋的回收顺序一致。
     *
     * @internal
     */
    public function dropDisposers(): void
    {
        $pending = $this->disposers;
        $this->disposers = [];
        $this->watchSlots = [];

        foreach ($pending as $cleanup) {
            $cleanup();
        }
    }

    /** @internal `StatefulElement` 挂载时调；把"只准 initState 订阅"收在一处 */
    public function callInit(): void
    {
        if ($this->initialized) {
            return; // 幂等：重复挂载不重复订阅
        }

        $this->initialized = true;
        Pipeline::current()->queueMounted($this);   // ★ 挂载登记（`06` §4.2）
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
        if ($dirty) {
            $this->dirty = false;
            Pipeline::current()->consumeDirty();
        }

        return $dirty;
    }

    /**
     * 声明"**我还要下一帧**"（隐式动画在 `didUpdateWidget()` / `build()` 里起飞时用）。
     *
     * 必须调的情况：动画**在 settle 期间**才开始。那时 `tickAnimations()` 已跑过、
     * 目标还是旧的 ⇒ 帧时钟落闸 ⇒ 动画停在起点，且不报错（点「淡入」一动不动）。
     * 不用调的情况：动画在**输入回调**里起飞，那种路径本来就会跑一帧。
     */
    public function requestFrame(): void
    {
        $pipeline = Pipeline::current();
        $pipeline->scheduleNextFrame();

        // 帧时钟只 tick 活跃表里的节点 ⇒ 光叫醒还不够，还得让我自己在表里，
        // 否则下一帧不会来问我。只入队、不置 `animationActive`（理由同 `markDirty()`）。
        $element = $this->element;
        if ($element !== null && !$element->isAnimationActive()) {
            $pipeline->noteActiveAnimation($element);
        }
    }

    /**
     * @internal `StatefulElement::rebuild()` 的第二句调它（第一句是 consumeDirty）
     *
     * 唯一职责：`settle()` 的"有没有重建"判据（前后 `rebuiltCount` 差）。
     */
    public function noteRebuilt(): void
    {
        Pipeline::current()->noteRebuilt();
    }

    /**
     * @internal `rebuild()` 包住 `build()` 的进入 / 退出：标记"此刻正在 build 的 State"。
     *
     * `markDirty()` 看到 `$buildingState === $this` 即知"build 里改了自己"；
     * `applyContext` 的标脏在 build 之后，看不到这个标记。
     */
    public function enterBuild(): void
    {
        self::$buildingState = $this;
    }

    /** @internal 见 `enterBuild()`；`build()` 抛异常也要清（try/finally 调它） */
    public function leaveBuild(): void
    {
        self::$buildingState = null;
    }

    /**
     * 换新描述：Widget 不可变 ⇒ 参数**整体换新**。
     *
     * @internal 别在这里做脏活 —— 那是 `rebuild()` 的事。
     *
     * 框架在调本钩子之前已由 {@see StatefulElement} 用 {@see self::adoptWidget()}
     * 落好新描述；这里保留赋值作**幂等兜底**（覆写者习惯性调 `parent::` 也无害），
     * "漏调 parent"从静默 bug 变成本地无副作用。
     */
    public function didUpdateWidget(Widget $widget): void
    {
        $this->widget = $widget;
    }

    /**
     * 框架落点：`StatefulElement` 在调用用户钩子 {@see self::didUpdateWidget()}
     * 之前先把新描述交过来。
     *
     * @internal 只有 `StatefulElement::didUpdateWidget()` 调它。
     */
    public function adoptWidget(Widget $widget): void
    {
        $this->widget = $widget;
    }

    /**
     * 冒泡链：本控件接到了命令。
     *
     * @return bool true = "我处理了，停止冒泡"；false = "不是我管，继续往上"
     *
     * 默认 false：不接是常态，做成抽象会让每个 State 都白写一遍 `return false`。
     *
     * @api
     */
    public function handleCommand(string $name, CommandArg $arg): bool
    {
        return false;
    }

    /**
     * 被回收：虚拟化滚出视口 / 条件分支不渲染 ⇒ 卸载前调。
     *
     * ★ 清理外部资源优先用 {@see self::onDispose()}（框架侧兜底，不必记得
     *   `parent::dispose()`）；本钩子留给"卸载时算点什么"的逻辑。
     */
    public function dispose(): void
    {
    }

    /**
     * 描述子层。
     *
     * 默认实现只为"直接产盒"的控件存在：它们的 `build()` 不会被调用。
     * 默认实现而非抽象：PHP/AOT 无法表达"`build()` 与 `createRenderObjectFromState()`
     * 二选一必填"（无反射）。返回零尺寸而非抛异常：遗漏 override 无害，真出错是
     * "控件不显示"不是崩溃。
     */
    public function build(BuildContext $ctx): Widget
    {
        return SizedBox::make()->size(0.0, 0.0);
    }
}
