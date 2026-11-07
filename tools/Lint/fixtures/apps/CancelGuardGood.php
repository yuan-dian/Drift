<?php
declare(strict_types=1);

namespace App;

use Drift\Async\AsyncCancelled;
use Drift\Async\AsyncScope;
use Drift\Async\Future;

/**
 * 夹具：`cancel-guard` 的不该响形态。
 *
 * ★ 两个「看着像但不是」：宽 catch 前已有取消守卫 / try 里没有让出点（吞掉也无害）。
 */
final class CancelGuardGood
{
    public function guarded(AsyncScope $co): void
    {
        try {
            $co->await(Future::resolved(null));
        } catch (AsyncCancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 网关抖一下继续重试
        }
    }

    public function noYield(): void
    {
        try {
            $value = 1 + 1;
        } catch (\Throwable $e) {
            // 没有 await/sleep ⇒ 不可能有取消要从这里冒泡
        }
    }
}
