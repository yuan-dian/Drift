<?php
declare(strict_types=1);

namespace App;

use Drift\PublicThing;

/**
 * 夹具：同类名的五种正确形态 —— 一条都不该响。
 *   ① use 过之后的 `new` / `::`（可见）；
 *   ② 全限定名（写全了不会错）；
 *   ③ `catch` 里是内建类（`\RuntimeException`，全仓符号表里没有 ⇒ 规则诚实的边界）；
 *   ④ `self::` 与变量左侧 `$cls::m()`（不是类名）。
 *
 * ★ 第五条"匿名类"**故意不放在这里**：本仓 `nano-hygiene` 直接禁匿名类
 *   （§16.7），拿它当反例只会让两条规则互相打架。
 */
final class ClassMissingUseGood
{
    public static function probe(string $cls): string
    {
        $thing = new PublicThing();

        try {
            if ($cls !== '') {
                $cls::marker();
            }
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return PublicThing::marker() . self::tail() . \get_class($thing);
    }

    private static function tail(): string
    {
        return '';
    }
}
