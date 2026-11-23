<?php
declare(strict_types=1);

namespace App;

use Drift\Widget\Each;

/**
 * `nested-subscribe`：BadList 该响（builder 里调 watch），GoodList / KeyOnly 不该响。
 * 本文件提及 Each；不提及 Each 的 Lazy 形态见 LazySubscribeFixture.php。
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

