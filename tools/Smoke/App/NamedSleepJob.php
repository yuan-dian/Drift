<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\NamedJob;

/**
 * @internal 冒烟夹具：睡一会儿的任务（`smoke.sleep`）—— 用来占住 worker。
 *
 * 存在的理由（§17.4.5 的并发判据）：要证明"3 个 worker 同时各跑一条"，就得让每条任务
 * **停留一会儿**；否则任务可能在第二次 `submit()` 之前就跑完了，"并行"根本无从观察。
 */
final class NamedSleepJob implements NamedJob
{
    public function __construct(private readonly int $ms)
    {
    }

    public function name(): string
    {
        return 'smoke.sleep';
    }

    public function args(): array
    {
        return ['ms' => $this->ms];
    }

    public function run(): mixed
    {
        usleep($this->ms * 1000);

        return $this->ms;
    }
}
