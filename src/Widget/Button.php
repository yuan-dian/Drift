<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Commands\CommandArg;
use Drift\Errors;
use Drift\Geometry\EdgeInsets;
use Drift\Input\SemanticsRole;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderButton;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;
use Drift\Text\TextRole;
use Drift\Rendering\ColorToken;
use Drift\Rendering\Elevation;
use Drift\Rendering\MetricToken;

/**
 * 按钮（§A.1 / §A.3.7）。
 *
 * ```php
 * Button::primary('刷新')->command('users.refresh');
 * Button::make('取消')->onPressed(fn() => $this->closeModal());
 * ```
 *
 * ★ **继承 `RenderBoxWidget`**（不是结构节点）—— 它产渲染盒，几何由 `RenderButton`
 *   算。盒样式（底 / 边 / 圆角）**不另存**：`->background()` 走 `Widget` 的通用修饰
 *   写进 `$mods`，本类的 `resolveBoxStyle()` 再从那里**派生**出 `BoxStyle` 交给
 *   `RenderButton`（S1，2026-10-28 —— 此前是 `BoxWidget` 里的第二份存储，见
 *   `Modifier::boxStyle()` 的注）。
 *
 * ★ **两条输入路径，语义不同**：
 *   - `->onPressed(闭包)` = 纯 UI 回调，零框架依赖（§A.3.7 就是这么用的）
 *   - `->command('名')->arg(...)` = 走命令系统（`CommandRouter` + 冒泡链，M3 已通）
 *   ★ **激活时机**（2026-10-16 P0 修复）：**按下即激活**（`down` 相位 ——
 *     `Application::dispatchInput()` 的 focusHit + activateFocusedWidget）。
 *     `up` 相位（2026-10-17 落地）服务于**拖放放下**，按钮激活仍住 down
 *     （不迁移到 up：press/release 视觉态无消费者，A15）。
 *   ★ **悬停**：`->hover(fn(bool $entered) => ...)`（`Widget` 基类）—— 指针
 *     `move` 相位派发，**目标变化才通知**（§9.1 语义 #5）。菜单项高亮即此用法。
 *
 * ★ **不是 `final`**：`NavButton`（§12.2 外壳词汇）继承它 —— 导航按钮就是一种
 *   按钮，共享同一条链与同一个渲染盒，不为一个新名字复制一套机制。
 *
 * @api
 */
class Button extends RenderBoxWidget implements Activatable
{
    // ★ **AOT：会被继承的类，字段不能是 private** —— tpc 按**运行时类型**查属性表，
    //   而父类的 `private` 不进子类属性表 ⇒ 子类实例（`NavButton` / `Menu` / `MenuItem`）
    //   上访问它们会**动态创建属性**（`Deprecated`，且值是 NULL ⇒ 紧接着
    //   `ArgumentCountError: RenderButton::__construct(): Argument #1 ($label) not passed`）。
    //   与 `Element::$box` / `RenderBox::$size` 同一条纪律（那两处早就是 protected）。
    //   2026-10-16 真机跑 `users.exe` 时当场崩出来 —— 解释器面 496 断言 / lint / codegen 全绿，
    //   只有真机能看见。
    protected ButtonVariant $variant = ButtonVariant::Default;

    protected ?\Closure $onPressed = null;

    protected ?string $command = null;

    protected ?CommandArg $commandArg = null;

    /**
     * 前置图标（`null` = 无图标）。
     *
     * ★ 图标与标签在**同一个渲染盒**里排（不是 `Row`）—— `Flex::createRenderObject()`
     *   只建 `RenderFlex` 空壳、子盒由 Element 挂，手工拼 `Row(图标, 文字)` 会得到
     *   一个没有孩子的 `RenderFlex`（详见 `RenderButton` 类注释）。
     */
    protected ?IconName $icon = null;

    /**
     * 标签是否**画出来**（`false` = 纯图标按钮，标签只作可访问名）。
     *
     * ★ 用字段而不是让 `IconButton` 覆写虚方法：tpc 全程序去虚化会让"基类默认 +
     *   子类覆写"静默失效（见 `Widget::semantics()` 同款注释）。
     */
    protected bool $labelVisible = true;

    /**
     * 内容是否在盒内居中（`false` = 左对齐）。
     *
     * ★ `NavButton` 构造时置 `false`：菜单 / 导航项左对齐，否则"图标 + 文字"这个
     *   整体会按标签长度各自漂移（短标签的图标比长标签靠右 20+ px，看着没对齐）。
     * ★ 单个按钮要左对齐用 `alignLeft()`（`TreeView` 的行就是这个场景）——
     *   借 `NavButton` 会把"树行"和"导航项"焊在一起，改侧栏对齐会波及树。
     * ★ 与 `labelVisible` 同一条纪律：字段而不是覆写虚方法（tpc 去虚化）。
     */
    protected bool $centerLabel = true;

    public function __construct(protected readonly string $label)
    {
        parent::__construct();
        // §9.1：按钮天然可聚焦（Tab 能到、回车/空格能触发）
        $this->markFocusable();
        // §9 无障碍：按钮的可访问名 = label。**字段赋值**（不是虚方法覆写 ——
        // tpc 全程序去虚化会让"基类默认+子类覆写"静默失效，见 Widget::semantics()）
        $this->semantics(SemanticsRole::Button, $label);
    }

