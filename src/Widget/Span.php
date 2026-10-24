<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Rendering\Color;
use Drift\Text\FontWeight;
use Drift\Text\TextSpan;
use Drift\Text\TextStyle;

/**
 * 富文本里的一段（`RichText` 的子项，W8-5，2026-10-24）。
 *
 * ```php
 * RichText::make([
 *     Span::make('保存失败：')->bold(),
 *     Span::make('磁盘空间不足')->color(Color::fromHex('#d93025')),
 * ])->size(14.0);
 * ```
 *
 * ★ 只表达"这一段与段落基线不同"的**增量**（颜色 / 粗细 / 字号）；段落级的
 *   对齐 / 行数上限 / 省略号归 `RichText`，不在这里（与 `Text` 的分工一致）。
 * ★ 颜色存 `int`（ARGB，`-1` = 未设）而不是 `?Color`：可空对象在 AOT 下退化。
 *
 * @api
 */
final class Span
{
    private string $content;
    private int $argb = -1;
    private int $bold = -1;      // -1 未设 / 0 否 / 1 是
    private float $size = -1.0;  // 负值 = 未设
    private string $tag = '';    // 空 = 不是链接
    private bool $underline = false;

    private function __construct(string $content)
    {
        $this->content = $content;
    }

    public static function make(string $content): self
    {
        return new self($content);
    }

    public function color(Color $color): static
    {
        $this->argb = $color->toArgb();

        return $this;
    }

    public function bold(bool $value = true): static
    {
        $this->bold = $value ? 1 : 0;

        return $this;
    }

    public function size(float $pt): static
    {
        $this->size = $pt;

        return $this;
    }

    /**
     * 让这一段可以被点击（`RichText::onSpanTap()` 收到这个标识）。
     *
     * ★ 只标"身份"，不绑定回调：回调只有一处（控件级），否则同一段文本的
     *   每个 span 都持一个闭包，`GlyphRun` 会被迫携带不可序列化的东西。
     */
    public function tag(string $tag): static
    {
        $this->tag = $tag;

        return $this;
    }

    public function underline(bool $value = true): static
    {
        $this->underline = $value;

        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    /**
     * 段落基线样式 + 本段的增量 ⇒ 排版层的值对象。
     *
     * ★ 只改**影响排版的那几项**（字号 / 字重）；颜色是独立字段（不进缓存键）。
     */
    public function toTextSpan(TextStyle $base): TextSpan
    {
        $style = $base;

        if ($this->size >= 0.0) {
            $style = $style->withSize($this->size);
        }

        if ($this->bold === 1) {
            $style = $style->withWeight(FontWeight::Bold);
        } elseif ($this->bold === 0) {
            $style = $style->withWeight(FontWeight::Regular);
        }

        return new TextSpan($this->content, $style, $this->argb, $this->tag, $this->underline);
    }
}
