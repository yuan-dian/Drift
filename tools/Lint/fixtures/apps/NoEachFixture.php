<?php
declare(strict_types=1);

namespace App;

use Drift\State\Subject;

/**
 * 夹具：`nested-subscribe` 的"文件闸"干净用例（§16.6.3 #9）。
 *
 * 本文件**一次都没提 `Each`**，且 `->items(...)` 的实参里就有 `->watch()`：
 * `nested-subscribe` **不该响** —— 它只治"列表 item builder 里订阅"，
 * 而判不出 receiver 是不是 `Each`（那要类型推断），所以拿"本文件出现过 `Each`"
 * 当闸。没有 `Each` 的列表根本不可能是 `Each::make(...)->items(...)`。
 *
 * ★ 这条夹具的价值全在"不响"：把文件闸删掉，它立刻变 warn。
 */
final class PlainList
{
    public function build(Subject $source): void
    {
        $this->items([$source], static function (Subject $s): void {
            $s->watch(static function (): void {
            });
        });
    }

    /** @param list<Subject> $rows */
    private function items(array $rows, callable $builder): void
    {
        foreach ($rows as $row) {
            $builder($row);
        }
    }
}
