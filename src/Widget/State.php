<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Commands\CommandArg;
use Drift\Errors;
use Drift\Layout\RenderBox;
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
    /**
     * @internal 未消费的脏 State 数 —— `Element::settle()` 收敛循环的 O(1) 提前退出条件
     *
     * ★ **静态**：`Frame::run()` 里主树与浮层两棵树共用一份计数。这是**对的** ——
     *   若浮层的 build 标脏了主树的 State，主树的 `settle()` 会看到非零计数并
     *   多跑一遍（`03` §5.4）。
     * ★ 漏减只会让每帧多扫一遍（性能退化，**不报错**）⇒ smoke 有"跑 100 帧后
     *   `pendingDirty() === 0`"的夹具守着（`03` §10）。`settle()` 里另有
     *   "这一遍什么都没重建就 break"的兜底，防止漂移被误判成 E-FLUSH-CYCLE。
     */
    private static int $pendingDirty = 0;

    /**
     * @internal 正在 `build()` 的 State —— `markDirty()` 用它识别"build 期写自己"
     *
     * ★ **为什么是"build 期写自己"而不是"同一次 settle 两次 rebuild"**：真机上
     *   `Element::applyContext()`（主题 / 上下文切换）会合法地给 StatefulElement
     *   标脏，形态和"build 期写"完全一样（同一次 settle 多次重建），但那是框架
     *   自身的必要传播，**不是 bug**。`applyContext` 发生在 `build()` 之外
     *   （`rebuild()` 调完 `build()` 之后才走 `updateChildren` → `applyContext`），
     *   所以只在 `build()` 调用栈内标脏自己才判 E-FLUSH-CYCLE —— 精准、零误杀。
     */
    private static ?State $buildingState = null;

    /** @internal 本帧累计 rebuild 次数 —— `settle()` 的"有没有重建"判据（前后差） */
    private static int $rebuiltCount = 0;

    /**
     * 本帧挂载的 State（`callInit()` 登记，`Application::frame()` 第 ⑦ 段消费）。
     *
     * ★ 存在理由（`06` §4.2）：`tickFrame()` **必须**在 settle 之前跑，而挂载**必然**
     *   发生在 settle 期间 ⇒ "这一帧刚挂上的 State"在第一次 tick 时还不在树里。
     *   改动前是靠**再遍历一遍全树**补上（`tickFrameStamped` 的帧戳防重复推进），
     *   代价是每帧多 O(树) 次虚调用，只为照顾通常 0–5 个新挂载。
     *
     * ★ **登记点是 `callInit()`**（已在 `StatefulElement::onMount()` 里被调）
     *   ⇒ 不新增调用点，只加一行。
     *
     * ★ **必须被帧消费**：不消费就会一直累积（挂着已卸载的 State）。所以
     *   `Application::frame()` 每次都 `takeMountedThisFrame()`，且循环里**跳过
     *   已卸载的**（`mount()` 了但一直没有帧到来的场景：测试、纯头侧夹具）。
     *
     * @var list<State>
     */
    private static array $mountedThisFrame = [];

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
        $this->markDirty();
    }

    /**
     * 只标脏、不改值（§11.3.1）。
     *
     * ★ `Cell::setIfChanged()` 与 `SubjectBuilder` 的回调走这条 —— 它们已经在
     *   自己的上下文里改完值了，不需要再裹一层空闭包。
     *
     * ★ **什么时候用它而不是 `setState()`**：值已经改完了（`Cell` 刚 set 过、
     *   动画刚 tick 过、输入刚落过），只差"告诉框架该重建了"。包一层空闭包
     *   也能跑，但读者会以为"这里要改点什么"—— 而这里没有。
     *
     * ★ **它不比较值**：同 `setState()`，想"设同值不重建"就把值装进 `Cell`。
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
        self::$pendingDirty++;
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
     * 唯一的 `Cell` 创建入口（§11.3.1）。
     *
     * ★ 为什么不能 `new Cell(-1)` 直接放属性初始化器：PHP 的属性初始化器
     *   **拿不到 `$this`**，`Cell` 便无从知道该标脏哪个 `State` —— 于是"
     *   设了值但没人重建"。绑定必须显式发生，`cell()` 就是那一个显式点。
     *
     * ★ `Cell::__construct` 是 `private` ⇒ "绕过 `cell()` 直接 new"这个写法
     *   **编译期就写不出来**（运行期抛 `Error`），不再靠"未绑定 ⇒ Fatal"
     *   兜底（`03` §3.5：把运行期 Fatal 换成更早、更近出错点的 Error）。
     *
     * @api
     */
    public function cell(mixed $initial): Cell
    {
        return Cell::makeFor($this, $initial);
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
        self::$mountedThisFrame[] = $this;   // ★ 挂载登记（`06` §4.2）
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
            self::$pendingDirty--;
        }

        return $dirty;
    }

    /** @internal 收敛循环的提前退出条件（`Element::settle()` 用） */
    public static function pendingDirty(): int
    {
        return self::$pendingDirty;
    }

    /** @internal 本帧累计 rebuild 次数（`settle()` 用前后差判断"有没有重建"） */
    public static function rebuiltCount(): int
    {
        return self::$rebuiltCount;
    }

    /**
     * @internal 渲染树"需要重排"的绝对序号 —— `Frame` 与它**上一帧记下的值**比对。
     *
     * ★ 转发到 `RenderBox::layoutEpoch()`：盒换代与盒内输入变化（滚动偏移）
     *   必须**共用**一个序号，否则 `Frame` 要判两次、而且两处迟早漂。
     */
    public static function boxEpoch(): int
    {
        return RenderBox::layoutEpoch();
    }

    /**
     * @internal `Element::syncBox()` 每调一次就加一（那里是唯一的盒创建点）。
     *
     * ★ 故意做成**静态**而不是 `Element` 的字段：判据要跨**整棵树**（含浮层那棵
     *   第二根树），逐个 Element 记账需要遍历；一个全局序号就能表达"有没有换过"。
     * ★ 转发到 `RenderBox::markNeedsLayout()` —— 同一个计数器的另一个入口
     *   （`setScrollOffset()` 那条路也加它）。
     */
    public static function noteBoxReplaced(): void
    {
        RenderBox::markNeedsLayout();
    }

    /**
     * 取走本帧挂载的 State 并**清空**登记表（`06` §4.2）。
     *
     * @return list<State>
     * @internal 由 `Application::frame()` 第 ⑦ 段调（每帧一次，必须调）
     */
    public static function takeMountedThisFrame(): array
    {
        $list = self::$mountedThisFrame;
        self::$mountedThisFrame = [];

        return $list;
    }

    /** @internal 夹具用：登记表必须被消费干净（不消费会无限累积） */
    public static function mountedCount(): int
    {
        return count(self::$mountedThisFrame);
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
        self::$rebuiltCount++;
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
