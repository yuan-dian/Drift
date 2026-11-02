<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\JobProgress;
use Drift\App\NamedJob;

/**
 * @internal 冒烟夹具：**会报进度**的任务（`smoke.progress`）。
 *
 * 存在的理由（2026-11-02，第 2 棒）：要证明"进度真的从 worker 进程回到了 UI 进程"，
 * 就得有一条**在 worker 里分段干活**的任务 —— 否则进度只可能在 UI 线程上产生，
 * 那测的就不是 IPC。
 *
 * ★ `steps` 是**步数**不是毫秒：判据要看"收到几步"，而时间是机器相关的。
 * ★ 每步都 `report()`，`JobProgress` 自己会按 1% 节流 ⇒ 步数少的时候
 *   （比如 4 步：0.25 / 0.5 / 0.75 / 1.0）**一步都不会被吞**，判据能精确对数。
 */
final class NamedProgressJob implements NamedJob
{
    public function __construct(
        private readonly int $steps = 4,
        private readonly int $stepMs = 30,
    ) {
    }

    public function name(): string
    {
        return 'smoke.progress';
    }

    public function args(): array
    {
        return ['steps' => $this->steps, 'stepMs' => $this->stepMs];
    }

    public function run(): mixed
    {
        $steps = $this->steps < 1 ? 1 : $this->steps;

        for ($i = 1; $i <= $steps; $i++) {
            if ($this->stepMs > 0) {
                usleep($this->stepMs * 1000);
            }
            JobProgress::report($i / $steps);
        }

        return $steps;
    }
}
