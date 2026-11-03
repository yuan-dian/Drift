<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Core\Pipeline;
use Drift\Input\CursorShape;
use Drift\Input\FocusNode;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Widget\Element;
use Drift\Widget\State;
use Drift\Widget\EditableText;
use Drift\Widget\States;

/**
 * **焦点链 + 输入路由的最小实现**（§9.1 路由链的 ②③ 环与命中测试）。
 *
 * ```text
 * KeyEvent ──▶ ⓪编辑命令（剪贴板，`TextEditingBridge::dispatchEditCommand`）
 *          ──▶ ①焦点链 ← 本类（`dispatchKey`）
 *          ──▶ ②加速键（InputRouter，`AcceleratorTable::resolve`）
 *          ──▶ ③动作激活（Enter/Space）
 * PointerEvent ──▶ 命中测试（RenderBox 树）──▶ 焦点 ← 本类
 * ```
 *
 * ★★★ **2026-11-03 顺序裁决（破坏性）**：①与②**换了位**。此前是加速键先、
 *   靠 `FocusNode::claimKeys()` 白名单给个别控件开口子 —— 白名单是逐键列举的，
 *   `Table` 非编辑态的 `Ctrl+A`/`Ctrl+C` 就一直收不到（应用一绑那两个键就是死码），
 *   编辑态的 `Ctrl+Z` 更需要"进编辑补声明、退出撤掉"这种静态声明做不到的动态让位。
 *   现在"谁处理谁赢"：焦点控件认得就吃、认不得返回 `false` 自然落给加速键。
 *   ★ 判据见 `tests/Feature/Input/KeyRoutingPriorityTest`（三条必须成对验）。
 *
 * ★ 上面 ①② 原来写着"（未做）"，2026-11-02 核实为**假话**：加速键在 `InputRouter`
 *   的 ② 环（`accelerators()->resolve()`），IME 在 `cpp-src/drift_win32.cc` 的
 *   `WM_IME_STARTCOMPOSITION / _COMPOSITION / _ENDCOMPOSITION` 三段 + `TextEditingHost`。
 *   本类只负责 ① —— **"本类不做"不等于"框架没做"**：那张图画的是**路由链**，
 *   不是本类的职责边界，写混了就变成在源码里散播过期结论。
 *
 * ★ **为什么住 `Drift\App` 而不是 `Drift\Input`**：`Input` 按 §4.3 **不许依赖
 *   `Widget` / `Rendering`**，而本类必须引用 `Element` 与 `EditableText`。
 *   §9.1 本来就把路由判给 `EventLoop`（`Drift\App`）—— 这里只是把那条裁决落成代码，
 *   不含 EventLoop 的**循环**能力（`waitEvents()` 由平台驱动，§5.2 裁决 1）。
 *
 * ★ **它是 `@internal`**：§4.2 的 `Drift\App` 行没有这个名字 —— 焦点链是
 *   路由的**实现细节**，不是公共契约。公共面是 `FocusNode`（声明）与
 *   `KeyEvent` / `PointerEvent`（数据），都在 `Drift\Input`。
 *
 * ★ **焦点链是"渲染态"**（§9.1）：本类只存 `?Element`，不碰任何业务值。
 *   切焦点时经 `State::didChangeFocus()` 同步编辑态（`EditableText` 的
 *   `beginEditing()` / `endEditing()`）—— 于是"插入符画不画"自动跟着焦点走。
 *
 * @internal
 */
final class FocusManager
{
    /** @var list<Element> 文档序（深度优先），同容器内按 `focusOrder` 稳定排序 */
    private array $order = [];

    /** @var array<int, Element> `spl_object_id` ⇒ Element（判断"焦点还在不在链里"） */
    private array $byId = [];

    private ?Element $focused = null;

    /**
     * 上次 `collect()` 时的内容版本号（`Pipeline::contentVersion()`；`null` = 还没收过）。
     *
     * ★ 与 `$collectedRoots` 一起构成"要不要重收"的签名，见 `collect()` 的注。
     */
    private ?int $collectedVersion = null;

