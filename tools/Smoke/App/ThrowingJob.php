<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Job;

/**
 * @internal 冒烟夹具：**`run()` 必抛**的 Job —— 验错误通道（§17.4.2）。
 *
 * ★ 单独的类是为了让"抛异常"这件事在 AOT 的可达性分析里是一条**结构事实**
 *   （闭包里 throw 在翻译层的处理与类方法不同；这里只要类）。
 */
final class ThrowingJob implements Job
{
    public function __construct(private readonly string $message = 'boom')
    {
    }

    public function run(): mixed
    {
        throw new \RuntimeException($this->message);
    }
}
