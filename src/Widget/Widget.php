<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Input\CursorShape;
use Drift\Input\FocusNode;
use Drift\Input\SemanticsRole;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Drag\DragTarget;
use Drift\Widget\Menu\ContextMenu;

/**
 * 所有控件的根基类（§15.2）。
 *
 * fluent-first：唯一表面，全部 API 活在同一个类上 —— 不搞"构造器 vs 链式"
 * 双表面（那正是 GUI 仓"糖自造状态路径"的根源，A12）。
 *
 * 链式方法统一以 `mutate()` 开头、`return $this` 收尾；挂载时 Element 调
 * `seal()` 封存，封存后再写 ⇒ Fatal（A11 的守卫）。
 *
 * @api
 */
abstract class Widget
{
    /** @internal 0 = 可配置（构建期）；1 = 已封存（挂载后） */
    private int $sealed = 0;

    /** @internal null = 无 Key ⇒ diff 身份由位置决定（§12.3） */
    private ?Key $key = null;

    /** @internal null = 未声明焦点（不可聚焦 / 用文档序，§9.1） */
    private ?FocusNode $focus = null;

    /** @internal null = 未声明光标形状（继承命中链上更近的祖先，§9.1） */
    private ?CursorShape $cursor = null;

    /** @internal 本控件是否**天然可聚焦**（输入控件在构造器里声明，§9.1） */
    private bool $focusable = false;

    /** @internal null = 本控件不可拖动（§A.3.5） */
    private ?Draggable $draggable = null;

    /** @internal null = 本控件不接受放置（§A.3.5） */
    private ?DragTarget $dragTarget = null;

    /** @internal null = 本控件没有右键菜单（§A.3.1） */
    private ?ContextMenu $contextMenu = null;

    /** @internal null = 本控件不参与悬停派发（§9.1 语义 #5） */
    private ?\Closure $hoverHandler = null;

    /**
     * @internal null = 本控件不接受**捕获期**的指针事件（`06` §5）
     *
     * ★ 与 `hoverHandler` 的分工：hover 是"指针路过我"（每帧谁在最上面就是谁），
     *   捕获是"指针被钉在我身上"（按下后一直到抬起，**不再命中测试**）。
     *   两者都是 `\Closure(PointerEvent): ?CursorShape`，但语义互不覆盖。
     */
    private ?\Closure $pointerHandler = null;

    /** @internal null = 本控件不在语义树里（无角色 = 不建节点，§9 / M5 无障碍） */
    private ?SemanticsRole $semRole = null;

    /** @internal 人读的名字（按钮 label / 对话框标题）；无角色时无意义 */
    private string $semName = '';

    /** @internal 扁平的取值 / 状态（如文本框当前文本）；无角色时无意义 */
    private string $semValue = '';

    /** @internal 可用性声明（§9）；默认 true。只是**数据**，没有消费者时不起任何作用 */
    private bool $semEnabled = true;

    /**
     * ★ 子类必须调 `parent::__construct()`。
     *
     * @api
     */
    public function __construct()
    {
    }

    /**
     * 给本控件一个跨位置稳定的身份（§12.3）。
     *
     * 不给 ⇒ 身份由在 `children([...])` 里的位置决定；给了 ⇒ 重排也能保住 `State`。
     * `ForEach` 的 key 是**必填**的（§16.6.1 R4），因为列表重排最需要它。
     *
     * @api
     */
    public function key(string|int $value): static
    {
        $this->mutate();
        $this->key = new Key($value);

        return $this;
    }

    /**
     * 子层拿到的渲染环境（§2446：主题 scope 链的扩展点）。
     *
     * 默认原样递下去。**只有"面覆盖"型节点才覆写**（目前 `Drift\Theme\ThemeScope`
     * 换主题；将来方向 / 本地化同形）。返回类型是 `BuildContext` 而不是更具体的
     * 类型 —— 所以 `Drift\Widget` 不需要认识 `Drift\Theme`（§4.3 的协议例外
     * 仍然只有 `BuildContext.php` 一个文件）。
     *
     * @internal Element 挂载 / 更新时调它，用户不需要。
     */
    public function childContext(BuildContext $context): BuildContext
    {
        return $context;
    }

