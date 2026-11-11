<?php
declare(strict_types=1);

namespace App;

/**
 * 夹具：`cjk-var-name` 的不该响 —— 四件"看着像但不是"。
 *
 * ① 单引号拼接；② 花括号插值（`{$n}` 有明确边界）；③ 单引号字面量（`$…` 不是变量）；
 * ④ 变量后有空格（`"$n 项"` 的变量名是 `$n`，中文在下一个 token）。
 */
final class CjkVarNameGood
{
    public function concat(int $n): string
    {
        return '共 ' . $n . ' 项';
    }

    public function braces(int $n): string
    {
        return "共 {$n}项";
    }

    public function literal(): string
    {
        return '$不是变量项';
    }

    public function spaced(int $n): string
    {
        return "$n 项";
    }
}
