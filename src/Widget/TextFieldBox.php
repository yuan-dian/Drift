<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Scale;
use Drift\Input\CursorShape;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextField;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Text\TextRole;
use Drift\Text\TextStyle;

/**
 * `TextField` 的盒（§13.2 的 Widget → RenderBox 构造契约）。
 *
 * ★ **为什么存在**：`TextField` 必须有纯 UI 态（编辑缓冲 + 插入符），所以它只能是
 *   `StatefulWidget`。2026-10-23 之前"有态"与"有盒"因此必须是两个 Widget
 *   （Flutter `TextField → EditableText → RenderEditable` 的三段形态）。
 *
 * ★ **今天它仍是三件套，但是"有子"的特例，不是框架要求**（`02` §6.4）：
 *   `createRenderObjectFromState()` 让"有态 + 有盒 + **无子**"的控件两段就够
 *   （`Spinner` 已改造，`SpinnerBox` 已删），但那个钩子的语义是"**不产子**"，
 *   而 `TextField` 有子（label + 输入框）⇒ 套上去会静默丢掉子层。
 *   ⇒ 下一个写控件的人：**叶子用钩子，有子才需要 `*Box`**。
 *
 * ★ **它只做一件事**：把主题 token 解析成值，交给 `RenderTextField`（§15.7 纪律 3
 *   的合并点）。于是 `Drift\Layout` 那边不必也不许碰 Theme。
 *
 * ★ `@internal`：应用不直接用它。字段的公共面在 `TextField` / `TextFieldState`。
 *
 * @internal
 */
final class TextFieldBox extends RenderBoxWidget
{
    private function __construct(
        private readonly string $text,
        private readonly string $placeholder,
        private readonly ?int $caretOffset,
        private readonly int $selectionStart,
        private readonly int $selectionEnd,
        private readonly bool $invalid,
        private readonly bool $obscure = false,
    ) {
        parent::__construct();
        // §9.1 命中三方消费：指针停在字段上给 I 形光标（文本编辑的通用约定）。
        // ★ 声明在**这里**而不是 `TextField`：`Element::syncBox()` 只把光标
        //   落在**自己产盒**的 Widget 上，`TextField` 是 `StatefulWidget`
        //   （自己不产盒）⇒ 那边的 `cursor()` 是静默无效的调用。
        $this->cursor(CursorShape::IBeam);
    }

    /** @internal 由 `TextFieldState::build()` 调 */
    public static function make(
        string $text,
        string $placeholder,
        ?int $caretOffset,
        int $selectionStart,
        int $selectionEnd,
        bool $invalid = false,
        bool $obscure = false,
    ): self {
        return new self($text, $placeholder, $caretOffset, $selectionStart, $selectionEnd, $invalid, $obscure);
    }

    public function text(): string
    {
        return $this->text;
    }

    public function placeholder(): string
    {
        return $this->placeholder;
    }

    public function caretOffset(): ?int
    {
        return $this->caretOffset;
    }

    public function selectionStart(): int
    {
        return $this->selectionStart;
    }

    public function selectionEnd(): int
    {
        return $this->selectionEnd;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // §15.7 纪律 3：token → 值，只在这里合并一次。
        // ★ 单行：`maxLines(1)` 由这里保证（`RenderTextField` 的插入符几何依赖它）。
        $style = TextStyle::of($context->textSize(TextRole::Body))->withMaxLines(1);

        $gap = $context->metric(MetricToken::Gap);
        $radius = $context->metric(MetricToken::Radius);
        $border = $context->metric(MetricToken::BorderWidth);

        // 输入"井"：底色用画布色、描边用边框色（与 Button::Default 的 Chrome 底区分开
        // —— 输入区应当是"凹"的观感，按钮是"凸"的）。出错只换描边色，底与几何不动。
        $borderColor = $this->invalid
            ? $context->color(ColorToken::Danger)
            : $context->color(ColorToken::Border);
        $box = new BoxStyle(
            $context->color(ColorToken::Pane),
            $borderColor,
            $border,
            $radius,
        );

        $textColor = $context->color(ColorToken::Text);
        $render = new RenderTextField(
            $this->text,
            $style,
            $context->textColor(TextRole::Body),
            $this->placeholder,
            $context->textColor(TextRole::Caption),   // 占位符 = 弱化色
            $box,
            EdgeInsets::symmetric($context->metric(MetricToken::Padding), $gap * 0.5),
            $this->caretOffset,
            $textColor,
            $border,
            // 选区高亮：强调色向画布色混 72%（不透明混色，不用 alpha —— 软/硬两个
            // 后端对半透明的处理不一致，§14.1 的后端等价性就不能挂在运气上）。
            // 主题换了表就自动跟随，控件一行不改。
            Color::lerp(
                $context->color(ColorToken::Accent),
                $context->color(ColorToken::Pane),
                0.72,
            ),
            $this->selectionStart,
            $this->selectionEnd,
            $context->metrics(),
            new Scale(1.0),
            $this->obscure,
        );

        // 反馈 = 边框朝文字色递进两档（亮主题变深、暗主题变亮，方向由主题自己给）。
        // 不叠主题 Hover/Pressed：输入井底就是 Pane，那两个 token 与它只差几级，
        // 填充叠色在亮主题下看不见，且 hover 变亮 / press 变暗与按钮的递进相反。
        $render->setInteraction(
            Color::lerp($borderColor, $textColor, 0.28),
            Color::lerp($borderColor, $textColor, 0.50),
        );

        return $render;
    }
}
