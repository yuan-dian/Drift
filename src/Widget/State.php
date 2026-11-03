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
    use PipelineAccess;

    /*
     * 五件"跨节点记账"的数字**不住本类**：`pendingDirty` / `markedCount` /
     * `rebuiltCount` / `mountedThisFrame` / `frameWantedCount` 都住 `Pipeline`
     * （2026-11-02，技术债 D3①）。原先它们是 `private static`，前提是"单应用 +
     * 单窗口 + 串行测试"；前提一变就是硬阻塞 —— 两个 `Application` 会互相消费
     * 对方的脏、共用一条标脏号。搬进管线之后**一个应用一份**，而"主树与浮层共用
     * 一份"的语义没变（它们是同一次帧、同一条管线）。
     *
     * ★ 各字段"为什么长这样"的理由留在它对应的方法上（计数而非登记表 /
     *   只增不减 / 登记表必须被消费干净）—— 那几条与"住哪"无关，别跟着搬走。
     */
    /**
     * @internal 正在 `build()` 的 State —— `markDirty()` 用它识别"build 期写自己"
     *
     * ★ **为什么是"build 期写自己"而不是"同一次 settle 两次 rebuild"**：真机上
     *   `Element::applyContext()`（主题 / 上下文切换）会合法地给 StatefulElement
     *   标脏，形态和"build 期写"完全一样（同一次 settle 多次重建），但那是框架
     *   自身的必要传播，**不是 bug**。`applyContext` 发生在 `build()` 之外
     *   （`rebuild()` 调完 `build()` 之后才走 `updateChildren` → `applyContext`），
     *   所以只在 `build()` 调用栈内标脏自己才判 E-FLUSH-CYCLE —— 精准、零误杀。
     *
     * ★ 它是五件里唯一**故意留静态**的（同 `Pipeline` 类注释里那张清单）：build 是
     *   同步的，两个应用不可能交错进入 ⇒ 它标记的是调用栈，不是状态。
     */
    private static ?State $buildingState = null;

    /*
     * `$mountedThisFrame`（本帧挂载的 State）与 `$frameWantedCount`（"我要下一帧"
     * 的累计请求数）也住 `Pipeline`。两者的理由分别写在 `takeMountedThisFrame()`
     * 与 `requestFrame()` 上。
     */

    private Widget $widget;
    private ?StatefulElement $element = null;
    private bool $dirty = false;

    /** @var list<Subscription> 订阅袋（§11.3 RT-1） */
    private array $bag = [];

    /** @var list<AsyncCommand> 本 State 持有的命令（卸载时统一停，见 `dropCommands()`） */
    private array $commands = [];

    /** @internal A4 守卫：只有 `initState()` 期间允许 `watch()` */
    private bool $initializing = false;

    /** @internal 挂载重载时不要重复订阅 */
    private bool $initialized = false;

    /** @internal 帧时钟幂等：本帧已 `tickFrame()` 过的状态，重跑一遍时不再被推进第二次 */
    private int $lastTickedFrame = -1;

    /**
     * 本帧 `tickFrame()` 的回答 —— 同帧重入时返回它，而不是 `false`。
     *
     * ★★ 少了它就是一个静默的动画冻结（2026-11-03，随动效活跃表一起加）：
     *   活跃表按"tick 返回 false ⇒ 出表"维护，而**同帧第二次调用必然返回 false**
     *   （上面那条幂等早退）⇒ 一个正在跑的动画会在第一次重复调用时被踢出表，
     *   下一帧没人 tick 它，画面停在中途。两种返回值的语义必须区分：
     *   "这一帧不需要下一帧" vs "这一帧已经问过了"。
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
     * 改纯 UI 态（§11.3.1 / §5.2）。
     *
     * ★ **只标脏，不立即重建** —— 事件处理中途的 `setState()` 只置标；统一重建
     *   发生在帧末 flush。所以回调里连点十次只重建一次，"build 期 setState
     *   触发重入"这类问题**结构上不存在**。
     *
     * ★ **不比较值**：`setState()` 收的是一个"改哪些字段"的闭包，框架无从知道
     *   里面动了什么，所以它**必须**假定变了。
     *   "设同值不重建"的标准写法是**比较放在闭包外面**：
     *
     *   ```php
     *   if ($this->query !== $next) {
     *       $this->setState(function () use ($next): void { $this->query = $next; });
     *   }
     *   ```
     *
     *   ★ 为什么不给一个 `Cell` 之类的单值包装（2026-11-02 删掉了）：它只省掉
     *   上面这两行，却要多一个类型、一个创建方法、一套"绑定属主"的概念，还要
     *   在文档里解释三次"什么时候用它、什么时候不用"。**样板量小于概念成本**，
     *   而且 `if` 是显式的 —— 读代码的人一眼看到门控在哪，不用去查包装类做了什么。
     */
    public function setState(callable $change): void
    {
        $change();
        $this->markDirty();
    }

    /**
     * 只标脏、不改值（§11.3.1）。
     *
     * ★ `SubjectBuilder` 的回调、动画 tick、输入落地走这条 —— 它们已经在自己的
     *   上下文里改完值了，不需要再裹一层空闭包。
     *
     * ★ **什么时候用它而不是 `setState()`**：值已经改完了，只差"告诉框架该重建了"。
     *   包一层空闭包也能跑，但读者会以为"这里要改点什么"—— 而这里没有。
     *
     * ★ **它不比较值**：同 `setState()`，门控写在调用点（见 `setState()` 的示例）。
     *
     * ★ 与 `setState()` 同一条时间线：只置脏标记，重建发生在**帧末**的 settle
     *   段 ⇒ 连标十次只重建一次。
     *
     * ★ 它同时维护 `pendingDirty` 静态计数（收敛循环的提前退出条件）⇒
     *   **已经脏就直接返回**，不重复计数。
     *
     * @api
     */
    public function markDirty(): void
    {
        // ★★ build() 里标脏**自己** = build 期写（E-FLUSH-CYCLE）：build 本该是纯函数，
        //   却在第 ① 环就改了自己的脏标记 ⇒ 下一次 settle 又重建 ⇒ 活锁。放在早退之前，
        //   确保"重复标脏自己"也判得到。`applyContext` 的标脏发生在 build 之后，看不到
        //   这个标记，所以不会误杀主题 / 上下文切换。
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

        // ★★ 登记到**动效活跃表**（2026-11-03）：帧时钟不再每帧问全树，只 tick
        //   在动的那些。"开始动"最常见的现场就是这里（`setState` 之后启动动画 /
        //   改动画参数），所以顺手报一声。不报的后果是静默的：动画不动、不报错。
        // ★ 只入队、**不置 `animationActive`**：那个位由收表的人写。在这里置了，
        //   收表时"已在表里就跳过"的判据会把自己刚报上来的这条跳过（第一版就这么错的）。
        $element = $this->element;
        if ($element !== null && !$element->isAnimationActive()) {
            $pipeline->noteActiveAnimation($element);
        }
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
            return $this->lastTickedMore;
        }
        $this->lastTickedFrame = $serial;

        return $this->lastTickedMore = $this->tickFrame($timestampMs);
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
     * 焦点进入 / 离开本控件时（`FocusManager::enter/leave` 调）。
     *
     * ★ **默认空**：绝大多数 State 不关心焦点；覆写即可（`TextFieldState`
     *   用它进出编辑态）。
     * ★ 为什么是 `State` 钩子而不是 `Widget` 上的闭包：焦点态必须存在**跨重建
     *   存活**的地方 —— Widget 每次 `setState` 都是新实例、盒也会被
     *   `syncBox()` 造新的（2026-10-24 实测：盒里的 `$focused` 一重建就丢）。
     *   `State` 是唯一生命期与 Element 对齐的对象。
     *
     * @api
     */
    public function didChangeFocus(bool $focused): void
    {
    }

    /**
     * 命令创建 + 绑定（页面侧**唯一**的造命令入口，与 `asyncCommand` 同名同路）。
     *
     * ```php
     * public function initState(): void
     * {
     *     $this->rows = $this->asyncCommand($this->fetchRows);   // 造 + 订阅
     *     $this->rows->run(1);
     * }
     * ```
     *
     * ★ **必须在 `initState()` 里调**：内部走 `watch()`，而订阅只允许在那里
     *   （§11.3 RT-1 / A4：build 期订阅会让订阅数随重建次数增长）。
     *
     * ★★ **生命周期由框架收，不由页面收**。上一版要页面自己写
     *   `dispose() { $this->rows->dispose(); }` —— 而 `dispose()` 是**可覆写**的，
     *   "忘了 `parent::dispose()`"正是 A4 点名的失败模式；`Subscription` 的注释
     *   把这条原则写得很清楚（"把兜底放在用户侧就是文档承诺了、机制守不住"）。
     *   命令是同一类资源 ⇒ 同样由 {@see self::dropCommands()} 在 `unmount()` 时
     *   统一停掉。于是"转圈中离开页面 ⇒ worker 白跑满 1.2s"不需要谁记得防。
     *
     * ★ 这里**没有**"宿主还在树上吗"那句守卫：卸载时命令已经被 `dispose()`，
     *   迟到的结算根本到不了这个回调（上一版靠那句守卫兜底，是因为当时命令的
     *   生死归页面管）。留一个不可达的分支比不留更糟 —— 它没有测试能消费，
     *   读者却要替它想象一个场景（本仓纪律：未消费的分支 = 未实现）。
     *
     * ★★ **"住页面还是住 Store"的判据只有一句**（2026-10-31 复审补）：***离开
     *   这个页面之后，这个请求还该跑完吗？*** 该 ⇒ 住 `Store`（`subscribeFor()`，
     *   生命周期 = 应用，下一页面还要它）；不该 ⇒ 住页面（本方法）。
     *   两种住法的差别是**硬的**：本方法造的命令在 `unmount()` 时被
     *   {@see self::dropCommands()} 停掉 ⇒ 在途请求真的取消、迟到结果永不结算；
     *   住 `Store` 的命令离开页面后照跑照结算 ⇒ 回来时直接是 `data`（不白转一次圈）。
     *   选错**不会报错**，只会让请求"多跑一趟"或"白跑一趟" —— 所以要有判据，
     *   不能靠直觉（`apps/showcase` 的表格页住 State，因为它只属于那一页；
     *   `apps/users` 的 `UserStore` 住 Store，因为多个页面看同一份数据）。
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

        $this->commands[] = $command;

        return $command;
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

    /**
     * 停掉 {@see self::asyncCommand()} 造出来的全部命令（**框架侧兜底**）。
     *
     * ★ 与 `dropSubscriptions()` 同一个理由、同一条路：命令持有在途的 `Future`，
     *   而"页面走了还在跑"的代价是 worker 空转 + 迟到的结算去标一个没人消费的脏。
     *   `dispose()` 可覆写 ⇒ 兜底只能放框架侧。
     *
     * @internal 由 `StatefulElement::unmount()` 调
     */
    public function dropCommands(): void
    {
        foreach ($this->commands as $command) {
            $command->dispose();
        }
        $this->commands = [];
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

    // ★★ 这里原先有 7 个静态门面（`pendingDirty` / `markedCount` / `rebuiltCount` /
    //   `boxEpoch` / `noteBoxReplaced` / `takeMountedThisFrame` / `mountedCount`），
    //   全部是 `Pipeline::current()->…` 的一行转发。2026-11-02 删掉（D3 收口）：
    //   同一份数两个名字，新代码就会用旧名字，而旧名字还在骗人 —— `boxEpoch()`
    //   读的是渲染树的全局序号，与"某个 State"毫无关系。
    //   现在只有两个入口，语义各自清楚：
    //     · 树内对象（本类 / `Element` / `RenderObject`）→ `$this->pipeline()`（`PipelineAccess`）
    //     · 树外观察者（`Application` / `FocusManager` / 测试 / 探针）→ `Pipeline::current()`

    /**
     * 声明"**我还要下一帧**"（隐式动画在 `didUpdateWidget()` / `build()` 里起飞时用）。
     *
     * ★★ 什么时候必须调：动画**在 settle 期间**才开始。`tickAnimations()` 在 settle
     *   之前跑，那时目标还是旧的 ⇒ 它看不到"刚起飞" ⇒ 帧时钟落闸 ⇒ 动画停在起点，
     *   **且不报错**。真机现象：点「淡入」画面一动不动，再点一次才动。
     *
     * ★ 什么时候**不用**调：动画在**输入回调**里起飞（`Button::onPressed()` 里
     *   `forward()`）。那种路径本来就会跑一帧，⓸ 在 settle 之前已经看到它了。
     *
     * ★★ **语义在 2026-11-02 变强了**（技术债 D1）：以前它只 `++` 一个计数器，
     *   帧时钟**根本不知道** —— 只在这时候"恰好还有别的东西欠帧"才生效，是
     *   **巧合正确**（注释原先写的就是"上膛"）。现在它直接 `scheduleNextFrame()`：
     *   真的把帧时钟叫醒，且档位是"按帧节奏"而不是"立刻"（同帧再跑一次等于
     *   一帧走两步，动画快进）。
     *
     * ★ **为什么不用返回值 / 登记表**（第一版写的就是 `list<State>`，真机不生效）：
     *   登记表要在 `Application` 侧再问一遍 `element()` / `isMounted()` 才敢用，
     *   而那两跳在 AOT 下任何一个失手都会静默退化成"什么都没发生"。现在只有
     *   一次自增，没有可以失手的中间环节 —— 与 `markedCount` 同一个理由。
     */
    public function requestFrame(): void
    {
        $pipeline = Pipeline::current();
        $pipeline->scheduleNextFrame();

        // ★★ "我要下一帧"现在有**两个**半边（2026-11-03）：帧时钟只 tick 活跃表里的
        //   节点 ⇒ 光叫醒它不够，还得让我自己在表里，否则下一帧根本不会来问我。
        //   并进 `markDirty()` 那条通道：两者都是"我可能要动"的举手点。
        //   ★ 只入队、不置 `animationActive`（理由同 `markDirty()`）。
        $element = $this->element;
        if ($element !== null && !$element->isAnimationActive()) {
            $pipeline->noteActiveAnimation($element);
        }
    }

    /**
     * @internal `StatefulElement::rebuild()` 的第二句调它（第一句是 consumeDirty）
     *
     * ★ 唯一职责：`settle()` 的"有没有重建"判据（`rebuiltCount` 前后差）。build 期写
     *   的守卫已移到 `markDirty()`（见 `$buildingState`）—— 那里能区分"build 自己标脏"
     *   与"框架 `applyContext` 合法标脏"，不会再误杀主题 / 上下文切换。
     */
    public function noteRebuilt(): void
    {
        Pipeline::current()->noteRebuilt();
    }

    /**
     * @internal `rebuild()` 包住 `build()` 的进入 / 退出：标记"此刻正在 build 的 State"。
     *
     * ★ 仅此一个信号就够判 build 期写：`markDirty()` 看到 `$buildingState === $this`
     *   即知"build 里改了自己"。`applyContext` 的标脏发生在 build 之后，看不到这个标记。
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
     * 换新描述（§13.2）：Widget 不可变 ⇒ 参数**整体换新**。
     *
     * @internal 别在这里做脏活 —— 那是 `rebuild()` 的事。
     *
     * ★★ 2026-10-30 之前覆写者的注释写着「第一句必须是
     *   `parent::didUpdateWidget($widget)`，那是**唯一**把新 Widget 落到
     *   `$this->widget` 的地方」。那句话当时是对的，但它把一个**框架自己能做**的
     *   事推给了每一处覆写：漏掉 ⇒ `$this->widget` 停在旧值 ⇒ 读参数得到旧值
     *   （"改了筛选条件但结果不变"），**而且不报错**。本仓四处覆写
     *   （`LazyListState` / `AnimatedOpacityState` / `TextFieldState` …）全都老实写了那一句 —— 说明纪律能守，但不该让纪律存在。
     *
     * ⇒ 现在由 {@see StatefulElement} 在调本钩子**之前**用
     *   {@see self::adoptWidget()} 落好。这里保留赋值作**幂等兜底**（覆写者习惯性
     *   调 `parent::` 也完全无害），于是"漏调 parent"从静默 bug 变成本地无副作用。
     */
    public function didUpdateWidget(Widget $widget): void
    {
        $this->widget = $widget;
    }

    /**
     * 框架落点：`StatefulElement` 在调用**用户钩子** {@see self::didUpdateWidget()}
     * 之前先把新描述交过来。
     *
     * ★ 为什么单独一个方法而不是让 `StatefulElement` 直接写 `$this->widget`：
     *   字段是 `private`（`State` 之外不该有人碰）。这条封装不该以"每处覆写都要
     *   记得调 parent"为代价 —— 那是把封装成本转嫁给调用方。
     *
     * @internal 只有 `StatefulElement::didUpdateWidget()` 调它。
     */
    public function adoptWidget(Widget $widget): void
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

    /**
     * 描述子层。
     *
     * ★ **默认实现只为"直接产盒"的控件存在**（`StatefulWidget::
     *   createRenderObjectFromState()` 返回非 null 的那些，`02` §6.2）——
     *   它们的 `build()` **永远不会被调用**（`StatefulElement::rebuild()` 看到
     *   `$boxFromState` 就不走这条路）。
     *
     * ★ **为什么从抽象改成有默认实现**：2026-10-23 之前 `StatefulWidget` 不产盒
     *   ⇒ 每个 State 都必须 `build()`，抽象是准确的。叶子路径落地后"有态 + 有盒 +
     *   无子"的控件不再需要 `build()`，而 PHP/AOT 都无法表达"`build()` 与
     *   `createRenderObjectFromState()` 二选一必填"（无反射）⇒ 默认实现是
     *   唯一可编译的形态。
     *
     * ★ **为什么返回零尺寸而不是抛异常**：抛异常会把"忘记 override"变成 Fatal，
     *   而那个遗漏本身无害（返回值没人读 —— 叶子控件根本不经过这里）。真出错的
     *   现象是"控件不显示"，不是崩溃。这是一处**契约弱化**，诚实标注。
     *
     * ★ 防线：smoke 有一条"叶子控件的 `build()` 零调用"的断言（计数器夹具）。
     */
    public function build(BuildContext $ctx): Widget
    {
        return SizedBox::make()->size(0.0, 0.0);
    }
}
