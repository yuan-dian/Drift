<?php
declare(strict_types=1);

namespace App\Watermark\Jobs;

use App\Watermark\Api\Config;
use Drift\App\Job;

/**
 * 任务注册表 —— **UI 侧与 worker 侧共用同一份**。
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
            FetchSignJob::NAME => static function (array $args): Job {
                return new FetchSignJob((string) ($args['base'] ?? Config::API_BASE));
            },
            UploadBatchJob::NAME => static function (array $args): Job {
                $sign = $args['sign'] ?? [];
                $items = $args['items'] ?? [];

                return new UploadBatchJob(
                    \is_array($sign) ? $sign : [],
                    \is_array($items) ? \array_values($items) : [],
                    (string) ($args['base'] ?? Config::API_BASE),
                );
            },
            AddTaskJob::NAME => static function (array $args): Job {
                $keys = $args['keys'] ?? [];

                return new AddTaskJob(
                    \is_array($keys) ? \array_values($keys) : [],
                    (string) ($args['time'] ?? ''),
                    (string) ($args['address'] ?? ''),
                    (string) ($args['size'] ?? '2K'),
                    (string) ($args['base'] ?? Config::API_BASE),
                );
            },
            PollResultJob::NAME => static function (array $args): Job {
                return new PollResultJob(
                    (string) ($args['taskId'] ?? ''),
                    (string) ($args['base'] ?? Config::API_BASE),
                );
            },
            DownloadResultsJob::NAME => static function (array $args): Job {
                $urls = $args['urls'] ?? [];

                return new DownloadResultsJob(
                    \is_array($urls) ? \array_values($urls) : [],
                    (string) ($args['dir'] ?? ''),
                );
            },
        ];
    }
}
