<?php
declare(strict_types=1);

namespace App\Showcase\Jobs;

use Drift\App\Job;

/**
 * 任务注册表 —— **UI 侧与 worker 侧共用同一份**（`ProcessTaskHost` 的约定）。
 *
 * ★ 两边（正常窗口 / `--drift-worker`）都调 `make()` ⇒ 名字不可能对不上。
 * ★ 加一个后台任务 = 加一个 `NamedJob` + 在这里登记一行。
 */
final class JobRegistry
{
    /**
     * @return array<string, callable(array<string, mixed>): Job>
     */
    public static function make(): array
    {
        return [
            FetchRowsJob::NAME => static function (array $args): Job {
                return new FetchRowsJob(
                    (int) ($args['page'] ?? 1),
                    (int) ($args['per'] ?? 20),
                    (string) ($args['mode'] ?? FetchRowsJob::MODE_LOCAL),
                    (string) ($args['base'] ?? FetchRowsJob::DEFAULT_BASE),
                );
            },
        ];
    }
}
