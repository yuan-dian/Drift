<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 一次输入后的文本与插入符（字节偏移）。格式化器的输入与输出都是它。
 *
 * @api
 */
final readonly class TextEdit
{
    public function __construct(
        public string $text,
        public int $caret,
    ) {
    }

    /** 整串按正则删除，并把插入符按「它前面被删掉多少」往回收 */
    public function without(string $pattern): self
    {
        $caret = max(0, min($this->caret, strlen($this->text)));
        $text = preg_replace($pattern, '', $this->text) ?? $this->text;
        $before = substr($this->text, 0, $caret);
        $kept = preg_replace($pattern, '', $before) ?? $before;

        return new self($text, max(0, $caret - (strlen($before) - strlen($kept))));
    }
}