    public static function make(string $label): self
    {
        return new self($label);
    }

    /** §15.4：变体工厂 = `make()` 的薄壳别名，糖只转发 */
    public static function primary(string $label): self
    {
        return self::make($label)->variant(ButtonVariant::Primary);
    }

    public static function danger(string $label): self
    {
        return self::make($label)->variant(ButtonVariant::Danger);
    }

    public function variant(ButtonVariant $variant): static
    {
        $this->mutate();
        $this->variant = $variant;

        return $this;
    }

    /** 纯 UI 回调（不依赖命令系统） */
    public function onPressed(callable $handler): static
    {
        $this->mutate();
        $this->onPressed = \Closure::fromCallable($handler);

        return $this;
    }

    /** 走命令系统（键盘 Enter/Space 与指针按下两路径同激活，§24.2 #16） */
    public function command(string $name): static
    {
        $this->mutate();
        $this->command = $name;

        return $this;
    }

    /** 命令参数（§11.4.3：`none/id/key` 三形态，不裸传 int/string） */
    public function arg(CommandArg $arg): static
    {
        $this->mutate();
        $this->commandArg = $arg;

        return $this;
    }

    /**
     * 前置图标（工具条 / 导航项用）。
     *
     * ```php
     * Button::make('刷新')->icon(IconName::Refresh);
     * NavButton::make('列表 List')->icon(IconName::ListBullets);
     * ```
     */
    public function icon(IconName $icon): static
    {
        $this->mutate();
        $this->icon = $icon;

        return $this;
    }

    public function iconValue(): ?IconName
    {
        return $this->icon;
    }