    /** @var list<int> 上次收的根签名（陷阱开关 + `spl_object_id` 序） */
    private array $collectedRoots = [];

    /**
     * 本次 `collect()` 是否处于**焦点陷阱**（arch-v2 `01` §6.4）。
     *
     * ★ 陷阱只改一件事：Tab 到边界时**在链内循环**（不环绕 = 用户在对话框里
     *   从最后一个控件再按 Tab 就卡住不动，Windows 对话框不是这个手感）。
     *   非陷阱维持既有裁决「到末尾就停」—— 主树的 Tab 不该绕回第一个控件。
     */
    private bool $trapped = false;

    /**
     * 最近一次指针命中解析出的 Element（O-20 的锚点来源）。
     *
     * ★ 菜单打开的锚点不能用坐标（命令是"意图"，不带位置 —— §11.4.3）⇒
     *   `Application::showMenu($panel)` 不给锚点时就取这个。
     */
    private ?Element $lastHit = null;

    /**
     * 当前悬停目标（`hoverHandlerValue()` 声明者链上的最近 Element）。
     *
     * ★ 与 `DragController::$hover` **同模式但独立**：普通悬停常驻（任何 move
     *   刷新），拖放悬停只在拖动期活跃 —— 两个职责，同一条"目标变化才派发"
     *   纪律（§9.1 语义 #5）。
     * ★ **按 Element 实例比较是稳的**：Element diff 复用实例（§13.2"换描述换盒"
     *   说的是**盒**，Element 实例跨重建存活）⇒ 悬停高亮 setState ⇒ 重建后
     *   同一个实例还在，不会自激 leave/enter。
     */
    private ?Element $hovered = null;

    /**
     * 悬停**原始命中**变化的监听口（W8-2 Tooltip，2026-10-23）。
     *
     * `\Closure(?Element): void`，收到的是**未解析的命中元素**（不是 `$hovered`）。
     *
     * ★ 为什么不复用 `hoverHandler`：那个声明者链找的是"有 `hover()` 的控件"，
     *   而 Tooltip 的声明者可能**没有** `hover()`（也不需要）—— 两个集合不同源。
     * ★ 为什么收原始命中：消费方（`Application`）要沿父链找 `tooltipTextValue()`，
     *   在这里替它解析就把"Tooltip 是什么"的知识漏进了焦点层。
     * ★ 默认 `null` ⇒ 一次 `if` 都不多做，逐字节不变。
     */
    private ?\Closure $hoverListener = null;

    /**
     * 上一次的**原始命中**元素（`hoverTo()` 收的那个，未经 `hoverOwner` 解析）。
     *
     * ★ 与 `$hovered` 分开存：`$hovered` 是"有 `hover()` 声明的最近祖先"，
     *   本字段是"指针真的落在谁身上"—— 悬停高亮要前者，Tooltip 要后者。
     */
    private ?Element $hoverHit = null;

    /**
     * 当前处于**悬停态**的渲染盒（交互状态层，`08` §4.1）。
     *
     * ★ 与 `$hovered` / `$hoverHit` 是**三条独立判据**：
     *   - `$hoverHit` = 指针真的落在谁身上（Tooltip 要）；
     *   - `$hovered` = 有 `hover()` 声明的最近祖先（回调要）；
     *   - `$hoverBox` = 沿盒父链第一个 `acceptsHover()` 的盒（视觉要）。
     * ★ 存盒而不是 Element：`acceptsHover()` 在 `RenderObject` 上；盒在重建时会换新的，
     *   但每次 move 都会重推到新盒，旧盒已离开树，不会留着"永远亮着"。
     */
    private ?RenderObject $hoverBox = null;

    /** 注入悬停命中监听（`null` 回调不注册即默认行为） */
    public function onHoverChanged(\Closure $listener): void
    {
        $this->hoverListener = $listener;
    }

