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
use Drift\Widget\Traits\WidgetFocus;
use Drift\Widget\Traits\WidgetInteraction;
use Drift\Widget\Traits\WidgetModifiers;
use Drift\Widget\Traits\WidgetSemantics;

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
    // ── 四个能力面（2026-10-28，S2）────────────────────────────────────
    //
    // 本类曾经 1265 行 / 66 个 public 方法，其中 **58 个是样板**
    // （实测：21 个写 `$mods`、15 个写字段、22 个纯 getter，真逻辑只有 10 个）。
    // "身份 + 生命周期 + 修饰 + 焦点 + 交互槽 + 语义"六件事挤在一起 ⇒
    // 加控件的人不知道哪些该覆写、哪些只是转发（`REVIEW-2026-10-28` §1.2 L1/L2）。
    //
    // ★★ **"唯一表面"没有被破坏**：那句说的是"调用方看到的 API 只有一个类"
    //   （不搞"构造器 vs 链式"两套表面），不是"所有方法必须写在同一段文字里"。
    //   trait 合入后这些方法仍是 `Widget` 的实例方法，`$w->paddingAll(8)` 一个字母都不变
    //   —— 拆分前后用反射快照逐签名比对过。
    // ★ 按**能力面**拆而不是按机制拆（"写 mods 的一堆 / 读字段的一堆"）：
    //   后者会把 `semanticsName()` 与 `semantics()` 分到两个文件里，更难读。
    // ★ tpc（AOT）约束：traits must be visible at compile time（官方
    //   `CLASS_INHERITANCE.md`）。本仓是自建 PSR-4 装载器 ⇒ 落地时用
    //   `tools/drift-build.php showcase` 实测过真机产物（trait + `static` 返回 +
    //   私有成员访问三者一起过编译）。
    use WidgetModifiers;

    use WidgetFocus;

    use WidgetInteraction;

    use WidgetSemantics;

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

    /**
     * @internal 按下是否**归本控件**（W8-2，Slider 立的口子）。
     *
     * `true` ⇒ 命中链上最近的 `pointer()` 声明者接管本次按下：框架捕获它、先把
     * `down` 相位交过去，**跳过** `activateFocusedWidget()`。焦点仍然落上去
     * （键盘可达），只是"激活"的含义由控件自己定义（Slider：按下的 x 换算成值）。
     *
     * ★ 为什么是开关而不是默认行为：`pointer()` 今天还有一个消费者
     *   （`OverlayLab` 的锚点按钮）只想要"捕获期收事件"、按下仍该走命令派发
     *   ⇒ 默认 `false` 保持逐字节不变，需要接管的控件显式声明。
     */
    private bool $pointerOwnsPress = false;

    /**
     * @internal null = 本控件不声明"窗口拖动"（P1-Stage 1b 无边框窗）
     *
     * `\Closure(): void` —— 按在本控件**自身**（不是它的子控件）上时调一次。
     * ★ 为什么是闭包而不是让框架直接调 `WindowHost`：拖窗是**平台动作**，而
     *   `Drift\App` 的输入路由不该认识 `WindowHost`（§5.1 纪律 1：句柄不跨层）。
     *   组合根（持有 `WindowHost`）递一个闭包进来 —— 与 `semanticsSink` 同一条
     *   "组合根接线"的路，框架核心零平台认知。
     */
    private ?\Closure $windowDragHandler = null;

    /**
     * @internal null = 本控件不处理键盘（按键落给焦点链的默认路径）
     *
     * `\Closure(KeyEvent): bool`，返回 `true` = 吃掉这个键。
     * ★ 与 `pointer()` 同族：都是"控件自己处理某类输入"的声明，框架只做路由。
     */
    private ?\Closure $keyHandler = null;

    /**
     * @internal 空串 = 本控件无悬停提示（W8-2 Tooltip）
     *
     * ★ 存**文本**不存 Widget：Tooltip 的形体归框架（`TooltipPanel` 工厂），
     *   应用只声明"说什么"。要富内容提示时再开 `tooltipWidget()` 另一个声明。
     * ★★ **用空串当哨兵，不用 `?string`**：可选标量在 AOT 侧退化 `php::Var`
     *   （与 `?float` 用 `NAN` 是同一条纪律）。2026-10-23 真机就是这么坏的：
     *   smoke（解释器）22 条全绿，`showcase.exe` 上提示**永远不出现** ——
     *   悬停高亮正常（那条链只碰 `?Element`），唯独 `tooltipTextValue()` 恒 null。
     */
    private string $tooltipText = '';

    /**
     * @internal 激活时浮出的面板工厂（W8-2 Select）；`null` = 激活时不浮面板
     *
     * ★ 存**工厂**不存 Widget：面板里的"点了选项就收掉这层"要靠 `Application`
     *   注入的 dismiss 闭包，而面板**必须在开的那一刻**才建得出来。
     * ★ `?\Closure` 是本仓已跑通的形态（`hoverHandler` / `pointerHandler` /
     *   `keyHandler` / `draggable` 都是），与 `?string` 退化 `php::Var` 那条不同。
     */
    private ?\Closure $overlayPanelFactory = null;

    /** 面板宽上限（`null` = 框架默认，见 `overlayPanel()` 的 `$maxWidth`） */
    private ?float $overlayPanelMaxWidth = null;

    /**
     * 面板内容的**签名**（`\Closure(): string`；`null` = 不声明）。
     *
     * ★ 与 `overlayPanelFactory` 一样是"声明式字段、不是虚方法"（见 `semantics()`
     *   那条 §9 P3 的说明）。
     *
     * @internal 只有 `Application` 读它（`overlaySignatureValue()`）
     */
    private ?\Closure $overlaySignature = null;

    /** @internal null = 本控件不在语义树里（无角色 = 不建节点，§9 / M5 无障碍） */
    private ?SemanticsRole $semRole = null;

    /** @internal 人读的名字（按钮 label / 对话框标题）；无角色时无意义 */
    private string $semName = '';

    /** @internal 扁平的取值 / 状态（如文本框当前文本）；无角色时无意义 */
    private string $semValue = '';

    /** @internal 可用性声明（§9）；默认 true。只是**数据**，没有消费者时不起任何作用 */
    private bool $semEnabled = true;

    /**
     * @internal 取值区间的下界/上界（只对 `range` 族角色有意义：`Slider` / `Progress`）。
     *
     * ★ 与 `semValue` **正交**：`semValue` 是"人读的当前值"，这里是"这个值能在哪个
     *   区间里"。分成两个字段而不是塞进 `semantics()` 的签名，是因为只有少数控件
     *   需要区间，且 `semantics()` 的 4 参签名已被多处调用（不膨胀它）。
     * ★ `UIA IRangeValueProvider` 必须有 `Minimum`/`Maximum` ⇒ 没有这两个值，
     *   滑块的 `RangeValuePattern` 给不出合法应答。
     */
    private float $semRangeMin = 0.0;

    private float $semRangeMax = 0.0;

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
     * 本控件展开出的子控件（`ProxyElement` 的唯一输入）。
     *
     * ★ 从 `ProxyElement::expand()` 的 7 个 `instanceof` 分支搬过来：那种写法要求
     *   **每新增一个单子控件都回来改 `ProxyElement`**（漏了就是"子不挂载、静默空白"，
     *   §24.2 #25）。搬到 `Widget` 上之后是开放-封闭：新控件覆写自己的方法即可。
     * ★ 默认 `[]`：容器控件（`Column` / `Row` / `Dock`）走各自的 Element，不经过这里。
     *
     * @internal `ProxyElement` 调；应用侧不该用
     * @return list<Widget>
     */
    public function expandChildren(): array
    {
        return [];
    }



















































}
