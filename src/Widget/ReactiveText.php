<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Core\Readable;
use Drift\Errors;
use Drift\Text\TextRole;

/**
 * 内容来自 `Readable` 的文本：**读点就在自己这一层**，不需要包一层 builder 闭包。
 *
 * ```php
 * Text::of($this->count, '计数 ')->body()      // 取代 Bind::make(fn() => Text::make(...))
 * ```
 *
 * ★ 它是 `Bind` 的专用形态，不是第二个机制：依赖采集与订阅仍在 `State` /
 *   `StatefulElement` 里，本类只负责"在 build 里读一次、拼好字符串、套上样式"。
 * ★ 每次重建只重跑这一个 State（≈3 个元素），页面与兄弟节点不动。
 * ★ 样式与 `Text` 一一对应，避免为了"无闭包"牺牲表现力。
 *
 * @api
 */
final class ReactiveText extends StatefulWidget
{
    private Readable $source;
    private string $prefix;
    private string $suffix;
    private int $role;
    private float $size = -1.0;
    private int $bold = -1;
    private int $maxLines = 0;
    private int $ellipsize = 0;

    public function __construct(Readable $source, string $prefix = '', string $suffix = '')
    {
        parent::__construct();
        $this->source = $source;
        $this->prefix = $prefix;
        $this->suffix = $suffix;
        $this->role = TextRole::Unset->value;
    }

    /** @api */
    public static function of(Readable $source, string $prefix = '', string $suffix = ''): self
    {
        return new self($source, $prefix, $suffix);
    }

    public function title(): static { $this->mutate(); $this->role = TextRole::Title->value; return $this; }
    public function heading(): static { $this->mutate(); $this->role = TextRole::Heading->value; return $this; }
    public function body(): static { $this->mutate(); $this->role = TextRole::Body->value; return $this; }
    public function caption(): static { $this->mutate(); $this->role = TextRole::Caption->value; return $this; }
    public function error(): static { $this->mutate(); $this->role = TextRole::Error->value; return $this; }

    public function size(float $pt): static { $this->mutate(); $this->size = $pt; return $this; }
    public function bold(bool $v = true): static { $this->mutate(); $this->bold = $v ? 1 : 0; return $this; }
    public function maxLines(int $n): static { $this->mutate(); $this->maxLines = $n; return $this; }
    public function ellipsize(bool $v = true): static { $this->mutate(); $this->ellipsize = $v ? 1 : 0; return $this; }

    public function source(): Readable { return $this->source; }
    public function prefix(): string { return $this->prefix; }
    public function suffix(): string { return $this->suffix; }
    public function roleInt(): int { return $this->role; }
    public function explicitSize(): ?float { return $this->size < 0.0 ? null : $this->size; }
    public function explicitBold(): ?bool { return match ($this->bold) { -1 => null, 0 => false, default => true }; }
    public function maxLinesValue(): int { return $this->maxLines; }
    public function ellipsizeValue(): bool { return $this->ellipsize === 1; }

    public function createState(): State
    {
        return new ReactiveTextState();
    }
}

/**
 * `ReactiveText` 的 State：唯一职责是在 build 里 `get()` 一次。
 *
 * @internal
 */
final class ReactiveTextState extends State
{
    public function build(BuildContext $ctx): Widget
    {
        $widget = $this->widget();
        if (!$widget instanceof ReactiveText) {
            Errors::report('ReactiveTextState 只能配 ReactiveText（框架内部不变量被破坏）');

            return SizedBox::make();
        }

        $text = Text::make($widget->prefix() . (string) $widget->source()->get() . $widget->suffix());
        $role = $widget->roleInt();
        if ($role === TextRole::Title->value) {
            $text->title();
        } elseif ($role === TextRole::Heading->value) {
            $text->heading();
        } elseif ($role === TextRole::Body->value) {
            $text->body();
        } elseif ($role === TextRole::Caption->value) {
            $text->caption();
        } elseif ($role === TextRole::Error->value) {
            $text->error();
        }

        $size = $widget->explicitSize();
        if ($size !== null) {
            $text->size($size);
        }
        $bold = $widget->explicitBold();
        if ($bold !== null) {
            $text->bold($bold);
        }

        return $text->maxLines($widget->maxLinesValue())->ellipsize($widget->ellipsizeValue());
    }
}
