<?php
declare(strict_types=1);

namespace App\Users\Jobs;

use Drift\App\Job;

/**
 * 任务注册表（§17.4.5）—— **UI 侧与 worker 侧共用同一份**。
 *
 * ★ 它是"组合根的配置"：`Bootstrap` 里两条路径（正常窗口 / `--drift-worker`）
 *   都调 `make()`，所以两侧的名字不可能对不上。
 * ★ 加一个后台任务 = 一个 `NamedJob` 类 + **本文件一行**。参数怎么从 `args()`
 *   还原由任务自己的 `fromArgs()` 回答 —— 映射与 `args()` 上下相邻，不会分叉。
 *   漏登记由 `NamedJobContractTest` 判红。
 */
final class JobRegistry
{
    /**
     * @return array<string, callable(array<string, mixed>): Job>
     */
    public static function make(): array
    {
        return [
            FetchUsersJob::NAME => static fn (array $args): Job => FetchUsersJob::fromArgs($args),
        ];
    }
}
