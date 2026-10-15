<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextField;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Text\TextRole;
use Drift\Text\TextStyle;

/**
 * `TextField` 的盒（§13.2 的 Widget → RenderBox 构造契约）。
 *
 * ★ **为什么存在**：`TextField` 必须有纯 UI 态（编辑缓冲 + 插入符），所以它只能是
 *   `StatefulWidget`；而 `StatefulWidget` 自己不产盒（`Element::syncBox()` 只认
 *   `RenderBoxWidget`）。于是"有状态的字段"与"有盒的控件"必须是两个 Widget ——
 *   这正是 Flutter `TextField → EditableText → RenderEditable` 的三段形态。
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
    ) {
        parent::__construct();
    }

    /** @internal 由 `TextFieldState::build()` 调 */
    public static function make(string $text, string $placeholder, ?int $caretOffset): self
    {
        return new self($text, $placeholder, $caretOffset);
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

    public function createRenderObject(BuildContext $context): RenderBox
    {
        // §15.7 纪律 3：token → 值，只在这里合并一次。
        // ★ 单行：`maxLines(1)` 由这里保证（`RenderTextField` 的插入符几何依赖它）。
        $style = TextStyle::of($context->textSize(TextRole::Body))->withMaxLines(1);

        $gap = $context->metric(MetricToken::Gap);
        $radius = $context->metric(MetricToken::Radius);
        $border = $context->metric(MetricToken::BorderWidth);

        // 输入"井"：底色用画布色、描边用边框色（与 Button::Default 的 Chrome 底区分开
        // —— 输入区应当是"凹"的观感，按钮是"凸"的）
        $box = new BoxStyle(
            $context->color(ColorToken::Pane),
            $context->color(ColorToken::Border),
            $border,
            $radius,
        );

        return new RenderTextField(
            $this->text,
            $style,
            $context->textColor(TextRole::Body),
            $this->placeholder,
            $context->textColor(TextRole::Caption),   // 占位符 = 弱化色
            $box,
            EdgeInsets::symmetric($context->metric(MetricToken::Padding), $gap * 0.5),
            $this->caretOffset,
            $context->color(ColorToken::Text),
            $border,
            $context->metrics(),
        );
    }
}
