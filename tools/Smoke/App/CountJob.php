<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\SlicedJob;

/**
 * @internal 冒烟夹具：分片累加 —— `total` 次求和，每次 `step()` 干 `chunk` 个。
 *
 * ★ 记 `stepMs` 是为了验"每帧耗时有界"（§17.4.3 的预算）：只断言"分了几帧"
 *   测不出"一帧里偷偷干了十倍活"。
 */
final class CountJob implements SlicedJob
{
    private int $cursor = 0;

    private int $sum = 0;

    /** @var list<float> 每次 step 的耗时（ms）。只被夹具读，不属于 SlicedJob 契约 */
    public array $stepMs = [];

    public function __construct(
        private readonly int $total,
        private readonly int $chunk = 10000,
    ) {
    }

    public function step(int $budgetMs): bool
    {
        $t0 = microtime(true);

        $end = min($this->total, $this->cursor + $this->chunk);
        for ($i = $this->cursor + 1; $i <= $end; $i++) {
            $this->sum += $i;
        }
        $this->cursor = $end;

        $this->stepMs[] = (microtime(true) - $t0) * 1000.0;

        return $this->cursor < $this->total;
    }

    public function result(): mixed
    {
        return $this->sum;
    }

    /** 已被推进了几帧（夹具读数） */
    public function steps(): int
    {
        return count($this->stepMs);
    }
}
