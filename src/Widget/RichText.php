<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderRichText;
use Drift\Text\TextRole;
use Drift\Text\TextStyle;

/**
 * 富文本控件（W8-5，2026-10-24）—— 一段文本里可以有多个样式段。
 *
 * ```php
 * RichText::make([
 *     Span::make('保存失败：'),
 *     Span::make('磁盘空间不足')->color(Color::fromHex('#d93025'))->bold(),
 * ])->size(14.0)->maxLines(2);
 * ```
 *
 * ★ **与 `Text` 的分工**：`Text` 是单样式的便捷控件（绝大多数场景）；`RichText`
 *   只在"同一行里要换色/换粗细/换字号"时用。段落级参数（角色 / 字号 / 行数 /
 *   省略号）在这里，`Span` 只给增量。
 * ★ 排版是**一次成段**（不是多个 `Text` 拼起来）⇒ 断行可以跨 span、光标与选中
 *   用全文字节偏移（`Paragraph::indexAt()` 白拿）。
 *
 * @api
 */
final class RichText extends RenderBoxWidget
{
    /** @var list<Span> */
    private array $spans;
    private int $role;
    private float $size = -1.0;
    private int $maxLines = 0;
    private int $ellipsize = 0;

    /** @param list<Span> $spans */
    private function __construct(array $spans)
    {
        parent::__construct();
        $this->spans = $spans;
        $this->role = TextRole::Unset->value;
    }

    /** @param list<Span> $spans */
    public static function make(array $spans): self
    {
        return new self($spans);
    }

    public function title(): static   { $this->mutate(); $this->role = TextRole::Title->value;   return $this; }
    public function heading(): static { $this->mutate(); $this->role = TextRole::Heading->value; return $this; }
    public function body(): static    { $this->mutate(); $this->role = TextRole::Body->value;    return $this; }
    public function caption(): static { $this->mutate(); $this->role = TextRole::Caption->value; return $this; }
    public function error(): static   { $this->mutate(); $this->role = TextRole::Error->value;   return $this; }

    public function size(float $pt): static          { $this->mutate(); $this->size = $pt; return $this; }
    public function maxLines(int $n): static         { $this->mutate(); $this->maxLines = $n; return $this; }
    public function ellipsize(bool $v = true): static { $this->mutate(); $this->ellipsize = $v ? 1 : 0; return $this; }

    /** @return list<Span> */
    public function spans(): array
    {
        return $this->spans;
    }

    public function role(): TextRole
    {
        return TextRole::from($this->role);
    }

    public function maxLinesValue(): int
    {
        return $this->maxLines;
    }

    public function ellipsizeValue(): bool
    {
        return $this->ellipsize === 1;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        $theme = $context->theme();
        $role = $this->role();

        // 段落基线（§15.7 纪律 3）：主题 token 与显式字号只在这里合并一次
        $base = TextStyle::of($this->size < 0.0 ? $theme->textSize($role) : $this->size)
            ->withMaxLines($this->maxLinesValue())
            ->withEllipsize($this->ellipsizeValue());

        $spans = [];
        foreach ($this->spans as $span) {
            $spans[] = $span->toTextSpan($base);
        }

        return new RenderRichText(
            $spans,
            $base,
            $theme->textColor($role),
            $context->metrics(),
        );
    }
}
