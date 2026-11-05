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
 * 按钮。
 * 继承 `RenderBoxWidget`（产渲染盒，几何由 `RenderButton` 算）；盒样式不另存，
 * 经通用修饰写进 `$mods`，本类 `resolveBoxStyle()` 再从 `$mods` 派生 `BoxStyle`。
 * 两条输入路径语义不同：`onPressed(闭包)` 是纯 UI 回调；`command('名')->arg(...)`
 * 走命令系统（冒泡链）。按下即激活（`down` 相位）。`NavButton` 继承它，共享同
 * 一条链与同一个渲染盒。
 * @api
 */
class Button extends RenderBoxWidget implements Activatable
{
    // ★ 字段不能是 private：tpc 按**运行时类型**查属性表，父类的 `private` 不进子类
    //   属性表 ⇒ 子类实例（`NavButton` / `Menu` / `MenuItem`）访问它们会**动态创建
    //   属性**（值为 NULL ⇒ `RenderButton::__construct()` 第 1 参数 `$label` 未传）。
    protected ButtonVariant $variant = ButtonVariant::Default;

    protected ?\Closure $onPressed = null;

    protected ?string $command = null;

    protected ?CommandArg $commandArg = null;

    /**
     * 前置图标（`null` = 无图标）。
     * ★ 图标与标签在**同一个渲染盒**里排（不是 `Row`）—— `Flex::createRenderObject()`
     *   只建 `RenderFlex` 空壳、子盒由 Element 挂，手工拼 `Row(图标, 文字)` 会得到
     *   一个没有孩子的 `RenderFlex`。
     */
    protected ?IconName $icon = null;

    /**
     * 标签是否**画出来**（`false` = 纯图标按钮，标签只作可访问名）。
     * 用字段而不是让 `IconButton` 覆写虚方法：tpc 全程序去虚化会让"基类默认 +
     * 子类覆写"静默失效。
     */
    protected bool $labelVisible = true;

    /**
     * 内容是否在盒内居中（`false` = 左对齐）。
     * `NavButton` 构造时置 `false`：菜单 / 导航项左对齐，否则图标会随标签长度漂移。
     * 单个按钮要左对齐用 `alignLeft()`。
     */
    protected bool $centerLabel = true;

    /**
     * 紧凑内边距（`false` = 常规：横向 `2×gap`、纵向 `1×gap`）。
     * 日历格子等被父容器等分的场景必须压到 `1×gap`，否则按钮最小宽超过格宽 ⇒ 布局超界。
     */
    protected bool $compactPadding = false;

    public function __construct(protected readonly string $label)
    {
        parent::__construct();
        // 按钮天然可聚焦（Tab 能到、回车/空格能触发）
        $this->markFocusable();
        // 按钮的可访问名 = label；字段赋值而非虚方法覆写（tpc 去虚化会让覆写静默失效）
        $this->semantics(SemanticsRole::Button, $label);
    }

    public static function make(string $label): self
    {
        return new self($label);
    }

    /** 变体工厂，糖只转发 */
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

    /**
     * 禁用 / 恢复（`enabled(false)` ≡ `disabled()`）。
     * 状态只有一份（`Modifier::$disabled`），没有第二个 `enabled` 字段。
     * 禁用态做三件事：不激活、外观变淡、不进 Tab 序且语义上报 disabled。
     * @api
     */
    public function enabled(bool $enabled): static
    {
        return $this->disabled(!$enabled);
    }

    /** 见 {@see self::enabled()} */
    public function isEnabled(): bool
    {
        return !$this->mods()->disabled();
    }

    /** 走命令系统（键盘 Enter/Space 与指针按下两路径同激活） */
    public function command(string $name): static
    {
        $this->mutate();
        $this->command = $name;

        return $this;
    }

    /** 命令参数（`none/id/key` 三形态，不裸传 int/string） */
    public function arg(CommandArg $arg): static
    {
        $this->mutate();
        $this->commandArg = $arg;

        return $this;
    }

    /**
     * 前置图标（工具条 / 导航项用）。
     * Button::make('刷新')->icon(IconName::Refresh);
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
     * 与 `NavButton` 的分工：那个是"这类按钮天生左对齐"（构造期置死），
     * 本方法是"这一个按钮要左对齐"。`TreeView` 的行必须用它（标签被 `Expanded`
     * 撑满，居中会让整棵树看着像居中的文字，缩进层级读不出来）。
     */
    public function alignLeft(): static
    {
        $this->mutate();
        $this->centerLabel = false;

        return $this;
    }

