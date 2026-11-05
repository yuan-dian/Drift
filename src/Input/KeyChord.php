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

    /**
     * 键名 + 修饰键。
     *
     * ★ 修饰键有默认值 ⇒ 既有的 `make('A')` 调用**一字不改**（纯扩展）。
     *   三个单修饰键的工厂（`ctrl()` / `alt()` / `shift()`）只是它的简写。
     *
     * ★ **为什么必须有带修饰键的通用入口**：平台把一条 `WM_KEYDOWN` 归一化时，
     *   修饰键是 `GetKeyState` 读出来的**三个独立布尔**，`Ctrl+Shift+A` 这种组合
     *   在三个单修饰工厂里**表达不出来** ⇒ 没有它，平台层只能丢掉组合信息，
     *   而丢掉的后果是"加速键表里 `Ctrl+Shift+A` 永远不命中"且**不报错**。
     *
     * ★★ **单字母键名归一成大写**（2026-11-02）。壳的 `keyNameFromVk()` 对
     *   `VK_A`–`VK_Z` 给的就是**大写** `"A"`，而大小写信息在 `$shift` 里、
     *   不在键名里。不归一的话 `Ctrl+A` 有 `'a'` / `'A'` 两种写法：
     *   `TableState` 写过小写、`TextKeymap` 写过大写 —— 前者的 Ctrl+A/C 在
     *   真机上**从来没生效过**，而它的单元测试拿小写 chord 直接喂 ⇒ 实现与测试
     *   同源，一起绿。这类错误没有独立判据能发现，只能让"两种写法"不存在。
     */
    public static function make(string $key, bool $ctrl = false, bool $alt = false, bool $shift = false): self
    {
        return new self(\strlen($key) === 1 ? \strtoupper($key) : $key, $ctrl, $alt, $shift);
    }

    public static function ctrl(string $key): self
    {
        return self::make($key, ctrl: true);
    }

    public static function alt(string $key): self
    {
        return self::make($key, alt: true);
    }

    public static function shift(string $key): self
    {
        return self::make($key, shift: true);
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

    public static function up(): self
    {
        return self::make('Up');
    }

    public static function down(): self
    {
        return self::make('Down');
    }

    public static function home(): self
    {
        return self::make('Home');
    }

    public static function end(): self
    {
        return self::make('End');
    }

    /** 键名与壳层 `keyNameFromVk()` 的 `VK_PRIOR` / `VK_NEXT` 逐字一致。 */
    public static function pageUp(): self
    {
        return self::make('PageUp');
    }

    public static function pageDown(): self
    {
        return self::make('PageDown');
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
