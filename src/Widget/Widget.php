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
 * 所有控件的根基类。
 * fluent-first：全部 API 活在同一个类上，链式方法以 `mutate()` 开头、`return $this` 收尾。
 * 挂载时 Element 调 `seal()` 封存，封存后再写 ⇒ Fatal。
 * @api
 */
abstract class Widget
{
    // ── 四个能力面（修饰 / 焦点 / 交互 / 语义，由 trait 合入，仍是 `Widget` 的实例方法）──

    use WidgetModifiers;

    use WidgetFocus;

    use WidgetInteraction;

    use WidgetSemantics;

    /** @internal 0 = 可配置（构建期）；1 = 已封存（挂载后） */
    private int $sealed = 0;

    /**
     * 修饰字段的集合（padding / 尺寸 / 背景 / 边框 / 圆角 / 阴影 / 合成 / 弹性意图）。
     * **永远非空**（构造器里 `new Modifier()`）：`Element::syncBox()` 无条件把它递给盒，
     *   调用方不必判空。修饰是**声明**，与 `cursor()` / `focusOrder()` 同一层。
     */
    private Modifier $mods;

    /** @internal null = 无 Key ⇒ diff 身份由位置决定 */
    private ?Key $key = null;

    /** @internal null = 未声明焦点（不可聚焦 / 用文档序） */
    private ?FocusNode $focus = null;

    /** @internal null = 未声明光标形状（继承命中链上更近的祖先） */
    private ?CursorShape $cursor = null;

    /** @internal 本控件是否**天然可聚焦**（输入控件在构造器里声明） */
    private bool $focusable = false;

    /** @internal null = 本控件不可拖动 */
    private ?Draggable $draggable = null;

    /** @internal null = 本控件不接受放置 */
    private ?DragTarget $dragTarget = null;

    /** @internal null = 本控件没有右键菜单 */
    private ?ContextMenu $contextMenu = null;

    /** @internal null = 本控件不参与悬停派发 */
    private ?\Closure $hoverHandler = null;

    /**
     * @internal null = 本控件不接受**捕获期**的指针事件
     * 与 `hoverHandler` 的分工：hover 是"指针路过我"（每帧谁最上面就是谁），
     *   捕获是"指针被钉在我身上"（按下后到抬起不再命中测试），语义互不覆盖。
     */
    private ?\Closure $pointerHandler = null;

    /**
     * @internal 按下是否**归本控件**（Slider 立的口子）。
     * `down` 相位交过去，**跳过** `activateFocusedWidget()`；焦点仍落上去
     *   （键盘可达），只是"激活"的含义由控件自己定义（Slider：按下的 x 换算成值）。
     * 默认 `false`；需要接管的控件显式声明。
     */
    private bool $pointerOwnsPress = false;

    /**
     * @internal null = 本控件不声明"窗口拖动"
     * `\Closure(): void` —— 按在本控件**自身**（不是它的子控件）上时调一次。
     * 拖窗是平台动作：框架核心不认识 `WindowHost`，由组合根递闭包进来。
     */
    private ?\Closure $windowDragHandler = null;

    /**
     * @internal null = 本控件不接收系统拖放进来的文件
     * `\Closure(FilesDroppedEvent): void` —— 命中点沿父链冒泡找到本控件时调一次。
     * 与 `windowDragHandler` 同族：控件自己处理某类输入的声明，框架只做路由；
     *   闭包由页面 State 在构建期递入，异常由路由层兜住。
     */
    private ?\Closure $filesDropHandler = null;

    /**
     * @internal null = 本控件不处理键盘（按键落给焦点链的默认路径）
     * `\Closure(KeyEvent): bool`，返回 `true` = 吃掉这个键。
     * 与 `pointer()` 同族：控件自己处理某类输入的声明，框架只做路由。
     */
    private ?\Closure $keyHandler = null;

    /**
     * @internal 空串 = 本控件无悬停提示
     * 存**文本**不存 Widget：Tooltip 形体归框架（`TooltipPanel` 工厂），应用只声明"说什么"。
     * ★ **用空串当哨兵，不用 `?string`**：可选标量在 AOT 侧退化 `php::Var`
     *   （与 `?float` 用 `NAN` 是同一条纪律）。
     */
    private string $tooltipText = '';

    /**
     * @internal 激活时浮出的面板工厂；`null` = 激活时不浮面板
     * 存**工厂**不存 Widget：面板"点了选项就收掉这层"靠 `Application` 注入的
     *   dismiss 闭包，而面板**必须在开的那一刻**才建得出来。
     */
    private ?\Closure $overlayPanelFactory = null;

    /** 面板宽上限（`null` = 框架默认，见 `overlayPanel()` 的 `$maxWidth`） */
    private ?float $overlayPanelMaxWidth = null;

    /**
     * 面板内容的**签名**（`\Closure(): string`；`null` = 不声明）。
     * 与 `overlayPanelFactory` 一样是"声明式字段、不是虚方法"。
     * @internal 只有 `Application` 读它（`overlaySignatureValue()`）
     */
    private ?\Closure $overlaySignature = null;

    /** @internal null = 本控件不在语义树里（无角色 = 不建节点） */
    private ?SemanticsRole $semRole = null;

    /** @internal 人读的名字（按钮 label / 对话框标题）；无角色时无意义 */
    private string $semName = '';

    /** @internal 扁平的取值 / 状态（如文本框当前文本）；无角色时无意义 */
    private string $semValue = '';

    /** @internal 可用性声明；默认 true。只是**数据**，没有消费者时不起任何作用 */
    private bool $semEnabled = true;

