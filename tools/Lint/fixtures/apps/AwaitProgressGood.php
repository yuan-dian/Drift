<?php
declare(strict_types=1);

namespace App;

use Drift\Async\AsyncScope;
use Drift\Async\Future;

/**
 * 夹具：`await-progress` 的不该响形态。
 *
 * ★ 三个「看着像但不是」：第二参已订（内联 / 变量）、以及不报进度的 Job 单参合法。
 */
final class AwaitProgressGood
{
    public function inline(AsyncScope $co): void
    {
        $co->await($this->exec(new ProgressReportingJob()), function (float $fraction): void {
        });
    }

    public function viaVariable(AsyncScope $co): void
    {
        $future = $this->exec(new ProgressReportingJob());
        $co->await($future, function (float $fraction): void {
        });
    }

    public function silentJob(AsyncScope $co): void
    {
        $co->await($this->exec(new SilentJob()));
    }

    private function exec(mixed $job): Future
    {
        return Future::pending();
    }
}
