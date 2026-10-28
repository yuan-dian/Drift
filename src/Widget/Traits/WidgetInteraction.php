<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Widget\Drag\Draggable;
use Drift\Widget\Drag\DragTarget;
use Drift\Widget\Menu\ContextMenu;

/**
 * `Widget` 的**交互面**：10 个回调槽（悬停 / 指针 / 窗口拖动 / 键盘 / 拖放 /
 * 右键菜单 / 工具提示 / 浮层面板 / 浮层签名）+ 11 个对应的读侧。
 *
 * ★ **成对出现**：每个槽都是一个 `xxx(Closure)` + 一个 `xxxValue()`。
 *   读侧存在的理由只有一个 —— 命中链/帧管线要能问"这个控件声明了什么"，
 *   而写侧只该在构建期被调用。把两者放同一个文件里，加新槽时不会漏掉读侧。
 * ★ 读侧标 `@internal`：应用不该读自己的槽（它写的时候就知道了）。
 *
 * @internal 与 `Widget` 同生共死
 */
trait WidgetInteraction
{
    /**
     * 声明"本控件及其子树可被拖动"（§A.3.5，应用内拖放）。
     *
     * ```php
     * Row::make()->children([...])->draggable(Draggable::make(payload: $user->id))
     * ```
     *
     * ★ 它是**声明**：拖动状态机住 `Drift\App\DragController`（与焦点链同源 ——
     *   都需要"命中的 Element"）。指针驱动已接线（2026-10-17，§24.2 #16）：
     *   down 记意图 → move 过阈值 begin → up 放置。
     *
     * @api
     */
    public function draggable(Draggable $value): static
    {
        $this->mutate();
        $this->draggable = $value;

        return $this;
    }

    /** @internal 拖动状态机读它；`null` = 不可拖 */
    public function draggableValue(): ?Draggable
    {
        return $this->draggable;
    }

    /**
     * 声明"本控件接受放置"（§A.3.5）。
     *
     * ```php
     * Container::make()->dragTarget(DragTarget::make()->onAccept(fn($id) => ...))
     * ```
     *
     * @api
     */
    public function dragTarget(DragTarget $value): static
    {
        $this->mutate();
        $this->dragTarget = $value;

        return $this;
    }

    /** @internal 拖动状态机读它；`null` = 不接受放置 */
    public function dragTargetValue(): ?DragTarget
    {
        return $this->dragTarget;
    }

    /**
     * 声明右键弹出的上下文菜单（§A.3.1；挂在**子树**上，命中链向上找最近的声明者）。
     *
     * ```php
     * Row::make()->children([...])->contextMenu(ContextMenu::make()->items([...]))
     * ```
     *
     * @api
     */
    public function contextMenu(ContextMenu $value): static
    {
        $this->mutate();
        $this->contextMenu = $value;

        return $this;
    }

    /** @internal 右键入口读它（`Application::dispatchInput()` 的指针分支） */
    public function contextMenuValue(): ?ContextMenu
    {
        return $this->contextMenu;
    }

    /**
     * 声明"指针悬停在我上面时通知我"（§9.1 语义 #5，2026-10-17 随 move 相位落地）。
     *
     * ```php
     * Button::make('删除', 'user.delete')->hover(function (bool $entered): void { ... })
     * ```
     *
     * ★ **只在目标变化时派发**（进入 / 离开各一次，回调里可放心 `setState`）——
     *   与 `DragController::hoverTo()` 同一条纪律（§9.1 语义 #5 同源）：指针在
     *   同一控件上移动不会重复通知。
     * ★ **回调签名** `function (bool $entered): void` —— 与 `DragTarget` 的
     *   `hoverHandler` 同形（两处悬停是同一契约的两种消费者）。
     *
     * @api
     */
    public function hover(\Closure $handler): static
    {
        $this->mutate();
        $this->hoverHandler = $handler;

        return $this;
    }

    /** @internal 悬停派发读它；`null` = 不参与悬停（交给命中链上更近的祖先） */
    public function hoverHandlerValue(): ?\Closure
    {
        return $this->hoverHandler;
    }

