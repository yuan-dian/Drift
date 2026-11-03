<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Input\CursorShape;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextArea;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Text\TextRole;
use Drift\Text\TextStyle;

/**
 * `TextArea` 的盒（与 `TextFieldBox` 同形，多行的那一份）。
 *
 * ★ **只做一件事**：把主题 token 解析成值，交给 `RenderTextArea`（§15.7 纪律 3
 *   的合并点）。于是 `Drift\Layout` 那边不必也不许碰 Theme。
 *
 * ★ 与 `TextFieldBox` 的差别只有三处，是好事 —— 说明"多行"没有渗进颜色 / 圆角 /
 *   描边那些**与行数无关**的决定：
 *   ① 样式是 `maxLines(0)`（不限行），单行那份是 `maxLines(1)`；
 *   ② 多给 `minHeight`（`minLines` 折算）；
 *   ③ 多给 `scrollOffset`（垂直滚动；单行没有这个概念）。
 *
 * ★ 这里的 `*Box` 而不是 `createRenderObjectFromState()`：`TextArea` 有子
 *   （label + 输入区），那个钩子的语义是"**不产子**"（见 `TextFieldBox` 注释）。
 *
 * @internal
 */
final class TextAreaBox extends RenderBoxWidget
{
    private function __construct(
        private readonly string $text,
        private readonly string $placeholder,
        private readonly ?int $caretOffset,
        private readonly int $selectionStart,
        private readonly int $selectionEnd,
        private readonly int $minLines,
        /** 固定框高（行数；`0` = 按内容长）—— `TextArea::lines()` */
        private readonly int $lines,
        private readonly float $scrollOffset,
        /** 滚动条淡出透明度（`ScrollBarFade` 的值，`build()` 每帧递） */
        private readonly float $barOpacity = 1.0,
    ) {
        parent::__construct();
        // §9.1 命中三方消费：文本编辑的通用 I 形光标
        $this->cursor(CursorShape::IBeam);
    }

    /** @internal 由 `TextAreaState::build()` 调 */
    public static function make(
        string $text,
        string $placeholder,
        ?int $caretOffset,
        int $selectionStart,
        int $selectionEnd,
        int $minLines,
        int $lines,
        float $scrollOffset,
        float $barOpacity = 1.0,
    ): self {
        return new self(
            $text,
            $placeholder,
            $caretOffset,
            $selectionStart,
            $selectionEnd,
            $minLines,
            $lines,
            $scrollOffset,
            $barOpacity,
        );
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

    public function minLines(): int
    {
        return $this->minLines;
    }

    public function scrollOffset(): float
    {
        return $this->scrollOffset;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // §15.7 纪律 3：token → 值，只在这里合并一次。
        // ★ 多行：`maxLines(0)` = 不限行（排版层按宽度自由折行 + 按 `\n` 硬断）。
        $style = TextStyle::of($context->textSize(TextRole::Body))->withMaxLines(0);

        $gap = $context->metric(MetricToken::Gap);
        $radius = $context->metric(MetricToken::Radius);
        $border = $context->metric(MetricToken::BorderWidth);

        $box = new BoxStyle(
            $context->color(ColorToken::Pane),
            $context->color(ColorToken::Border),
            $border,
            $radius,
        );

        $insets = EdgeInsets::symmetric($context->metric(MetricToken::Padding), $gap * 0.5);
        $lineHeight = $style->size * $style->lineHeight;
        // 固定框高含内边距（`RenderTextArea` 的 size 是整盒）
        $fixedHeight = $this->lines > 0
            ? $this->lines * $lineHeight + $insets->vertical()
            : 0.0;

        return new RenderTextArea(
            $this->text,
            $style,
            $context->textColor(TextRole::Body),
            $this->placeholder,
            $context->textColor(TextRole::Caption),
            $box,
            $insets,
            $this->caretOffset,
            $context->color(ColorToken::Text),
            $border,
            // 选区高亮：与单行同一份混色（不透明混色，不用 alpha —— 软/硬两个
            // 后端对半透明的处理不一致，§14.1 的后端等价性不能挂在运气上）
            Color::lerp(
                $context->color(ColorToken::Accent),
                $context->color(ColorToken::Pane),
                0.72,
            ),
            $this->selectionStart,
            $this->selectionEnd,
            $context->metrics(),
            $this->minLines * $lineHeight,
            $fixedHeight,
            $this->scrollOffset,
            // 滚动条色走主题（`Muted`，与 `ScrollView` 同一个 token：两主题都清晰、
            // 语义"次要信息"）—— 条是纯视觉层，颜色不该由控件自己拍。
            $context->color(ColorToken::Muted),
            $this->barOpacity,
        );
    }
}