    /**
     * 紧凑内边距（横向 `1×gap`、纵向 `1×gap`）。
     * 只解决"塞得进"，不解决"看起来紧凑" —— 间距仍由父容器的 gap 决定。
     * Button::make('29')->compact()->variant(ButtonVariant::Flat);
     */
    public function compact(): static
    {
        $this->mutate();
        $this->compactPadding = true;

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
     * 命令优先：`command('x')` 声明了就走全链（冒泡 → 注册表）；
     * `onPressed` 是纯 UI 回调，作为没声明命令时的兜底。
     */
    public function activate(ActivationContext $context): void
    {
        // 禁用 ⇒ 什么都不做（激活是"行为"，不该靠上游记得拦）。
        if ($this->mods()->disabled()) {
            return;
        }

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
        // 变体 → token → 值，只在这里合并一次（经 BuildContext 取，Render 层只收值）。
        $gap = $context->metric(MetricToken::Gap);
        $fontSize = $context->textSize(TextRole::Body);

        // 纯图标按钮要正方形：文字按钮横向内边距是纵向两倍（给字宽留呼吸），
        // 图标没字宽，两倍横向会撑成胶囊。
        // 不用三元：两支类型不同 ⇒ tpc 的 operator?: 会编译失败（AOT 约束）。
        $insets = EdgeInsets::symmetric($gap * 2.0, $gap);
        if (!$this->labelVisible) {
            $insets = EdgeInsets::all($gap * 1.5);
        }
        // 紧凑档最后覆盖：被父容器等分的按钮，最小宽必须让得出来
        if ($this->compactPadding) {
            $insets = EdgeInsets::all($gap);
        }

        // 图标与文字同高（+4 让笔画不显局促），线宽按图标渲染的同一口径推
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
            $this->resolvePressedOverlay($context),
        );
    }

    /**
     * @internal 见 `RenderBoxWidget::paintsBoxChrome()`：`RenderButton::paintOwnChrome()`
     *   自己画底 / 边 / 浮起影子（两相位叠色由 `RenderBox::paint()` 统一画）
     *   ⇒ 交给盒的 `$mods` 要剥掉视觉三字段，
     *   ★ 否则描边会画两遍（不幂等，golden 实测差 32 像素）。
     */
    public function paintsBoxChrome(): bool
    {
        return true;
    }

    /**
     * 悬停浮起档位（`Elevation` 口径）。
     * `Flat` 不浮：它是"没有容器的文字/图标"，浮起来会凭空长出盒子。
     * 不放 `BuildContext`：与变体绑定，不随主题变。
     */
    protected function resolveHoverElevation(): float
    {
        return match ($this->variant) {
            ButtonVariant::Flat => Elevation::Flat,
            ButtonVariant::Default, ButtonVariant::Primary, ButtonVariant::Danger => Elevation::Raised,
        };
    }

    /**
     * 变体 → 盒样式（用户经 `background()/border()/radius()` 设的优先）。
     * 字段级合并：用户设了（非 null/非零）就用用户的，否则回退主题默认盒。
     * radius 取 `> 0` 才用用户的（BoxStyle 以 0 表示"未设/直角"）。
     * 用户设的那一份从 `$mods` 派生（三个修饰方法不再由 `BoxWidget` 覆写），
     * "用户设过没有"由 `Modifier` 的 `$hasBackground` / `$hasBorder` / `radius !== 0` 表达。
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
            // 扁平：什么都不画（`BoxStyle::none()`）；渲染盒照常占位、命中 ⇒ 视觉无盒但行为仍是按钮。
            ButtonVariant::Flat => BoxStyle::none(),
        };

        $userBox = $this->mods()->boxStyle();
        if ($userBox === null) {
            return $themeBox;
        }

        // 用户设了渐变 ⇒ 渐变就是底（`hasBackground()` 已算作"有底"）。实色用 `??`
        // 而非 `hasBackground() ?`：只设渐变时 `$userBox->background` 是 null，取主题色当 base。
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
     * 悬停状态色：Default/Flat 用主题 Hover 底；Primary/Danger 是实心强调底，
     * 叠 Hover 会被染灰 ⇒ 改用白色半透明提亮。
     * 解析在这里（Widget 层能碰主题），RenderButton 只收值。
     */
    protected function resolveHoverOverlay(BuildContext $context): ?Color
    {
        return match ($this->variant) {
            ButtonVariant::Default, ButtonVariant::Flat => $context->color(ColorToken::Hover),
            ButtonVariant::Primary, ButtonVariant::Danger => new Color(255, 255, 255, 40),
        };
    }

    /**
     * 按下态叠加色（`ColorToken::Pressed` —— 2026-11-04 之前**零消费者**）。
     *
     * ★ 两档色**必须递进**：按下比悬停深，否则"按下去"和"指着"看起来一样，
     *   用户会重复点。底色为深色的变体（Primary / Danger）没有更深的主题档可退，
     *   用**同一白但更高 alpha**（40 → 70）表达"又压深一档"。
     *
     * @api
     */
    protected function resolvePressedOverlay(BuildContext $context): ?Color
    {
        return match ($this->variant) {
            ButtonVariant::Default, ButtonVariant::Flat => $context->color(ColorToken::Pressed),
            ButtonVariant::Primary, ButtonVariant::Danger => new Color(255, 255, 255, 70),
        };
    }
}
