<?php
declare(strict_types=1);

namespace Drift\Theme;

/**
 * 应用侧往主题挂自定义 token 的缝（对标 Flutter `ThemeExtension`）。
 *
 * ★ 不是字符串注册表：`key()` 由实现写 `return self::class;`，是编译期常量。
 *   取扩展时也用 `Foo::class`，不是手拼的字符串 ⇒ 拼错不会静默拿到 null。
 *
 * @api
 */
interface ThemeExtension
{
    public function key(): string;
}
