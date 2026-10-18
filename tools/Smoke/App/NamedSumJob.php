<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\NamedJob;

/**
 * @internal 冒烟夹具：能跨进程描述的求和任务（`smoke.sum`）。
 *
 * ★ 它自己不算，而是**委托给 `SumJob`** —— 这样 worker 进程里跑的确实是
 *   "另一个类 + 一次方法调用"（不是把算式抄一遍），"跨进程跑 AOT 代码"才有意义。
 */
final class NamedSumJob implements NamedJob
{
    public function __construct(private readonly int $to)
    {
    }

    public function name(): string
    {
        return 'smoke.sum';
    }

    public function args(): array
    {
        return ['to' => $this->to];
    }

    public function run(): mixed
    {
        return (new SumJob($this->to))->run();
    }
}