    /**
     * 从根重建焦点链（挂载 / 更新后调）。
     *
     * ★ **浮层是第二棵根渲染树**（O-20）⇒ 它的焦点链也要能进 Tab 序 ⇒
     *   调用方把每层浮层的 element 都放进 `$roots`（文档序 = 主树在前、浮层在后）。
     *
     * ★ **`$scope` = 焦点陷阱**（arch-v2 `01` §6.4）：非空时**只收这几个根**，
     *   且 Tab 在链内循环。调用方给的是"最上面那个 `focusTrap` 条目 + 它上面
     *   压着的所有层" —— 这样"模态里开 Select 下拉"时下拉也能 Tab 到
     *   （下拉是独立根，不在这模态的子树里）。
     *
     * ★ 树变了 ⇒ 原来的焦点 Element 可能已被移除 ⇒ 这里**显式清掉**，
     *   不留一个指向死节点的焦点（那会让后续按键静默丢掉）。
     *
     * @param list<Element> $roots 全部要收的根（主树 + 各层浮层）
     * @param list<Element> $scope 非空 ⇒ 焦点陷阱，只收这些根且循环
     */
    public function collect(array $roots, array $scope = []): void
    {
        // ★★ **门控：树的内容没变就不用重收**（2026-11-01）。
        //   此前每帧无条件全树扫一遍，而 `Frame` 的 layout / paint 都有门控 ⇒
        //   稳态帧里唯一的大头就是这里（实测 2000 节点 1.6ms/帧，
        //   `tools/_tax-probe.php`；一帧的固定税里它是最大单项）。
        //
        //   ★ 判据用 `contentVersion()` 而不是 `structureVersion()`：
        //     后者看不见"`disabled()` 改了但节点没增删"，而那张表必须跟着变。
        //   ★ 根集合也要比：浮层开合不进主树，`contentVersion` 看不见它
        //     （与 `Frame` 的 paint 门控第 ⑤ 条同一个坑，已踩过一次）。
        //   ★ 用 `spl_object_id` 比根是安全的：根被卸载必走 `unmount()` ⇒
        //     `bumpStructure()` ⇒ 版本变 ⇒ 根本轮不到 id 复用这件事。
        $effective = $scope !== [] ? $scope : $roots;
        // 签名 = 陷阱开关 + 根集合（陷阱开关必须进签名：`roots` 相同而
        // `scope` 从空变非空时，收的面完全不同）
        $rootIds = [$scope !== [] ? 1 : 0];
        foreach ($effective as $root) {
            $rootIds[] = \spl_object_id($root);
        }
        $version = Pipeline::current()->contentVersion();
        if ($version === $this->collectedVersion && $rootIds === $this->collectedRoots) {
            return;
        }
        $this->collectedVersion = $version;
        $this->collectedRoots = $rootIds;

        $this->order = [];
        $this->byId = [];
        $this->trapped = $scope !== [];

        foreach ($effective as $root) {
            $this->walk($root);
        }

        if ($this->focused !== null && !isset($this->byId[spl_object_id($this->focused)])) {
            $this->leave($this->focused);
            $this->focused = null;

            // ★ 焦点因**元素卸载**而丢失（`collect()` 在帧内跑）⇒ 焦点环还留在
            //   旧位置，必须再画一帧把它擦掉（见 `focus()` 的注）。
            //   ★ 用**立刻**档：这一帧的 `paintRequested` 已经在 `Frame::run()`
            //   之前算好了，来不及回头 ⇒ 只能要下一帧。
            Pipeline::current()->scheduleFrame();
        }

        if ($this->lastHit !== null && !$this->lastHit->isMounted()) {
            $this->lastHit = null;
        }

        // 悬停目标离线（菜单关了 / 子树被换掉）⇒ 显式退出它 —— 否则"菜单项
        // 高亮"在菜单关闭后留在最后一个悬停项上（静默错形态）。
        if ($this->hovered !== null && !$this->hovered->isMounted()) {
            self::fireHover($this->hovered, false);
            $this->hovered = null;
        }

        // ★ 原始命中离线同理（Tooltip）：`$hovered` 的清理管不到它（两者不同源），
        //   不通知的话消费方会握着一个死锚点继续显示提示 —— 而 `placeOverlays()`
        //   的清扫只是把浮层拿掉，`$tooltipShown` 之类的状态会留在"已显示"。
        if ($this->hoverHit !== null && !$this->hoverHit->isMounted()) {
            $this->hoverHit = null;

            if ($this->hoverListener !== null) {
                ($this->hoverListener)(null);
            }
        }
    }

