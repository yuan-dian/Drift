<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\NamedJob;
use Drift\App\TimedJob;

/**
 * @internal 冒烟夹具：会声明自己墙钟上限的睡任务（`smoke.timed_sleep`）。
 *
 * 与 `NamedSleepJob` 的唯一差别是 `implements TimedJob` —— 用来证明
 * `ProcessTaskHost` 真的按**每条任务**上限判定，而不是只认 Host 默认值。
 */
final class NamedTimedSleepJob implements NamedJob, TimedJob
{
    public function __construct(
        private readonly int $ms,
        private readonly int $declaredMs = 0,
    ) {
    }

    public function name(): string
    {
        return 'smoke.timed_sleep';
    }

    public function args(): array
    {
        return ['ms' => $this->ms, 'declaredMs' => $this->declaredMs];
    }

    public function timeoutMs(): int
    {
        return $this->declaredMs;
    }

    public function run(): mixed
    {
        \usleep($this->ms * 1000);

        return $this->ms;
    }
}
