<?php
declare(strict_types=1);

namespace App\Showcase\Jobs;

use Drift\App\Job;

/**
 * 任务注册表 —— **UI 侧与 worker 侧共用同一份**（`ProcessTaskHost` 的约定）。
 *
 * ★ 两边（正常窗口 / `--drift-worker`）都调 `make()` ⇒ 名字不可能对不上。
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
            FetchRowsJob::NAME => static fn (array $args): Job => FetchRowsJob::fromArgs($args),
        ];
    }
}
