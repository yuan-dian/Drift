<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\EdgeInsets;
use Drift\Input\CursorShape;
use Drift\Input\FocusNode;
use Drift\Input\SemanticsRole;
use Drift\Rendering\Color;
use Drift\Rendering\Modifier;
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

    /**
     * 修饰字段的集合（padding / 尺寸 / 背景 / 边框 / 圆角 / 阴影 / 合成 / 弹性意图）。
     *
     * ★ **永远非空**（构造器里 `new Modifier()`）：`Element::syncBox()` 无条件把
     *   它递给盒，不要"有没有修饰"这条分支。代价是每个 Widget 多一次对象分配，
     *   换来 `->padding(8)` 之类完全不必判空。
     * ★ 修饰是**声明**，与 `cursor()` / `focusOrder()` 同一层；挂载后照样封存。
     */
    private Modifier $mods;

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
        $this->mods = new Modifier();
    }

    /**
     * ★ 克隆 = 复制一份**可再配置的描述** ⇒ 封存位必须归零。
     *
     * `$sealed` 是 `private int`，`clone` 会连它一起复制 ⇒ 克隆一个已挂载（已封存）
     * 的 Widget，得到的也是封存的，紧接着 `->padding(...)` 里的 `mutate()` 直接 Fatal。
     *
     * 需要的场景：`Card` 的语义是"**包一层**"而不是"给这个控件加样式"，所以它的
     * `build()` 必须 `clone $this->kid` 再贴样式 —— 否则样式会漏给另一处共享引用
     * （`children([$a, $a])` 合法）。
     *
     * @internal
     */
    public function __clone()
    {
        $this->sealed = 0;
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
     * ★ **`@api` 而不是 `@internal`**（2026-10-22，W7 第 40 步）：它是 `final
     *   protected` 的**封存守卫**，应用自定义 Widget 写自己的链式方法时**必须**
     *   调它 —— 否则那些方法能绕过封存、在挂载后改字段（`§10.1` 的不可变纪律
     *   从这一处漏光）。原来的 `@internal` 把"唯一的正确做法"标成了内部面，
     *   于是 `apps/users` 3 处正确用法被方法级 `internal-leak` 报成泄漏。
     *   这正是 `07` §2.2 说的"标记与用法打架"：改标记，不改用法。
     *
     * @api
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

    // ── 修饰：字段，不是节点（arch-v2 `02` §11）────────────────────────
    //
    // ★ 全部走同一个 `$this->mods`（值对象，**换新不就地改**）⇒ `Element::syncBox()`
    //   只需把**一个**引用递给盒，不必为每个修饰加一条传递路径 —— 十二处抄同一句、
    //   漏抄不报错，那是 `syncBox()` 注释里已经写明的问题。
    //
    // ★ 名字上有**三处刻意避让**（实测撞名，撞了是类声明期 Fatal，不是运行时）：
    //   · `size()` 被 `Text::size(float $pt)`（字号）与 `SizedBox::size(w,h)` 占了
    //     ⇒ 尺寸修饰叫 `boxSize()`；
    //   · `center()` 被 `Dock::center(Widget $kid)`（五区槽位）占了
    //     ⇒ 居中修饰叫 `centerContent()`。
    //
    // ★ `padding` / `paddingAll` / `background` / `border` / `radius` / `shadow` /
    //   `width` / `height` 在 `BoxWidget` / `Card` / `Flex` / `PopupPanel` /
    //   `LazyColumn` / `Divider` / `Progress` 上**已有同名同签名实现** ⇒ 子类覆写
    //   基类，写它们自己的存储（`BoxStyle` / 自有字段），**行为与今天完全一致**。
    //   只有裸 `Widget` 的调用才落到 `mods`。这是有意的边界：控件自带的整套样式
    //   （含主题派生默认值）继续住 `BoxStyle`，`mods` 只管"给任意控件贴一层"。

    /** @internal `Element::syncBox()` 递它；永远非空 */
    public function mods(): Modifier
    {
        return $this->mods;
    }

    /**
     * 四边等距内边距。
     *
     * ```php
     * Text::make('hi')->paddingAll(8.0)          // 简写
     * $x->padding(EdgeInsets::symmetric(12.0, 8.0));
     * ```
     *
     * ★ 收 `EdgeInsets` 而不是 `float`：`padding(EdgeInsets)` 在 `Flex` /
     *   `LazyColumn` / `PopupPanel` / `Card` / `Padding` 上**已经存在**且被
     *   `apps/` 里十几处调用。基类收 `float` 会让那些子类变成"不兼容覆写"
     *   ⇒ 整个 `src/` 装载不起来。等距用法由 `paddingAll()` 覆盖。
     *
     * @api
     */
    public function padding(EdgeInsets $insets): static
    {
        $this->mutate();
        $this->mods = $this->mods->withPadding(
            $insets->left,
            $insets->top,
            $insets->right,
            $insets->bottom,
        );

        return $this;
    }

    /** 四边等距（`padding()` 的简写） */
    public function paddingAll(float $all): static
    {
        $this->mutate();
        $this->mods = $this->mods->withPadding($all, $all, $all, $all);

        return $this;
    }

    /** 逐边设置；`null` = 不动这一边（便于分次补设） */
    public function paddingOnly(
        ?float $left = null,
        ?float $top = null,
        ?float $right = null,
        ?float $bottom = null
    ): static {
        $this->mutate();

        $l = $this->mods->padLeft();
        $t = $this->mods->padTop();
        $r = $this->mods->padRight();
        $b = $this->mods->padBottom();

        if ($left !== null) {
            $l = $left;
        }
        if ($top !== null) {
            $t = $top;
        }
        if ($right !== null) {
            $r = $right;
        }
        if ($bottom !== null) {
            $b = $bottom;
        }

        $this->mods = $this->mods->withPadding($l, $t, $r, $b);

        return $this;
    }

    /**
     * 定尺寸（`SizedBox` 的字段形态）：给了的那一轴**钉死**，`null` = 该轴透传。
     *
     * ★ 叫 `boxSize` 而不是 `size`：`Text::size()` 是字号、`SizedBox::size()` 是
     *   两个必选参数 —— 两个都不兼容，基类叫 `size()` 会让它们变成非法覆写。
     *
     * @api
     */
    public function boxSize(?float $width = null, ?float $height = null): static
    {
        $this->mutate();

        $w = \NAN;
        if ($width !== null) {
            $w = $width;
        }
        $h = \NAN;
        if ($height !== null) {
            $h = $height;
        }

        $this->mods = $this->mods->withSize($w, $h);

        return $this;
    }

    /** 只定宽（高随内容） */
    public function width(float $width): static
    {
        $this->mutate();
        $this->mods = $this->mods->withWidth($width);

        return $this;
    }

    /** 只定高（宽随内容） */
    public function height(float $height): static
    {
        $this->mutate();
        $this->mods = $this->mods->withHeight($height);

        return $this;
    }

    /**
     * 底色。
     *
     * ★ `BoxWidget` 的子类（`Button` / `PopupPanel` / …）覆写了本方法、写进
     *   `BoxStyle` —— 那是控件自带的整套样式，与 `mods` 的"贴一层装饰"是两件事，
     *   刻意不合并。
     *
     * @api
     */
    public function background(Color $color): static
    {
        $this->mutate();
        $this->mods = $this->mods->withFill($color);

        return $this;
    }

    public function border(Color $color, float $width = 1.0): static
    {
        $this->mutate();
        $this->mods = $this->mods->withStroke($color, $width);

        return $this;
    }

    public function radius(float $radius): static
    {
        $this->mutate();
        $this->mods = $this->mods->withRadius($radius);

        return $this;
    }

    /**
     * 柔和投影（值域 = 黑色透明度；`0.0` = 不画）。
     *
     * ★ 阴影画在**盒外** ⇒ 父布局不会为它留白，块之间要留 ≥ 4px 间距，
     *   否则邻块底色会盖掉它。
     *
     * @api
     */
    public function shadow(float $elevation): static
    {
        $this->mutate();
        $this->mods = $this->mods->withShadow($elevation);

        return $this;
    }

    /**
     * 不透明度（`1.0` = 不透明）。
     *
     * ★ 这是**声明值**：`RenderBox::setMods()` 读一次去初始化盒上的合成参数，
     *   此后动画走 `RenderBox::setCompose()` 直接写盒、不重建不重排。
     *   rebuild 之后回到这里声明的值（结构变了，动画中间态就不该继续顶着）。
     *
     * @api
     */
    public function opacity(float $value): static
    {
        $this->mutate();

        if ($value < 0.0 || $value > 1.0) {
            Errors::report(\sprintf('opacity 应在 0.0..1.0，收到 %.3f', $value));
        }

        $this->mods = $this->mods->withOpacity($value);

        return $this;
    }

    /**
     * 绘制期平移（不开层、不重排）—— 合成维，与 `opacity()` 同一组。
     *
     * @api
     */
    public function translate(float $dx, float $dy): static
    {
        $this->mutate();
        $this->mods = $this->mods->withTranslate($dx, $dy);

        return $this;
    }

    /**
     * 把**内容**裁到盒矩形（含内边距）。`clip(false)` 可显式关掉。
     *
     * ★ 与容器声明 `->overflow(Overflow::Clip)` 的分工：`Overflow` 管**布局期**
     *   "放不下时报错还是夹住"，`clip()` 管**绘制期**"画的时候裁掉"。会超的容器
     *   两者都要 —— 只声明 `Overflow::Clip` 只是"不报错"，内容仍然画到盒外。
     * ★ 装饰（背景 / 边框 / 圆角 / 阴影）**不**被裁：阴影本来就在盒外。
     *
     * @api
     */
    public function clip(bool $on = true): static
    {
        $this->mutate();
        $this->mods = $this->mods->withClip($on);

        return $this;
    }

    /**
     * 把**内容**在本盒内居中（`Center` 的字段形态）。
     *
     * ★ 与 `alignSelf()` 不是一件事：`alignSelf` 只在 `RenderFlex` 里被消费
     *   （没有 Flex 父就是死字段），而 `Center` 常常是页面根。
     * ★ 叫 `centerContent` 而不是 `center`：`Dock::center(Widget)` 是五区槽位。
     *
     * @api
     */
    public function centerContent(bool $on = true): static
    {
        $this->mutate();
        $this->mods = $this->mods->withCenter($on);

        return $this;
    }

    /**
     * 主轴放大份额（`Expanded` 的字段形态）。
     *
     * ★ `RenderFlex` 读的是盒上的 `grow()`，`Element::syncBox()` 把这里声明的值
     *   递过去 —— 所以 `Expanded::make($x)->child()` 只是 `$x->grow(1)`。
     *
     * @api
     */
    public function grow(int $weight): static
    {
        $this->mutate();

        if ($weight < 0) {
            Errors::report(\sprintf('grow 份额不能为负，收到 %d', $weight));
        }

        $this->mods = $this->mods->withGrow($weight);

        return $this;
    }

    /**
     * 主轴压缩份额（`Flexible` 的字段形态）。
     *
     * 空间不够时按 `shrink × 起始尺寸` 加权压缩（`04` §3.2，≤3 轮冻结迭代）。
     *
     * ★ **下限是 0，没有保底**（`04` §3.4）：被压到 0 的控件内容不可见，但
     *   **不报错**。今天也没有"设 `width` 就压不动"这回事 —— `Modifier::tighten()`
     *   是把 `width` 夹进父给的区间，紧约束 `(0, 0)` 会把 `width` 一起夹成 0。
     *   要真正的下限得等 `min-content`，而它需要文本的最小宽度（今天没有）。
     */
    public function shrink(int $weight): static
    {
        $this->mutate();

        if ($weight < 0) {
            Errors::report(\sprintf('shrink 份额不能为负，收到 %d', $weight));
        }

        $this->mods = $this->mods->withShrink($weight);

        return $this;
    }

    /** 分配前的起始主轴尺寸（不设 = 用自然尺寸） */
    public function basis(float $basis): static
    {
        $this->mutate();
        $this->mods = $this->mods->withBasis($basis);

        return $this;
    }

    /**
     * 交叉轴自对齐（覆盖父容器的 `crossAlign`）。
     *
     * @param int $align `RenderBox::ALIGN_INHERIT` / `ALIGN_START` / `ALIGN_CENTER`
     *                   / `ALIGN_END` / `ALIGN_STRETCH`
     */
    public function alignSelf(int $align): static
    {
        $this->mutate();
        $this->mods = $this->mods->withAlign($align);

        return $this;
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
