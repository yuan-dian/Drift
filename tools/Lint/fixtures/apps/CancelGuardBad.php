<?php
declare(strict_types=1);

namespace App;

use Drift\Async\AsyncScope;
use Drift\Async\Future;

/** 夹具：`cancel-guard` 的已知错误 —— await / sleep 两种可取消上下文各一条。 */
final class CancelGuardBad
{
    public function awaiting(AsyncScope $co): void
    {
        try {
            $co->await(Future::resolved(null));
        } catch (\Throwable $e) {
            // 取消被当成业务失败接住了
        }
    }

    public function sleeping(AsyncScope $co): void
    {
        try {
            $co->sleep(10);
        } catch (\Throwable $e) {
            // 同上：循环会多跑一轮
        }
    }
}