    /**
     * 声明"我要处理指针事件"（`06` §5 指针捕获 + W8-2 按下接管）。
     *
     * ```php
     * // ① 只要捕获期事件（按下仍走框架的激活/命令派发）
     * Button::make('锚点')->pointer(fn(PointerEvent $e, Element $self) => null);
     *
     * // ② 按下就归我（Slider 这类"位置即语义"的控件）
     * Slider::make()->value($v)->pointer(
     *     fn(PointerEvent $e, Element $self) => $this->handlePointer($e, $self),
     *     ownsPress: true,
     * );
     * ```
     *
     * ★ **捕获不是"能收到更多事件"**：捕获期指针事件**不再命中测试**（这是捕获的
     *   全部意义 —— 拖到控件外面仍然归我）。抬起 / 捕获者卸载 / 窗口失焦时自动释放。
     * ★ `$ownsPress = false`（默认）⇒ 只在捕获期被调，按下仍由框架激活；
     *   `true` ⇒ 命中链上最近的声明者**接管按下**：框架捕获它并把 `down` 相位交过来，
     *   跳过 `activateFocusedWidget()`。焦点照旧落上去（键盘可达）。
     * ★ 坐标是**客户区坐标**，要局部坐标就减 `RenderBox::absoluteOrigin()`。
     *   回调第二参是**命中的 Element**（盒每帧重建 ⇒ 现取 `effectiveBox()`，
     *   别缓存盒）。
     *
     * @api
     */
    public function pointer(\Closure $handler, bool $ownsPress = false): static
    {
        $this->mutate();
        $this->pointerHandler = $handler;
        $this->pointerOwnsPress = $ownsPress;

        return $this;
    }

    /** @internal 捕获派发读它；`null` = 本控件不接受捕获期事件 */
    public function pointerHandlerValue(): ?\Closure
    {
        return $this->pointerHandler;
    }

    /** @internal 按下路由读它；`true` = 本次按下由本控件接管（不激活） */
    public function pointerOwnsPressValue(): bool
    {
        return $this->pointerOwnsPress;
    }

    /**
     * 声明"按在本控件上就拖动窗口"（P1-Stage 1b，无边框窗的标题栏替代品）。
     *
     * ```php
     * // 组合根（持有 WindowHost，句柄在它手里）
     * AppBar::make()->title('用户管理')->windowDrag(
     *     fn () => $window->beginDrag($handle),
     * );
     * ```
     *
     * ★ **只在命中的是"本控件这一层"时生效**：从命中点沿父链往上找，途中遇到
     *   任何**自己处理按下**的控件（可聚焦 / `Activatable` / 有 `pointer()`）就
     *   立刻让位 —— 否则 AppBar 上的按钮和搜索框会连点击一起被拖走（这正是
     *   `WM_NCHITTEST` 返 `HTCAPTION` 的做法做不到精确的地方：壳看不见控件树）。
     * ★ 闭包**无参**：坐标、句柄、怎么拖都是组合根的事，框架只负责"什么时候"。
     * ★ 只在按下时调**一次**，**不捕获指针** —— 拖窗的鼠标循环归平台（Win32 的
     *   `WM_NCLBUTTONDOWN` 自己 `SetCapture`），框架再捕获一次反而会在拖动结束后
     *   把后续事件全锁在这个控件上。
     *
     * @api
     */
    public function windowDrag(\Closure $handler): static
    {
        $this->mutate();
        $this->windowDragHandler = $handler;

        return $this;
    }

    /** @internal 按下路由读它；`null` = 本控件不声明窗口拖动 */
    public function windowDragHandlerValue(): ?\Closure
    {
        return $this->windowDragHandler;
    }

