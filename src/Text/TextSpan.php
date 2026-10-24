<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 一段带样式的文本（富文本的排版输入，W8-5，2026-10-24）。
 *
 * ★ 与 `Text` 的关系：`Text` 是"整段一个样式"的便捷控件；`RichText` 由 span
 *   列表组成，排版层只认本类，不认 Widget。
 * ★ `argb = -1` = 用调用方给的默认色；颜色**不参与** `TextStyle` 的排版缓存键
 *   （见 `GlyphRun::$argb` 的注释）。
 *
 * @api
 */
final readonly class TextSpan
{
    public function __construct(
        public string $text,
        public TextStyle $style,
        public int $argb = -1,
    ) {
    }

    public function withColor(int $argb): self
    {
        return new self($this->text, $this->style, $argb);
    }
}
