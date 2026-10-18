<?php
declare(strict_types=1);

namespace App\Users\Jobs;

use Drift\App\Job;

/**
 * 任务注册表（§17.4.5）—— **UI 侧与 worker 侧共用同一份**。
 *
 * ★ 它是"组合根的配置"：`Bootstrap` 里两条路径（正常窗口 / `--drift-worker`）
 *   都调 `make()`，所以两侧的名字不可能对不上。
 * ★ 加一个后台任务 = 加一个 `NamedJob` + 在这里登记一行 —— 没有别的开关。
 */
final class JobRegistry
{
    /**
     * @return array<string, callable(array<string, mixed>): Job>
     */
    public static function make(): array
    {
        return [
            FetchUsersJob::NAME => static function (array $args): Job {
                return new FetchUsersJob(
                    (int) ($args['page'] ?? 1),
                    (string) ($args['base'] ?? FetchUsersJob::DEFAULT_BASE),
                );
            },
        ];
    }
}
