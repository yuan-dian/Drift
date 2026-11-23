<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 一次按键（§9.1 路由链：`Platform → KeyEvent → ①加速键 → ②IME → ③焦点链`）。
 *
 * `$chord` 回答"哪个键"，`$text` 回答"它产生了什么字符" —— 两者**不是**冗余：
 *   · 加速键只看 `$chord`（`Ctrl+R` 不产生文本）
 *   · 文本输入只看 `$text`（输入法上屏的可能是**多**字符）
 *   · 编辑键（`Backspace` / 方向键）两样都没有，靠 `$chord` 识别
 *
 * ```php
 * KeyEvent::character('井')      // 文本输入（chord = '井'，text = '井'）
 * KeyEvent::down(KeyChord::backspace())   // 编辑键
 * KeyEvent::down(KeyChord::ctrl('A'))     // 加速键 / 编辑命令
 * ```
 *
 * ★ 今天只有**按下**（`down()` / `character()`）。`Up` / 长按重复留给 M4 的完整
 *   输入层 —— `TextField` 的编辑命令只需要"按下"这一种，**不预先发明没消费者的相位**。
 *
 * @api
 */
final readonly class KeyEvent implements InputEvent
{
    private function __construct(
        public KeyChord $chord,
        public string $text,
    ) {
    }

    /** 文本输入：一个可打印字符（`'a'` / `'井'` / `' '`），`text` 原样保留大小写 */
    public static function character(string $text): self
    {
        $first = \mb_substr($text, 0, 1, 'UTF-8');

        return new self(KeyChord::make(\mb_strtoupper($first, 'UTF-8')), $text);
    }

    /** 非文本键（编辑键 / 加速键 / Tab） */
    public static function down(KeyChord $chord): self
    {
        return new self($chord, '');
    }

    /** 这次按键**是否携带可插入的文本**（加速键与编辑键都是 false） */
    public function isText(): bool
    {
        return $this->text !== '';
    }

    /**
     * 这次按键是不是**键盘导航**（移焦点 / 翻视口 / 行首行尾）。
     *
     * 只有导航键切换 `:focus-visible` 输入模态；编辑键（文本、Enter、
     * Backspace、Delete、Ctrl+A/C/V）不改模态，否则鼠标点进输入框后按一个键
     * 就会凭空冒出焦点环。Shift 只扩选、不改归属，所以 Tab/方向键带 Shift 仍算。
     */
    public function isFocusNavigation(): bool
    {
        if ($this->text !== '' || $this->chord->ctrl || $this->chord->alt) {
            return false;
        }

        $key = $this->chord->key;

        return $key === 'Tab'
            || $key === 'Left' || $key === 'Right' || $key === 'Up' || $key === 'Down'
            || $key === 'PageUp' || $key === 'PageDown'
            || $key === 'Home' || $key === 'End';
    }
}
