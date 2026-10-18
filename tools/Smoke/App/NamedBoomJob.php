<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\NamedJob;

/** @internal 冒烟夹具：worker 里必抛的任务（`smoke.boom`）—— 验 `{ok:false}` 通道。 */
final class NamedBoomJob implements NamedJob
{
    public function __construct(private readonly string $message = 'boom')
    {
    }

    public function name(): string
    {
        return 'smoke.boom';
    }

    public function args(): array
    {
        return ['message' => $this->message];
    }

    public function run(): mixed
    {
        throw new \RuntimeException($this->message);
    }
}
