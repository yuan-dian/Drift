<?php
declare(strict_types=1);

namespace App\Watermark\Jobs;

use App\Watermark\Api\Api;
use App\Watermark\Api\Config;
use Drift\App\JobArgs;
use Drift\App\NamedJob;

/**
 * 建任务（后台任务）。
 *
 * ★ 返回 `['taskId' => string]` 而不是裸字符串：跨进程的返回值统一用数组，
 *   以后要加字段（比如服务端回的任务快照）不用改签名。
 */
final class AddTaskJob implements NamedJob
{
    use JobArgs;

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
            'objectKeys' => \array_values($this->objectKeys),
            'time' => $this->time,
            'address' => $this->address,
            'imageSize' => $this->imageSize,
            'base' => $this->base,
        ];
    }

    /**
     * worker 侧重建（**读法与 `args()` 上下相邻**，漏字段当场看得见）。
     *
     * @param array<string, mixed> $args
     */
    public static function fromArgs(array $args): self
    {
        return new self(
            self::argList($args, 'objectKeys'),
            self::argString($args, 'time'),
            self::argString($args, 'address'),
            // 与 `WatermarkStore::$imageSize` 的初值同一个默认：UI 侧不选就是 2K
            self::argString($args, 'imageSize', '2K'),
            self::argString($args, 'base', Config::API_BASE),
        );
    }

    public function run(): mixed
    {
        $taskId = Api::client($this->base)
            ->addTask($this->objectKeys, $this->time, $this->address, $this->imageSize);

        return ['taskId' => $taskId];
    }
}