    /**
     * 造 Element —— **唯一入口**（W1 同源：单一造盒路径）。
     *
     * ★ 收口成一处 `instanceof`，而不是让每个控件子类各写一遍 `return new
     *   XxxElement($this)`：45 个控件抄同一句话，漏抄的不会报错，只会静默走错
     *   元素类型。要特殊元素的（容器）可以覆写本方法。
     *
     * @internal 用户不该自己 new Element。
     */
    public function createElement(): Element
    {
        return $this instanceof StatefulWidget
            ? new StatefulElement($this)
            : new StatelessElement($this);
    }

    /**
     * diff 用的身份（§12.3）：`null` = 由位置决定。
     *
     * @internal Element 调和时读它，用户不需要（给了 Key 就已经生效）。
     */
    public function identity(): ?Key
    {
        return $this->key;
    }

    /**
     * 挂载时由 Element 调用：把描述封存为不可变。
     *
     * 幂等 —— 同一实例被复用到多处是**合法**的（Widget 是纯描述，各处各有
     * 自己的 Element），所以这里不做任何 owner 校验。`Column::make()->children([$t, $t])`
     * 常见且合法，做 owner 校验是误杀。
     *
     * @internal 不进公共面：用户不需要它，"挂载时自动封存"已覆盖全部合法场景（§15.6.1）。
     */
    final public function seal(): void
    {
        $this->sealed = 1;
    }

    /** @internal */
    final public function isSealed(): bool
    {
        return $this->sealed === 1;
    }

    /**
     * 链式修饰的统一入口 —— 一个口径一个落点，不是每方法各写一遍守卫。
     *
     * 封存后写入 ⇒ 响亮失败（Fatal 级，release 也中止，§1.2）。
     *
     * @internal
     */
    final protected function mutate(): void
    {
        if ($this->sealed === 1) {
            Errors::report('Widget 已封存，禁止再修改：' . static::class);
        }
    }

    // ── 焦点声明（§9.1 / §A.3.4）──────────────────────────────────────
    // ★ 这三个是**声明**，不是状态："现在谁聚焦"住在 Drift\App\FocusManager。
    //   Widget 挂载后不可变（§10.1 seal），把可变焦点塞进来会立刻撞纪律。

    /**
     * 显式指定本控件在**同一父容器内**的 Tab 序（§A.3.4）。
     *
     * 不声明 ⇒ 用文档序（深度优先）。★ 同一容器内**混用有/无** `focusOrder`
     * ⇒ 隐式相对顺序有歧义（§9.1 裁决为 G2 级错误）。
     * **诚实边界**：该 lint 守卫**尚未落地**（`php tools/lint.php --list` 里没有它）
     * —— 今天 `FocusManager` 的行为是**有 `focusOrder` 的排在无的前面**、同侧按文档序。
     *
     * @api
     */
    public function focusOrder(int $order): static
    {
        $this->mutate();
        $this->focus = ($this->focus ?? FocusNode::make())->withOrder($order);

        return $this;
    }

    /**
     * 整棵子树**不进** Tab 序（§A.3.4）。
     *
     * 典型：`AppBar` 的工具按钮组。**必须显式** —— 不做"智能跳过"。
     *
     * @api
     */
    public function skipFocus(): static
    {
        $this->mutate();
        $this->focus = ($this->focus ?? FocusNode::make())->skipped();

        return $this;
    }

    /**
     * 声明本控件**独占**的键集：命中时不让加速键抢（§9.1 让位规则 ②）。
     *
     * `TextField` 的编辑命令（`Ctrl+A/C/V/X/Z`）就是这么声明的。
     *
     * @param list<KeyChord> $chords
     *
     * @api
     */
    public function claimKeys(array $chords): static
    {
        $this->mutate();
        $this->focus = ($this->focus ?? FocusNode::make())->claiming($chords);

        return $this;
    }

    /** @internal 焦点链读它（`null` = 本控件没声明任何焦点属性） */
    public function focusNode(): ?FocusNode
    {
        return $this->focus;
    }

    /**
     * 声明本控件（及其**未覆写**的后代）的鼠标指针形状（§9.1 / §A.3.2）。
     *
     * ```php
     * Row::make()->cursor(CursorShape::PointingHand)->children([...])   // 可点
     * Text::make('可选中文本')->cursor(CursorShape::IBeam)              // 文本
     * Column::make()->cursor(CursorShape::SizeAll)                     // 拖动把手
     * ```
     *
     * ★ 这是**声明**，不是状态：谁声明、命中链取**最深**者 —— 解析住在
     *   `FocusManager`（与焦点链同源，§9.1「一次命中，三方消费」）。
     * ★ **不给控件开自绘光标的口子**（§9.1 裁决）：自绘光标要自己画、自己跟帧，
     *   为一个装饰把渲染路径拉进输入态不划算。
     *
     * @api
     */
    public function cursor(CursorShape $shape): static
    {
        $this->mutate();
        $this->cursor = $shape;

        return $this;
    }

