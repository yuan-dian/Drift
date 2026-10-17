<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Commands\CommandArg;
use Drift\Errors;
use Drift\Geometry\EdgeInsets;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderButton;
use Drift\Rendering\BoxStyle;
use Drift\Text\TextRole;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;

/**
 * 按钮（§A.1 / §A.3.7）。
 *
 * ```php
 * Button::primary('刷新')->command('users.refresh');
 * Button::make('取消')->onPressed(fn() => $this->closeModal());
 * ```
 *
 * ★ **继承 `BoxWidget`**（不是结构节点）—— 它有底色 / 边框 / 圆角，正是
 *   §15.7 说"需要盒样式的控件"那一类。
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
class Button extends BoxWidget
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

    public function __construct(protected readonly string $label)
    {
        parent::__construct();
        // §9.1：按钮天然可聚焦（Tab 能到、回车/空格能触发）
        $this->markFocusable();
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

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // §15.7 纪律 3：变体 → token → 值，**只在这里合并一次**。
        // 经 BuildContext 取（Widget 层唯一被允许碰主题的入口），Render 层只收值。
        $theme = $context;
        $radius = $theme->metric(MetricToken::Radius);
        $gap = $theme->metric(MetricToken::Gap);
        $border = $theme->metric(MetricToken::BorderWidth);

        [$themeBox, $labelColor] = match ($this->variant) {
            ButtonVariant::Default => [
                new BoxStyle(
                    $theme->color(ColorToken::Chrome),
                    $theme->color(ColorToken::Border),
                    $border,
                    $radius,
                ),
                $theme->color(ColorToken::Text),
            ],
            ButtonVariant::Primary => [
                new BoxStyle($theme->color(ColorToken::Accent), null, 0.0, $radius),
                $theme->color(ColorToken::Pane), // 强调底上用画布色当字色 ⇒ 明暗自适应
            ],
            ButtonVariant::Danger => [
                new BoxStyle($theme->color(ColorToken::Danger), null, 0.0, $radius),
                $theme->color(ColorToken::Pane),
            ],
        };

        // ★ §15.7 修正：用户经 `BoxWidget::background()/border()/radius()` 设的盒样式
        //   （`$this->box()`）此前被这里忽略 ⇒ 修饰符是死代码（其 docblock 写"渲染层读它"）。
        //   字段级合并：用户设了（非 null/非零）就用用户的，否则回退主题默认盒。
        //   ★ 边缘：radius 取 `> 0` 才用用户的（BoxStyle 以 0 表示"未设/直角"），故无法用修饰符把圆角显式设回 0。
        $userBox = $this->box();
        $box = $userBox === null ? $themeBox : new BoxStyle(
            $userBox->hasBackground() ? $userBox->background : $themeBox->background,
            $userBox->hasBorder() ? $userBox->borderColor : $themeBox->borderColor,
            $userBox->hasBorder() ? $userBox->borderWidth : $themeBox->borderWidth,
            $userBox->radius > 0.0 ? $userBox->radius : $themeBox->radius,
        );

        return new RenderButton(
            $this->label,
            $box,
            $labelColor,
            EdgeInsets::symmetric($gap * 2.0, $gap),
            $theme->textSize(TextRole::Body),
            $context->metrics(),
        );
    }
}
