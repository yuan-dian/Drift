<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Job;

/**
 * @internal 冒烟夹具：**参数走构造器、`run()` 无参**（§O-03 方案 A 的形态）。
 */
final class SumJob implements Job
{
    public function __construct(private int $to)
    {
    }

    public function run(): mixed
    {
        $sum = 0;
        for ($i = 1; $i <= $this->to; $i = $i + 1) {
            $sum = $sum + $i;
        }

        return $sum;
    }
}
