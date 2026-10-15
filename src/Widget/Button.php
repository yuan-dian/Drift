<?php
declare(strict_types=1);

namespace Drift\Widget;

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
 *   - `->command('名')->arg(...)` = 走命令系统（依赖 `Drift\Commands`，M3）
 *     **M2 里响亮说没实现**，不是静默忽略注册。
 *
 * @api
 */
final class Button extends BoxWidget
{
    private ButtonVariant $variant = ButtonVariant::Default;
    private ?\Closure $onPressed = null;
    private ?string $command = null;

    public function __construct(private readonly string $label)
    {
        parent::__construct();
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

    /** 走命令系统（M3 才接上） */
    public function command(string $name): static
    {
        $this->mutate();
        $this->command = $name;

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

    public function createRenderObject(BuildContext $context): RenderBox
    {
        if ($this->command !== null) {
            // M2 还没接命令系统 —— 响亮说没实现，不静默留下一个按了没反应的按钮
            Errors::report(sprintf(
                "Button '%s' 用了 ->command('%s')，但命令系统属 M3（§19）—— M2 请用 ->onPressed()",
                $this->label,
                $this->command,
            ));
        }

        // §15.7 纪律 3：变体 → token → 值，**只在这里合并一次**。
        // 经 BuildContext 取（Widget 层唯一被允许碰主题的入口），Render 层只收值。
        $theme = $context;
        $radius = $theme->metric(MetricToken::Radius);
        $gap = $theme->metric(MetricToken::Gap);
        $border = $theme->metric(MetricToken::BorderWidth);

        [$box, $labelColor] = match ($this->variant) {
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