    /** 最近一次命中的 Element（浮层锚点用；未命中过 / 已离线 ⇒ `null`） */
    public function lastHit(): ?Element
    {
        return $this->lastHit;
    }

    /**
     * 命中的盒 ⇒ 它对应的 Element（右键找 `ContextMenu` 声明者要用）。
     *
     * ★ 从**命中盒**反查（不是从坐标重算）—— §9.1「一次命中，三方消费」。
     */
    public function elementAt(Element $root, RenderBox $hit): ?Element
    {
        return $this->elementForBox($root, $hit);
    }

    /** @return list<Element> 当前焦点链（文档序） */
    public function order(): array
    {
        return $this->order;
    }

    public function focused(): ?Element
    {
        return $this->focused;
    }

    /**
     * 焦点节点的 `State`（S1 第 2 步：焦点穿透知识的**唯一出口**）。
     *
     * ★ 调用方（IME 光标 / 编辑态门控）要的都是"焦点上的 State"，不是
     *   "焦点 Element 本身"；让它们各自 `focused()` + `instanceof StatefulElement`
     *   一遍，就是本步要收编的那几处认知。
     */
    public function focusedState(): ?State
    {
        return States::of($this->focused);
    }

    /** 焦点落在一个节点上（切走旧焦点、进入新焦点，编辑态跟着走） */
    public function focus(Element $element): void
    {
        if ($this->focused === $element) {
            return;
        }

        $this->leave($this->focused);
        $this->focused = $element;
        $this->enter($element);

        // ★★ **焦点变了 ⇒ 立刻要一帧**（2026-11-02，技术债 D1）：焦点环是应用级
        //   叠加（`Application::paintFocusRing()`），不经过任何 `setState` ⇒
        //   没有任何脏信号。以前这条靠 `wantsFrame()` 里每轮重问
        //   `focusRingTarget() !== paintedFocus` 兜住；换成"现场举手"之后，
        //   变化点必须自己举手，否则键盘 Tab 换焦点后环要等**下一次鼠标移动**
        //   才出现（真机现象，`tests/Feature/App/FocusRingTest` 守着）。
        //   ★ 档位是**立刻**：环晚 15ms 出现就是肉眼可见的迟滞。
        Pipeline::current()->scheduleFrame();
    }

    /** Tab：前进一格。到末尾**就停**（不环绕 —— 与 Windows / Flutter 一致） */
    public function focusNext(): bool
    {
        return $this->step(1);
    }

    /** Shift+Tab：后退一格 */
    public function focusPrevious(): bool
    {
        return $this->step(-1);
    }

