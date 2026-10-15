<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 一个**按键组合**：键名 + 修饰键（§9.1 路由链的输入原子）。
 *
 * 加速键（§9.1 第 ① 环）、编辑命令（`Ctrl+A/C/V/X/Z`）、`claimKeys()` 的独占键集
 * 都建在它上面 —— 它是"哪个键被按下"的**可比较表示**，不是平台扫描码。
 *
 * ```php
 * KeyChord::ctrl('R')    // 刷新
 * KeyChord::f(5)         // F5
 * KeyChord::tab()        // 焦点前进
 * ```
 *
 * ★ **键名是可读字符串**（`'A'` / `'F5'` / `'Tab'`），**不是平台虚拟键码**：
 *   键码是 `Platform` 层的私事，`Input` 层只收**归一化后**的组合
 *   （§4.3：`Input` 不许依赖 `Platform`）。
 * ★ 值对象 ⇒ `is()` 做全字段比较；`label()` 只给日志/调试用（**不是**给 UI 显示的
 *   本地化文案）。
 *
 * @api
 */
final readonly class KeyChord
{
    private function __construct(
        public string $key,
        public bool $ctrl,
        public bool $alt,
        public bool $shift,
    ) {
    }

    public static function make(string $key): self
    {
        return new self($key, false, false, false);
    }

    public static function ctrl(string $key): self
    {
        return new self($key, true, false, false);
    }

    public static function alt(string $key): self
    {
        return new self($key, false, true, false);
    }

    public static function shift(string $key): self
    {
        return new self($key, false, false, true);
    }

    /** 功能键：`f(5)` ⇒ `F5` */
    public static function f(int $n): self
    {
        return new self('F' . (string) $n, false, false, false);
    }

    public static function tab(): self
    {
        return self::make('Tab');
    }

    public static function enter(): self
    {
        return self::make('Enter');
    }

    public static function escape(): self
    {
        return self::make('Escape');
    }

    public static function backspace(): self
    {
        return self::make('Backspace');
    }

    public static function delete(): self
    {
        return self::make('Delete');
    }

    public static function left(): self
    {
        return self::make('Left');
    }

    public static function right(): self
    {
        return self::make('Right');
    }

    public static function home(): self
    {
        return self::make('Home');
    }

    public static function end(): self
    {
        return self::make('End');
    }

    /** 全字段比较（值对象语义） */
    public function is(self $other): bool
    {
        return $this->key === $other->key
            && $this->ctrl === $other->ctrl
            && $this->alt === $other->alt
            && $this->shift === $other->shift;
    }

    /** 调试/日志用（`'Ctrl+Shift+A'`）—— 不是给 UI 显示的本地化文案 */
    public function label(): string
    {
        $parts = [];
        if ($this->ctrl) {
            $parts[] = 'Ctrl';
        }
        if ($this->alt) {
            $parts[] = 'Alt';
        }
        if ($this->shift) {
            $parts[] = 'Shift';
        }
        $parts[] = $this->key;

        return implode('+', $parts);
    }
}
