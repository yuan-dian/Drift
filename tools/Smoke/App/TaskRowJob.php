<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Job;

/**
 * @internal 冒烟夹具：返回**类实例**的 Job —— 验端口搬得动对象，不只是标量。
 */
final class TaskRowJob implements Job
{
    public function __construct(private string $name)
    {
    }

    public function run(): mixed
    {
        return new TaskRow($this->name, strlen($this->name));
    }
}