    /**
     * 派发一次按键（§9.1 第 ③ 环）。
     *
     * 顺序：`Tab` / `Shift+Tab`（焦点链自己的键）⇒ **焦点控件或其祖先的 `keyboard()`
     * 回调**（W8-2）⇒ 焦点节点的 `handleKey()`。
     * 返回 `false` ⇒ **这一环没吃掉**，上层（将来的加速键 / IME）可以继续。
     *
     * ★★ **`keyboard()` 沿父链找"最近的声明者"**（2026-10-24 修）：此前只读
     *   `$this->focused?->widget()` ⇒ **包裹式控件的声明是死的**。`ComboBox` 包一个
     *   `TextField` 时，焦点落在 `TextField` 上，包在外层的 `ComboBox` 声明
     *   `keyboard()` 永远收不到键（它要拦 Enter / 方向键 / Esc 来驱动浮层）。
     *   这与 `Widget::keyboard()` docblock 写的"任何控件都能挂"**不符** —— 文档
     *   承诺了一件代码没做到的事，比没有这个特性更贵。
     *
     * ★ **与 `pointer()` / `contextMenu()` 同一条规则**：那两条早就沿父链找最近
     *   声明者（`Application::pointerOwner()` / `openContextMenu()`），唯独
     *   `keyboard()` 漏了。三条缝现在同构，"最深的声明赢"。
     *
     * ★ **对现有代码可证明是加性**：全仓三处 `keyboard()` 声明
     *   （`Slider` / `Select` / `PressLabApplication`）都挂在**自己就是焦点控件**
     *   的 widget 上 ⇒ 父链第一跳就命中，逐字节不变。
     *
     * ★ 为什么 `keyboard()` 仍排在 `EditableText` 之前：两者都是"焦点控件处理按键"，
     *   但 `keyboard()` 是**声明式**的（任何控件都能挂），`EditableText` 是状态机。
     *   一个控件同时有两者几乎不可能（输入框不会自己声明方向键语义），
     *   真撞上了让显式声明优先 —— 应用总比框架更知道这个键该干嘛。
     * ★ 这条缝让"新交互控件"不必再往 `Application::activateFocusedWidget()`
     *   的 `instanceof` 塔里加分支（Slider 的方向键 / Select 的上下选 / DatePicker
     *   的翻页 / ComboBox 的浮层驱动全都走这里）。
     */
    public function dispatchKey(KeyEvent $event): bool
    {
        if ($event->chord->is(KeyChord::tab())) {
            return $this->focusNext();
        }

        if ($event->chord->is(KeyChord::shift('Tab'))) {
            return $this->focusPrevious();
        }

        // ★ 焦点控件**或它祖先**里最近的那个 `keyboard()` 声明者（见方法注释）。
        //   找不到 ⇒ 这一环让开（`null` handler 与"返回 false"同义）。
        for ($node = $this->focused; $node !== null; $node = $node->parent()) {
            $handler = $node->widget()->keyHandlerValue();
            if ($handler === null) {
                continue;
            }

            // ★ 与 `pointer()` / `hover()` 同形：`(KeyEvent, Element)`。
            //   老的单参 handler（`function (KeyEvent $e)`）在 PHP 里会忽略多余参数
            //   ⇒ 不需要一次性改所有调用点；新控件（`RichText`）可以拿 Element 找盒。
            if ($handler($event, $node) === true) {
                return true;
            }

            break;   // 最近的声明者没吃掉 ⇒ 不再往上找（更远的祖先更不具体）
        }

        $state = $this->focusedState();

        if ($state instanceof EditableText) {
            return $state->handleKey($event);
        }

        return false;
    }

    /**
     * 派发一次指针按下：命中测试 ⇒ 焦点（§9.1）。
     *
     * ★ 它是 `hitTest()` + `focusHit()` 的组合体，**保留给"只关心焦点"的调用方**
     *   （`drift-preview --focus` / smoke）。真机的输入派发走
     *   `Application::dispatchInput()` —— 那里一次命中要同时喂给焦点与光标。
     */
    public function dispatchPointer(PointerEvent $event, Element $root): bool
    {
        return $this->focusHit($this->hitTest($event->x, $event->y, $root), $root);
    }

    /**
     * 命中测试（§9.1）：**一次走树**，结果供多方消费。
     *
     * ★ 单独抽出来是因为 §9.1 的「一次命中，三方消费」（事件路由 / 语义 /
     *   光标形状）**明确要求不重复走树** ⇒ 调用方先拿一次命中结果，再分别喂给
     *   `focusHit()` 与 `cursorOf()`，而不是各自 hitTest 一遍。
     */
    public function hitTest(float $x, float $y, Element $root): ?RenderBox
    {
        return $root->effectiveBox()?->hitTest($x, $y);
    }