    /**
     * 声明"我要处理键盘"（W8-2，Slider 立的口子）。
     *
     * ```php
     * Slider::make()->value($v)->keyboard(fn(KeyEvent $e) => $this->nudge($e));
     * ```
     *
     * ★ 回调 `\Closure(KeyEvent, Element): bool`，返回 `true` = 吃掉这个键。
     *   ★ 第二个参数是**焦点所在的 Element**（2026-10-24 起与 `pointer()` /
     *     `hover()` 同形）—— 控件要按"盒上的状态"决定行为时靠它拿 `effectiveBox()`。
     *     老的单参写法（`function (KeyEvent $e)`）在 PHP 里忽略多余参数，仍然有效。
     * ★ **沿焦点控件的父链找"最近的声明者"**（`FocusManager::dispatchKey`）——
     *   所以**包裹式控件也能挂**：`ComboBox` 包一个 `TextField` 时，焦点落在
     *   `TextField` 上，而 `ComboBox` 自己声明的 `keyboard()` 照样收得到
     *   （它要拦 Enter / 方向键 / Esc 驱动浮层）。与 `pointer()` /
     *   `contextMenu()` 同一条规则："最深的声明赢"。
     *   （2026-10-24 之前只读焦点控件自己的 widget ⇒ 包裹层的声明是死的，
     *   而本条 docblock 一直写着"任何控件都能挂"—— 文档承诺了代码没做到的事。）
     * ★ 只对**焦点控件那一支**调（Tab/Shift+Tab 之后、`TextFieldState` 之前）
     *   —— 与"键盘属于焦点"的既有分工一致。
     * ★ 为什么不复用 `activateFocusedWidget()`：那个入口收的是"激活"语义且**收不到
     *   按键**，Slider 的方向键调值、Select 的上下选、DatePicker 的翻页都做不到。
     *   ⇒ 新控件走这条缝，`activateFocusedWidget()` 的 `instanceof` 塔不再增长。
     * ★ 命名是 `keyboard()` 不是 `key()` —— 后者已被 diff 身份 `key(Key)` 占用
     *   （`Widget::$key`），两者毫无关系。`pointer()` / `hover()` / `keyboard()`
     *   是同一族的三个输入类别。
     *
     * @api
     */
    public function keyboard(\Closure $handler): static
    {
        $this->mutate();
        $this->keyHandler = $handler;

        return $this;
    }

    /** @internal 键盘派发读它；`null` = 本控件不处理按键 */
    public function keyHandlerValue(): ?\Closure
    {
        return $this->keyHandler;
    }

    /**
     * 声明"悬停在我上面时显示这段提示"（W8-2，2026-10-23）。
     *
     * ```php
     * IconButton::make('save')->tooltip('保存当前文档 (Ctrl+S)');
     * ```
     *
     * ★ **为什么是声明而不是 `hover()` 回调**：回调收的是 `\Closure(bool)`，
     *   **拿不到锚点 Element**（Tooltip 必须知道挂在哪）。加第二个参数在 PHP 侧
     *   无害，但 tpc 生成的 C++ 侧闭包 arity 行为没有验证过 —— 不值得为一个可选
     *   能力去赌编译器。声明式还有一个更硬的好处：**时序只在一处**（两个控件相邻
     *   时"先关旧的再开新的"不需要每个应用自己写对）。
     * ★ **为什么不做成包裹 Widget**：Tooltip **不产生布局、不产生绘制**，包裹式
     *   要一个透明 `RenderProxy` + `expandChildren()`（纯开销），还会插进
     *   `hoverOwner()` 的父链判定 —— 而 `semantics()` / `pointer()` / `keyboard()`
     *   这一族正是"控件属性"的正确形状。
     * ★ 浮层生命周期归 `Application`（`§5.1` 裁决 1 的原话："Application 管生命周期
     *   与落位，控件只管形体"）。本声明只是**把文字交出去**。
     *
     * @param string $text 单行提示文本（多行由 `\n` 表达，渲染侧负责换行）
     *
     * @api
     */
    public function tooltip(string $text): static
    {
        $this->mutate();
        $this->tooltipText = $text;

        return $this;
    }

    /**
     * @internal Tooltip 浮层读它；**空串 = 本控件无提示**（不用 `?string`，见字段注释）
     */
    public function tooltipTextValue(): string
    {
        return $this->tooltipText;
    }

