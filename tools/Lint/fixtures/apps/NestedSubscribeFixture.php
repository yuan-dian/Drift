<?php
declare(strict_types=1);

namespace App;

use Drift\Widget\Each;

/**
 * 夹具：`nested-subscribe` 的已知错误 / 已知正确（§16.6.3 #9）。
 *
 * 该响（warn ×1）：
 * - `BadList::build()`：`Each::make(...)->items($rows, fn …)` 的 builder 里
 *   调 `->watch()` ⇒ 每行一个订阅者
 *
 * 不该响：
 * - `GoodList::build()`：同样用 Each + `->items()`，但 builder 只造 Widget
 * - `KeyOnly::build()`：只用 `Each::make()` 取 key，没有 items
 *
 * ★ `BadList` 从前 `implements SubscribingWidget`（"它真的调了 watch，就该被
 *   `subscriber-mark` 抓"）。两处都站不住：那条规则只认 `$this->watch(`，这里的
 *   接收者是 `$r`；而接口本身也已于 2026-11-24 随 `SubjectBuilder` 退役。
 * ★ "文件没提 `Each`"那道闸的干净用例在 `NoEachFixture.php`（不能同文件 ——
 *   本文件 import 了 `Each`，那道闸对本文件整体生效）。
 */
final class BadList
{
    /** @var list<mixed> */
    private array $rows = [];

    public function build(): Each
    {
        return Each::make(static fn (mixed $r): int => 0)
            ->items($this->rows, static function (mixed $r) {
                $r->watch(static function (): void {
                });

                return $r;
            });
    }
}

final class GoodList
{
    /** @var list<mixed> */
    private array $rows = [];

    public function build(): Each
    {
        return Each::make(static fn (mixed $r): int => 0)
            ->items($this->rows, static fn (mixed $r): mixed => $r);
    }
}

final class KeyOnly
{
    public function build(): Each
    {
        return Each::make(static fn (mixed $r): int => 0);
    }
}