    /**
     * 命中链解析光标形状（§9.1「三方消费」的第 ③ 方）。
     *
     * ★ 从命中的盒**向上**找最近的声明者 ⇒ "给 `Column` 声明一次，里面所有子节点
     *   都生效"，而子节点自己声明时覆盖祖先。
     * ★ 收的是**已经命中的盒**（不是坐标）：不重复走树（见 `hitTest()`）。
     * ★ 一路没人声明 ⇒ `CursorShape::Default`（**不是** `null` —— 见该枚举的
     *   docblock："未声明"与"明确要箭头"是两件事）。
     */
    public function cursorOf(?RenderBox $hit): CursorShape
    {
        for ($node = $hit; $node !== null; $node = $node->parent()) {
            $shape = $node->cursor();

            if ($shape !== null) {
                return $shape;
            }
        }

        return CursorShape::Default;
    }

    /**
     * 指针移动 ⇒ 刷新悬停目标（§9.1 语义 #5：**只在目标变化时派发**）。
     *
     * ★ `null` = 没命中 / 离开客户区 ⇒ 退出当前悬停目标。
     * ★ **命中一次、只喂一处**：与 `cursorOf()` 同源（收的是已解析的 Element，
     *   不重复走树）。
     */
    public function hoverTo(?Element $element): void
    {
        // 悬停态视觉先推（`08` §4.1）—— 与下面的 `hoverOwner`（回调）是**两条独立
        // 判据**：一条只认 `acceptsHover()` 的盒，一条只认声明了 `hover()` 的祖先。
        // 同一个 Button 通常两条都命中；"没声明 hover() 的 Button"只走这条（正是
        // "悬停毫无反馈"的修法）。
        $this->applyHoverState($element);

        // ★ 原始命中先过监听口（Tooltip）：与下面的 `hoverOwner` **两条独立判据**
        //   —— 指针停在同一个元素上移动时两者都不该再派发一次。
        if ($element !== $this->hoverHit) {
            $this->hoverHit = $element;

            if ($this->hoverListener !== null) {
                ($this->hoverListener)($element);
            }
        }

        $next = self::hoverOwner($element);

        if ($next === $this->hovered) {
            return;
        }

        $previous = $this->hovered;
        $this->hovered = $next;

        self::fireHover($previous, false);
        self::fireHover($next, true);
    }

    /** 当前悬停目标（测试 / 预览读） */
    public function hovered(): ?Element
    {
        return $this->hovered;
    }

    /**
     * 悬停态视觉（`08` §4.1）：沿**盒父链**找第一个 `acceptsHover()` 的盒，
     * 旧的复位、新的置位。
     *
     * ★ 从 `effectiveBox()` 起：命中的 Element 可能是 `StatefulWidget`（自己的盒在
     *   子元素上），`effectiveBox()` 已经处理了这一点。
     * ★ 问 `acceptsHover()` 而不是 `instanceof RenderButton`：`App` 层不得认识
     *   `Layout` 的具体类（I2 闸门）—— 这是"能力方法"的第二个消费者（第一个是
     *   `hitsModalPanel()` 问 `panelRect()`）。
     */
    private function applyHoverState(?Element $element): void
    {
        $next = null;
        for ($box = $element?->effectiveBox(); $box !== null; $box = $box->parent()) {
            if ($box->acceptsHover()) {
                $next = $box;

                break;
            }
        }

        if ($next === $this->hoverBox) {
            return;
        }

        $this->hoverBox?->setHovered(false);
        $this->hoverBox = $next;
        $this->hoverBox?->setHovered(true);

        // ★ 这里**不需要**请求"全树发现"：`setHovered()` 内部会
        //   `hoverAnim->toward()`，而 `AnimatedValue::toward()` 已经报了
        //   "帧时钟该发现我"（2026-11-03 的动效活跃表）。在这一层再报一次
        //   只是把同一次全扫多跑一遍。
    }

