<?php
declare(strict_types=1);

namespace App\Users\Jobs;

use Drift\App\Job;

/**
 * 任务注册表（§17.4.5）—— **UI 侧与 worker 侧共用同一份**。
 *
 * ★ `Bootstrap::jobs()` 一处声明，worker 路径（`AppEntry` 调 `serve()`）与 UI 路径
 *   （`useBackgroundJobs()`）都从它取，所以两侧的名字不可能对不上。
 * ★ 加一个后台任务 = 一个 `NamedJob` 类 + **本文件一行**。参数怎么从 `args()`
 *   还原由 `JobArgs` trait 按 `FIELDS` 常量回答（顺序 == 构造参数顺序），
 *   映射不用手写，不会分叉。漏登记由 `NamedJobContractTest` 判红。
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