    /** @internal 造盒时读它（`null` = 未声明 ⇒ 交给命中链上更近的祖先） */
    public function cursorShape(): ?CursorShape
    {
        return $this->cursor;
    }

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
     * 声明"按下后我要一直收到指针事件"（指针捕获，`06` §5）。
     *
     * ```php
     * Slider::make()
     *     ->pointer(function (PointerEvent $e, Element $self): ?CursorShape {
     *         // e 的坐标仍是**客户区坐标**（不换算），需要局部坐标就减
     *         // `RenderBox::absoluteOrigin($self->effectiveBox())`
     *         return null;
     *     });
     * ```
     *
     * ★ **捕获不是"能收到更多事件"**：捕获期指针事件**不再命中测试**（这是捕获的
     *   全部意义 —— 拖到控件外面仍然归我）。捕获由控件在处理 `down` 时主动申请：
     *   `$app->capturePointer($element)`，抬起 / 捕获者卸载 / 窗口失焦时自动释放。
     * ★ 只在捕获期被调 —— 没捕获过的控件这个回调**一次都不会跑**（不是"指针经过
     *   就调"，那是 `hover()`）。
     *
     * @api
     */
    public function pointer(\Closure $handler): static
    {
        $this->mutate();
        $this->pointerHandler = $handler;

        return $this;
    }

    /** @internal 捕获派发读它；`null` = 本控件不接受捕获期事件 */
    public function pointerHandlerValue(): ?\Closure
    {
        return $this->pointerHandler;
    }

    /**
     * 声明"这个控件是什么"（§9 无障碍 / M5）—— 角色 + 人读信息，供
     * `SemanticsBuilder` 建语义树（检查器 / 自动化 / 将来的朗读消费）。
     *
     * ```php
     * Button::make('保存', 'doc.save')          // 内部即 ->semantics(Button, '保存')
     * TextField::make()->label('邮箱')           // label 同时是可访问名
     * Modal::make()->title('确认删除')           // 标题即对话框名
     * ```
     *
     * ★★ **这是字段赋值，不是虚方法覆写**（§9 P3 陷阱）：tpc 有全程序去虚化缺陷，
     *   "基类给默认实现 + 子类覆写 `semantics()`" 会被**静默忽略** ⇒ 症状是"所有
     *   节点角色为空"且编译期不报错。控件要么在自己的构造/设值处调本方法
     *   （`Button` / `TextField::label()` / `Modal::title()` 即如此），要么由应用显式调。
     * ★ **不声明 = 不进语义树**（加性）：`Text` / `Row` / `Card` 这类结构与叶子
     *   一律不调它（判据见 `SemanticsRole` 的类注释）。
     * ★ `null` 参数 = "不动这一项"（便于分次补设，如先角色后取值）。
     *
     * @api
     */
    public function semantics(
        SemanticsRole $role,
        ?string $name = null,
        ?string $value = null,
        ?bool $enabled = null
    ): static {
        $this->mutate();
        $this->semRole = $role;
        if ($name !== null) {
            $this->semName = $name;
        }
        if ($value !== null) {
            $this->semValue = $value;
        }
        if ($enabled !== null) {
            $this->semEnabled = $enabled;
        }

        return $this;
    }

    /** @internal `SemanticsBuilder` 读它；`null` = 不在语义树里 */
    public function semanticsRole(): ?SemanticsRole
    {
        return $this->semRole;
    }

    /** @internal `SemanticsBuilder` 读它 */
    public function semanticsName(): string
    {
        return $this->semName;
    }

    /** @internal `SemanticsBuilder` 读它 */
    public function semanticsValue(): string
    {
        return $this->semValue;
    }

    /** @internal `SemanticsBuilder` 读它 */
    public function semanticsEnabled(): bool
    {
        return $this->semEnabled;
    }

    /**
     * 输入控件在**构造器里**声明"我能收焦点"（§9.1）。
     *
     * 默认 false：`Text` / `Column` 这类不该进 Tab 序。声明过的控件才可能被
     * `FocusManager` 收进焦点链。
     *
     * @internal
     */
    final protected function markFocusable(): void
    {
        $this->focusable = true;
    }

    /** @internal */
    final public function isFocusable(): bool
    {
        return $this->focusable;
    }
}