    /**
     * @internal 取值区间的下界/上界（只对 `range` 族角色有意义：`Slider` / `Progress`）。
     * 与 `semValue` **正交**：前者是"这个值能在哪个区间里"，后者是"人读的当前值"。
     * `UIA IRangeValueProvider` 必须有 `Minimum`/`Maximum` ⇒ 没有这两个值，
     *   滑块的 `RangeValuePattern` 给不出合法应答。
     */
    private float $semRangeMin = 0.0;

    private float $semRangeMax = 0.0;

    /**
     * @internal 选中态三态（0 无此概念 / 1 未选 / 2 已选）。
     * ★ 用 int 而不是 `?bool`：这条值要跨"数组 → C++ Variant"边界，`null` 的
     *   处置归 AOT 编译器管、仓内没法取证；`int` 与 `parent`/`depth` 同一条路。
     */
    private int $semSelect = 0;

    /** @internal 展开态三态（0 无此概念 / 1 折叠 / 2 展开） */
    private int $semExpand = 0;

    /** @internal 层级（1-based）；0 = 未声明 */
    private int $semLevel = 0;

    /**
     * ★ 子类必须调 `parent::__construct()`。
     * @api
     */
    public function __construct()
    {
        $this->mods = new Modifier();
    }

    /**
     * ★ 克隆 = 复制一份**可再配置的描述** ⇒ 封存位必须归零。
     * `$sealed` 是 `private int`，`clone` 会连它一起复制 ⇒ 克隆已挂载（已封存）的
     *   Widget，`->padding(...)` 里的 `mutate()` 会直接 Fatal。任何"**包一层**"的
     *   包装器都要 `clone $kid` 再贴自己的东西，否则共享引用漏给另一处。
     * @internal
     */
    public function __clone()
    {
        $this->sealed = 0;
    }

    /**
     * 给本控件一个跨位置稳定的身份。
     * 不给 ⇒ 身份由在 `children([...])` 里的位置决定；给了 ⇒ 重排也能保住 `State`。
     * `ForEach` 的 key 是**必填**的，因为列表重排最需要它。
     * @api
     */
    public function key(string|int $value): static
    {
        $this->mutate();
        $this->key = new Key($value);

        return $this;
    }

    /**
     * 子层拿到的渲染环境。
     * 默认原样递下去。**只有"面覆盖"型节点才覆写**（如 `Drift\Theme\ThemeScope` 换主题）。
     * 返回 `BuildContext` 而非更具体的类型，所以 `Drift\Widget` 不需要认识 `Drift\Theme`。
     * @internal Element 挂载 / 更新时调它，用户不需要。
     */
    public function childContext(BuildContext $context): BuildContext
    {
        return $context;
    }

    /**
     * 造 Element —— **唯一入口**。
     * 收口成一处 `instanceof`，而不是让每个控件子类各写一遍 `return new
     *   XxxElement($this)`（漏抄不会报错，只会静默走错元素类型）。要特殊元素的（容器）可覆写。
     * @internal 用户不该自己 new Element。
     */
    public function createElement(): Element
    {
        return $this instanceof StatefulWidget
            ? new StatefulElement($this)
            : new StatelessElement($this);
    }

    /**
     * diff 用的身份：`null` = 由位置决定。
     * @internal Element 调和时读它，用户不需要（给了 Key 就已经生效）。
     */
    public function identity(): ?Key
    {
        return $this->key;
    }

    /**
     * 挂载时由 Element 调用：把描述封存为不可变。
     * 幂等 —— 同一实例复用到多处是**合法**的（Widget 是纯描述，各处各有自己的
     *   Element），所以这里不做 owner 校验。`Column::make()->children([$t, $t])` 合法。
     * @internal 不进公共面：用户不需要它，"挂载时自动封存"已覆盖全部合法场景。
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
     * 封存后写入 ⇒ 响亮失败（Fatal 级，release 也中止）。
     * 应用自定义 Widget 写自己的链式方法时**必须**调它，否则能绕过封存、在挂载后改字段。
     * @api
     */
    final protected function mutate(): void
    {
        if ($this->sealed === 1) {
            Errors::report('Widget 已封存，禁止再修改：' . static::class);
        }
    }

    // ── 焦点声明 ──────────────────────────────────────────────────────
    // 这三个是**声明**，不是状态："现在谁聚焦"住在 Drift\App\FocusManager。
    //   Widget 挂载后不可变，把可变焦点塞进来会撞纪律。




    // ── 修饰：字段，不是节点 ──────────────────────────────────────────
    // 全部走同一个 `$this->mods`（值对象，**换新不就地改**）⇒ `Element::syncBox()`
    //   只递一个引用给盒，不必为每个修饰加一条传递路径。
    // 命名上两处刻意避让（撞名是类声明期 Fatal）：`size()` 被字号/尺寸占用 ⇒ 尺寸修饰
    //   叫 `boxSize()`；`center()` 被 `Dock` 槽位占用 ⇒ 居中修饰叫 `centerContent()`。
    // 仍必须覆写的只有两类：`padding`（容器要拿它参与排布数学，塞回 `$mods` 会双份内距）、
    //   `width`/`height`（控件尺寸就是它的语义，如 `Divider` 粗细、`Image` 自然尺寸）。

    /** @internal `Element::syncBox()` 递它；永远非空 */
    public function mods(): Modifier
    {
        return $this->mods;
    }

    /**
     * 本控件展开出的子控件（`ProxyElement` 的唯一输入）。
     * 新控件覆写自己的方法即可（不必回头改 `ProxyElement`）。
     * 默认 `[]`：容器控件（`Column` / `Row` / `Dock`）走各自的 Element，不经过这里。
     * @internal `ProxyElement` 调；应用侧不该用
     * @return list<Widget>
     */
    public function expandChildren(): array
    {
        return [];
    }



















































}
