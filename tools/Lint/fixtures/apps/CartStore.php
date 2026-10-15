<?php
declare(strict_types=1);

namespace App;

/**
 * 夹具：`stale-derived` 的已知错误（§16.6.3 #7）。
 *
 * - `CartStore::add()`：改了依赖字段 `$lines`，却没重置缓存槽 `$totalCache`
 *   ⇒ 缓存撒谎（error）
 * - `CartStore::clear()`：同样改字段，但把缓存槽重置了 ⇒ **不该响**
 * - `CartStore::total()`：只写缓存槽 ⇒ 不适用本规则
 */
final class CartStore
{
    private array $lines = [];

    private ?float $totalCache = null;

    public function total(): float
    {
        return $this->totalCache ??= $this->compute();
    }

    public function add(OrderLine $line): void
    {
        $this->lines = [...$this->lines, $line];
    }

    public function clear(): void
    {
        $this->lines = [];
        $this->totalCache = null;
    }

    private function compute(): float
    {
        return 0.0;
    }
}