    /**
     * 声明"激活我时，给我浮一个面板"（W8-2 Select，2026-10-23）。
     *
     * ```php
     * // 工厂：收"收掉这一层"的闭包，交回面板内容
     * Button::make($label)->overlayPanel(function (\Closure $dismiss): Widget {
     *     return PopupPanel::make(Column::make()->children([
     *         Button::make('北京')->onPressed(function () use ($dismiss): void {
     *             $this->apply('110000');
     *             $dismiss();
     *         }),
     *     ]));
     * });
     * ```
     *
     * ## 为什么是"工厂 + 注入 dismiss"而不是直接给一个 Widget
     *
     * ★★ **"点了选项要关掉自己"** —— 而面板里的按钮**拿不到 `Application`**
     *   （§4.3 禁 `Widget → App`）。所以 `dismiss` 只能由 `Application` 在**开面板
     *   的那一刻**注入进来。这与 `SnackbarPanel::make($s, $onDismiss)` 是**同一条**
     *   已被真机验证过的路（`119cfd7`）。
     *
     * ★★ **工厂只收一个参数是刻意的**：tpc 生成的 C++ 侧**闭包 arity 没有系统验证
     *   过**（`Menu::onOpen` 的 2 参形态至今没有被示例台消费过）。要往工厂里多塞
     *   "锚点宽度"这类信息，宁可给 `OverlayEntry` 加字段，也不要加形参。
     *
     * ## 为什么是声明而不是回调
     *
     * 与 `tooltip()` / `contextMenu()` 同一族：`pointer()` / `keyboard()` 这两条缝
     * 把事件交给**控件自己的闭包**，而"开浮层"要 `Application` —— 闭包够不着。
     * 声明式让框架在**激活**那一个入口统一处理（`activateFocusedWidget()` 里只有
     * **一条**通用分支，不按控件类型加 `instanceof`）。
     *
     * ★ 优先级：**声明了本字段的控件，激活时开面板，不走 `onPressed` / 命令**
     *   （"浮一个面板"比"按一下"更具体）。`Select` 因此不给内层按钮设 `onPressed`。
     * ★ 面板的落位 / 锚点 / 归属 / 关闭归 `Application`（`§5.1` 裁决 1）。
     *
     * @param \Closure(\Closure): Widget $factory 形参 = `dismiss` 闭包，返回值 = 面板内容
     *
     * @api
     */
    public function overlayPanel(\Closure $factory): static
    {
        $this->mutate();
        $this->overlayPanelFactory = $factory;

        return $this;
    }

    /** @internal 激活时浮出的面板工厂；`null` = 本控件激活时不开面板 */
    public function overlayPanelValue(): ?\Closure
    {
        return $this->overlayPanelFactory;
    }

    /**
     * 声明"我的面板**在意**什么" —— 一个字符串签名（W8-2，2026-10-24）。
     *
     * ```php
     * // Select 里（State 侧）
     * ->overlayPanel($this->panelFactory())
     * ->overlaySignature(fn(): string => $this->contentSignature())
     * ```
     *
     * ## 为什么需要这条缝
     *
     * ★★ **浮层是第二棵根渲染树**（`OverlayEntry::$panel` 的设计注释就是这条）：
     *   主树重建**不带动**面板。而 `Select` / `ComboBox` 的面板内容来自**受控属性**
     *   （`loading` / 选项表 / `emptyText`）—— 应用 `setState` 换了 Widget 之后，
     *   面板不会自己重读。
     *
     * ★★ 2026-10-24 之前这条职责落在**每个控件**身上：持有 `Subject`、`initState`
     *   里订阅、覆写 `didUpdateWidget()` 且**必须**调 `parent::`、比对签名再 publish。
     *   四步里漏任何一步都是**全静默**的（`Select` 就漏过 `parent::`，面板永不更新
     *   而 `didUpdateWidget()` 看起来被调了）。收进框架后，这个坑**结构上不存在**。
     *
     * ## 分工（这是本缝的全部理由）
     *
     * | 面板内容取决于 | 谁负责传播 |
     * |---|---|
     * | **受控属性**（住在 Widget 上） | 框架：本签名变了 ⇒ 标脏面板 |
     * | **控件私有态**（住在 State 上） | 控件自己：`Subject::notifyChanged()` |
     *
     * ★ **签名必须是"语义"而不是"身份"**：应用每次重建都造新的 `Option` 实例，
     *   用对象身份比必然"天天在变" ⇒ 面板每帧重建 ⇒ 列表滚动位置回顶部。
     *   写法参考 `Select::contentSignature()`（三态三值 + 受控值 + (value,label) 序列，
     *   用 `implode` 拼 —— 循环里 `.=` 会被 lint 的 `str-concat-loop` 判错）。
     *
     * ★ 求值时机：框架在**锚点 Widget 换了**（= 那个 `State` 重建过）之后才调一次，
     *   锚点没重建就一次都不调。所以签名函数要**便宜**，但不必极便宜。
     *
     * @param \Closure(): string $signature
     *
     * @api
     */
    public function overlaySignature(\Closure $signature): static
    {
        $this->mutate();
        $this->overlaySignature = $signature;

        return $this;
    }

    /** @internal 面板内容签名；`null` = 本控件不声明（框架不传播受控值变化） */
    public function overlaySignatureValue(): ?\Closure
    {
        return $this->overlaySignature;
    }
}
