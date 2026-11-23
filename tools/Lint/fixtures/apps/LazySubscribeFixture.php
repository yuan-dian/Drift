<?php
declare(strict_types=1);

namespace App;

use Drift\Widget\LazyColumn;

/**
 * `nested-subscribe`：本文件不提及 Each，旧类名闸会整文件跳过。
 * LazyBadList 该响（10000 行列表更怕每行订阅），LazyGoodList 不该响。
 */
final class LazyBadList
{
    /** @var list<mixed> */
    private array $rows = [];

    public function build(): LazyColumn
    {
        return LazyColumn::make(40.0)
            ->items($this->rows, static function (mixed $r) {
                $r->watch(static function (): void {
                });

                return $r;
            });
    }
}

final class LazyGoodList
{
    /** @var list<mixed> */
    private array $rows = [];

    public function build(): LazyColumn
    {
        return LazyColumn::make(40.0)
            ->items($this->rows, static fn (mixed $r): mixed => $r);
    }
}
