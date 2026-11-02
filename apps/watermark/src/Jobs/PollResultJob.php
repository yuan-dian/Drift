<?php
declare(strict_types=1);

namespace App\Watermark\Jobs;

use App\Watermark\Api\ApiClient;
use App\Watermark\Api\Config;
use Drift\App\NamedJob;

/**
 * 查一次任务结果（后台任务）。
 *
 * ★ **"查一次"是一个任务**，不是一个长期任务：轮询的节奏由 UI 侧（`Store` +
 *   `Application::after()`）控制。理由：
 *   - 一次 HTTP 就是一个任务，失败/超时/取消都走同一条路（不用给"循环任务"
 *     发明一套额外的取消语义）
 *   - 用户点"停止轮询"时，只需**不再排下一次**，不需要中断一个在途的循环
 * ★ 返回值是 `TaskResult::toArray()` —— UI 侧再 `fromArray()` 还原成值对象。
 */
final class PollResultJob implements NamedJob
{
    public const NAME = 'watermark.poll';

    public function __construct(
        private readonly string $taskId,
        private readonly string $base = Config::API_BASE,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function args(): array
    {
        return ['taskId' => $this->taskId, 'base' => $this->base];
    }

    public function run(): mixed
    {
        return (new ApiClient($this->base))->getResult($this->taskId)->toArray();
    }
}