    /**
     * 把**已经算好的命中**落到焦点上（§9.1 的第 ① 方）。
     *
     * ★ 命中的**不是**可聚焦节点（点了空白 / `Text`）⇒ 返回 `false` 且**不清焦点**
     *   —— "点空白丢焦点"是另一条设计决定，不在这里偷偷做掉。
     */
    public function focusHit(?RenderBox $hit, Element $root): bool
    {
        if ($hit === null) {
            return false;
        }

        // ★ 命中的是**最深**的盒，但焦点链收的是**声明可聚焦的那个 Element**。
        //   `TextField` 是 `StatefulWidget` ⇒ 自己没盒，命中的是它 build 出来的
        //   `TextFieldBox`（`RenderBoxWidget`）⇒ 必须沿父链上溯到最近的焦点链成员，
        //   否则"点得到、却给不了焦点"（且不报错）。
        $element = $this->elementForBox($root, $hit);

        // 锚点记的是**最深命中**（不是上溯后的可聚焦节点）：菜单要钉在被点的那个
        // 控件旁边，而"可聚焦的那个"可能大得多（§5.1 裁决 1 的锚点来源）。
        $this->lastHit = $element;

        while ($element !== null && !isset($this->byId[spl_object_id($element)])) {
            $element = $element->parent();
        }

        if ($element === null) {
            return false;
        }

        $this->focus($element);

        return true;
    }

    // ── 内部 ────────────────────────────────────────────────────────────

    /**
     * @param bool $skipped 祖先（或自己）声明了 `skipFocus()` ⇒ 本子树不进 Tab 序
     * @param bool $disabled 祖先（或自己）声明了 `disabled()` ⇒ 本子树**不可交互**
     */
    private function walk(Element $element, bool $skipped = false, bool $disabled = false): void
    {
        $widget = $element->widget();
        $node = $widget->focusNode();

        // `skipFocus()` 是**子树级**（§A.3.4：AppBar 工具组整体不进 Tab 序）
        $skip = $skipped || ($node !== null && $node->skip);

        // ★ `disabled()` 也是子树级，但**动的表和 `skipFocus()` 不同**：
        //   `skipFocus()` 只挡 Tab 序、保留"点了能拿焦点"；禁用连后者一起取消。
        //   所以这里不并入 `$skip`，而是单独一个开关（并入的话"禁用但可点"就成了
        //   `skipFocus()` 的隐式副作用，两者再也分不开）。
        $off = $disabled || $widget->mods()->disabled();

        if (!$off && $widget->isFocusable()) {
            // ★★ `byId` 与 `order` 是**两件事**（2026-10-29 拆分）：
            //   · `byId` = "**可聚焦**元素表" —— `focusHit()` 拿它回答"点到的这个东西
            //     能不能拿焦点"，答案只能是"它自己声明了可聚焦"；
            //   · `order` = **Tab 序** —— `skipFocus()` 唯一该动的是这张。
            //   早先两者共用一张表（`skip` 时 `return`，连 `byId` 都不登记）⇒
            //   `skipFocus()` 的真实语义变成"**这棵子树既不能 Tab 到、也点不动**"。
            //   踩点：`TreeView` 的行箭头（`IconButton` + `skipFocus()`）点了毫无反应，
            //   而画面上箭头好好地在那儿 —— 没有任何可观测的线索指向焦点表。
            //
            // ★ 禁用则**两张都不进**（这正是"不可交互"的定义）；连带的好处是
            //   `collect()` 末尾那条"focused 不在 byId ⇒ leave()"自动把焦点挪走 ——
            //   不需要在 `disabled()` 里手工找焦点。
            $this->byId[spl_object_id($element)] = $element;

            if (!$skip) {
                $this->order[] = $element;
            }
        }


        // ★★ **只在真的有人声明 `focusOrder` 时才排序**（2026-11-01）。
        //   此前每个容器都收集一遍子元素再 `usort`，而比较器里是两次
        //   `widget()->focusNode()` 链式调用 ⇒ 全树每帧一次。实测（`tools/_tax-probe.php`，
        //   2000 节点）`focus->collect()` 独占稳态帧 2.46ms 的**绝大多数**，而
        //   稳态帧里 layout / paint 全被门控跳过 ⇒ 这笔税每帧都付。
        //   ★ 没有 `order` 时比较器恒返回 0、顺序不变 ⇒ **跳过是等价变换**，
        //     不是"改语义换性能"。有 order 的容器仍然走原路径。
        $anyOrder = false;
        $element->visitChildren(static function (Element $child) use (&$anyOrder): void {
            if ($child->widget()->focusNode()?->order !== null) {
                $anyOrder = true;
            }
        });

        if (!$anyOrder) {
            // 无需排序 ⇒ 直接按文档序递归（省掉 `$children` 数组与一次 usort）
            $element->visitChildren(function (Element $child) use ($skip, $off): void {
                $this->walk($child, $skip, $off);
            });

            return;
        }

        /** @var list<Element> $children */
        $children = [];
        $element->visitChildren(function (Element $child) use (&$children): void {
            $children[] = $child;
        });

        // 同一容器内按 `focusOrder` 稳定排序（§9.1）。混用"有/无"是 lint 的活，
        // 这里只求行为**确定**：没声明的排在声明的之后，彼此保持文档序。
        usort($children, static function (Element $a, Element $b): int {
            $oa = $a->widget()->focusNode()?->order;
            $ob = $b->widget()->focusNode()?->order;

            if ($oa === $ob) {
                return 0;
            }

            return ($oa ?? PHP_INT_MAX) <=> ($ob ?? PHP_INT_MAX);
        });

        foreach ($children as $child) {
            $this->walk($child, $skip, $off);
        }
    }

