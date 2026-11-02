<?php
declare(strict_types=1);

namespace App\Watermark\Jobs;

use App\Watermark\Api\ApiClient;
use App\Watermark\Api\Config;
use Drift\App\NamedJob;

/**
 * 建任务（后台任务）。
 *
 * ★ 返回 `['taskId' => string]` 而不是裸字符串：跨进程的返回值统一用数组，
 *   以后要加字段（比如服务端回的任务快照）不用改签名。
 */
final class AddTaskJob implements NamedJob
{
    public const NAME = 'watermark.addTask';

    /**
     * @param list<string> $objectKeys 已上传的对象键
     */
    public function __construct(
        private readonly array $objectKeys,
        private readonly string $time,
        private readonly string $address,
        private readonly string $imageSize,
        private readonly string $base = Config::API_BASE,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function args(): array
    {
        return [
            'keys' => \array_values($this->objectKeys),
            'time' => $this->time,
            'address' => $this->address,
            'size' => $this->imageSize,
            'base' => $this->base,
        ];
    }

    public function run(): mixed
    {
        $taskId = (new ApiClient($this->base))
            ->addTask($this->objectKeys, $this->time, $this->address, $this->imageSize);

        return ['taskId' => $taskId];
    }
}
