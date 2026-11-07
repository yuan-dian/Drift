<?php
declare(strict_types=1);

namespace App;

use Drift\Async\AsyncScope;
use Drift\Async\Future;

/** 夹具：`await-progress` 的已知错误 —— 内联与变量两种形状各一条。 */
final class AwaitProgressBad
{
    public function inline(AsyncScope $co): void
    {
        $co->await($this->exec(new ProgressReportingJob()));
    }

    public function viaVariable(AsyncScope $co): void
    {
        $future = $this->exec(new ProgressReportingJob());
        $co->await($future);
    }

    private function exec(mixed $job): Future
    {
        return Future::pending();
    }
}
