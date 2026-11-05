<?php
declare(strict_types=1);

namespace App;

use Drift\Async\AsyncScope;
use Drift\Async\Future;

/**
 * 夹具：`await-scope` 的**不该响**形态。
 *
 * ★ 三个「看着像但不是」：类型化形参的普通方法 / body 闭包自身带类型化形参 /
 *   同一函数里 sleep 与 await 都合法。它们钉死「不能要求 await 直接写在 run 闭包体内」。
 */
final class AwaitScopeGood
{
    public function typedParam(AsyncScope $co, Future $f): void
    {
        $co->await($f);
        if (!$co->sleep(10)) {
            return;
        }
    }

    public function body(Future $f): Future
    {
        return AsyncScope::task(function (AsyncScope $co) use ($f): void {
            $co->await($f);
        });
    }
}
