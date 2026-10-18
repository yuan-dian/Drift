<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\SlicedJob;

/** @internal 冒烟夹具：`step()` 必抛 —— 验分片的错误通道（走 onError 且出队）。 */
final class FailSliceJob implements SlicedJob
{
    public function step(int $budgetMs): bool
    {
        throw new \RuntimeException('分片中途失败');
    }

    public function result(): mixed
    {
        return null;
    }
}