    private function step(int $delta): bool
    {
        $count = count($this->order);

        if ($count === 0) {
            return false;
        }

        $current = $this->focused === null ? -1 : $this->indexOf($this->focused);
        $next = $current + $delta;

        if ($next < 0 || $next >= $count) {
            // 焦点陷阱（模态）里循环；主树维持既有裁决「到末尾就停」
            if (!$this->trapped) {
                return false;
            }

            $next = ($next % $count + $count) % $count;
            if ($this->focused !== null && $this->order[$next] === $this->focused) {
                return false; // 链里只有一个节点：不自己派给自己
            }
        }

        $this->focus($this->order[$next]);

        return true;
    }

    private function indexOf(Element $element): int
    {
        foreach ($this->order as $index => $candidate) {
            if ($candidate === $element) {
                return $index;
            }
        }

        return -1;
    }

    /** 最深优先：先找子（组合节点的 `effectiveBox()` 会返回后代的盒，取最深才对） */
    /**
     * 命中盒 → 拥有它的 Element。
     *
     * ★ chrome 类（`RenderMenuItem` 等）**自绘**子内容（含文字）⇒ 最深命中盒
     *   常常没有任何 Element 拥有它（旧实现按"effectiveBox 精确等于命中盒"
     *   匹配，此时落空 ⇒ 返回 null）。此时必须沿**渲染树**上溯到第一个"有
     *   Element 的盒"—— 否则 chrome 控件（菜单项）的指针点击永远落不了焦点。
     *   键盘路径不经过这里（焦点序里直接取 focused()）⇒ 该缺陷一直没暴露。
     */
    private function elementForBox(Element $element, RenderBox $box): ?Element
    {
        $owners = [];
        $collect = function (Element $el) use (&$collect, &$owners): void {
            $own = $el->effectiveBox();
            if ($own !== null) {
                $owners[spl_object_id($own)] = $el;
            }
            $el->visitChildren($collect);
        };
        $collect($element);

        for ($node = $box; $node !== null; $node = $node->parent()) {
            $id = spl_object_id($node);
            if (isset($owners[$id])) {
                return $owners[$id];
            }
        }

        return null;
    }

    private function enter(?Element $element): void
    {
        States::of($element)?->didChangeFocus(true);
    }

    private function leave(?Element $element): void
    {
        States::of($element)?->didChangeFocus(false);
    }

    /** 沿父链找最近的悬停声明者（子声明覆盖祖先 —— 与光标同判据） */
    private static function hoverOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            if ($node->widget()->hoverHandlerValue() !== null) {
                return $node;
            }
        }

        return null;
    }

    /** 派发悬停进出（无声明者 / 无处理器 ⇒ 无事发生） */
    private static function fireHover(?Element $element, bool $entered): void
    {
        $handler = $element?->widget()->hoverHandlerValue();

        if ($handler !== null) {
            $handler($entered);
        }
    }
}