    /**
     * 内容左对齐（图标 + 标签靠左）。
     *
     * ★ 与 `NavButton` 的分工：那个是"这类按钮**天生**左对齐"（构造期置死），
     *   本方法是"**这一个**按钮要左对齐"。`TreeView` 的行必须用这个 —— 行里的
     *   标签被 `Expanded` 撑满剩余宽度（点空白也算点这一行），居中会让整棵树
     *   看着像一段居中的文字，缩进层级完全读不出来。
     */
    public function alignLeft(): static
    {
        $this->mutate();
        $this->centerLabel = false;

        return $this;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function variantValue(): ButtonVariant
    {
        return $this->variant;
    }

    public function pressedHandler(): ?\Closure
    {
        return $this->onPressed;
    }

    public function commandName(): ?string
    {
        return $this->command;
    }

    public function commandArg(): ?CommandArg
    {
        return $this->commandArg;
    }

    /**
     * 激活（`Activatable`）—— 命令优先，`onPressed` 兜底。
     *
     * ★ 这段逻辑原先住 `InputRouter::activateFocusedWidget()` 的末段，且前面要挡
     *   三个 `instanceof`（`Menu` / `ToggleBase` / `Segment`）。搬到控件自己身上之后
     *   框架不再需要知道"按钮长什么样"，而 `Menu` 只需覆写本方法就能改变激活语义
     *   （见 `Menu::activate()`）。
     *
     * ★ **命令优先**：`->command('x')` 声明了就走全链（冒泡 → 注册表），
     *   `onPressed` 是纯 UI 回调，作为没声明命令时的兜底 —— 与搬迁前逐字相同。
     */
    public function activate(ActivationContext $context): void
    {
        $name = $this->commandName();
        if ($name !== null) {
            $context->dispatch($name, $this->commandArg() ?? CommandArg::none());

            return;
        }

        $handler = $this->pressedHandler();
        if ($handler !== null) {
            $handler();
        }
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // §15.7 纪律 3：变体 → token → 值，**只在这里合并一次**。
        // 经 BuildContext 取（Widget 层唯一被允许碰主题的入口），Render 层只收值。
        $gap = $context->metric(MetricToken::Gap);
        $fontSize = $context->textSize(TextRole::Body);

        // ★ 纯图标按钮要**正方形**：文字按钮的横向内边距原本是纵向的两倍（给字宽
        //   留呼吸），图标没有字宽，两倍横向会把 40 高撑成 56 宽的胶囊。
        // ★ 不用三元：两支类型不同 ⇒ tpc 的 `operator?:` 编译成 C2678（AOT 约束 A2）。
        $insets = EdgeInsets::symmetric($gap * 2.0, $gap);
        if (!$this->labelVisible) {
            $insets = EdgeInsets::all($gap * 1.5);
        }

        // 图标与文字同高（+4 让笔画不显局促）；线宽按 §RenderIcon 的同一口径推
        $iconSize = $fontSize + 4.0;

        $label = $this->label;
        if (!$this->labelVisible) {
            $label = '';
        }

        $iconPath = null;
        if ($this->icon !== null) {
            $iconPath = IconSet::path($this->icon, $iconSize);
        }

        return new RenderButton(
            $label,
            $this->resolveBoxStyle($context),
            $this->resolveForeground($context),
            $insets,
            $fontSize,
            $context->metrics(),
            $this->resolveHoverOverlay($context),
            $iconPath,
            $iconSize,
            $gap,
            max(1.0, $iconSize * 0.09),
            $this->centerLabel,
            $this->resolveHoverElevation(),
        );
    }

    /**
     * @internal 见 `RenderBoxWidget::paintsBoxChrome()`：`RenderButton::paintOwnChrome()`
     *   自己画底 / 边 / 悬停层 / 浮起影子 ⇒ 交给盒的 `$mods` 要剥掉视觉三字段，
     *   否则描边会画两遍（**不幂等**，golden 实测差 32 像素）。
     */
    public function paintsBoxChrome(): bool
    {
        return true;
    }

    /**
     * 悬停浮起档位（`Elevation` 口径）。
     *
     * ★ `Flat` 不浮：它是"没有容器的文字/图标"（顶条动作、行内操作），浮起来
     *   会凭空长出一个不存在的盒子 —— 状态色已经足够表达悬停。
     * ★ 不放 `BuildContext`：与变体绑定，不随主题变。
     */
    protected function resolveHoverElevation(): float
    {
        return match ($this->variant) {
            ButtonVariant::Flat => Elevation::Flat,
            ButtonVariant::Default, ButtonVariant::Primary, ButtonVariant::Danger => Elevation::Raised,
        };
    }

    /**
     * 变体 → 盒样式（**用户经 `background()/border()/radius()` 设的优先**）。
     *
     * ★ §15.7 修正：用户设的盒样式此前被忽略 ⇒ 修饰符是死代码（其 docblock 写"渲染层读它"）。
     *   字段级合并：用户设了（非 null/非零）就用用户的，否则回退主题默认盒。
     *   ★ 边缘：radius 取 `> 0` 才用用户的（BoxStyle 以 0 表示"未设/直角"），故无法用修饰符把圆角显式设回 0。
     * ★ 用户设的那一份**从 `$mods` 派生**（S1）：三个修饰方法不再由 `BoxWidget` 覆写，
     *   所以"用户设过没有"这个三态由 `Modifier` 的 `$hasBackground` / `$hasBorder`
     *   / `radius !== 0` 表达 —— 与 `boxStyle()` 的判据是同一处，不会漂。
     */
    protected function resolveBoxStyle(BuildContext $context): BoxStyle
    {
        $radius = $context->metric(MetricToken::Radius);
        $border = $context->metric(MetricToken::BorderWidth);

        $themeBox = match ($this->variant) {
            ButtonVariant::Default => new BoxStyle(
                $context->color(ColorToken::Chrome),
                $context->color(ColorToken::Border),
                $border,
                $radius,
            ),
            ButtonVariant::Primary => new BoxStyle($context->color(ColorToken::Accent), null, 0.0, $radius),
            ButtonVariant::Danger => new BoxStyle($context->color(ColorToken::Danger), null, 0.0, $radius),
            // ★ 扁平：什么都不画（`BoxStyle::none()`），字色走正文色。
            //   渲染盒照常占位、照常命中 ⇒ 视觉上"没有盒子"但行为仍是按钮。
            ButtonVariant::Flat => BoxStyle::none(),
        };

        $userBox = $this->mods()->boxStyle();
        if ($userBox === null) {
            return $themeBox;
        }

        // ★ 用户设了渐变 ⇒ 渐变就是底（`BoxStyle::hasBackground()` 已把它算作"有底"）。
        //   实色那一格用 `??` 而不是 `hasBackground() ?`：只设渐变时
        //   `$userBox->background` 是 null，取主题色当 base（alpha 更贴近真实观感），
        //   而渐变本身在 `backgroundPaint()` 里优先生效。
        return new BoxStyle(
            $userBox->background ?? $themeBox->background,
            $userBox->hasBorder() ? $userBox->borderColor : $themeBox->borderColor,
            $userBox->hasBorder() ? $userBox->borderWidth : $themeBox->borderWidth,
            $userBox->radius > 0.0 ? $userBox->radius : $themeBox->radius,
            $userBox->backgroundGradient ?? $themeBox->backgroundGradient,
        );
    }

    /** 变体 → 前景色（标签与图标共用；强调底上用画布色 ⇒ 明暗自适应） */
    protected function resolveForeground(BuildContext $context): Color
    {
        return match ($this->variant) {
            ButtonVariant::Primary, ButtonVariant::Danger => $context->color(ColorToken::Surface),
            default => $context->color(ColorToken::Text),
        };
    }

    /**
     * 悬停状态色（`08` §4.1）：Default/Flat 用主题 Hover 底；Primary/Danger 是
     * 实心强调底，叠 Hover 会被染成灰 ⇒ 改用白色半透明（提亮）。
     *
     * ★ 解析在这里（Widget 层能碰主题），RenderButton 只收值。
     */
    protected function resolveHoverOverlay(BuildContext $context): ?Color
    {
        return match ($this->variant) {
            ButtonVariant::Default, ButtonVariant::Flat => $context->color(ColorToken::Hover),
            ButtonVariant::Primary, ButtonVariant::Danger => new Color(255, 255, 255, 40),
        };
    }
}
