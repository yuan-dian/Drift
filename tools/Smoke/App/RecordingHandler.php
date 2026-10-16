<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;

/**
 * @internal 冒烟夹具：把"收到过什么命令"交给一个回调（可多处复用同一实例）。
 *
 * ★ 具名类是**有意的**（§11.4.3 / O-15 方案 E：handler 必须可枚举 / 可单测）。
 */
final class RecordingHandler implements CommandHandler
{
    /** @var callable(string, CommandArg): void */
    private $onHandle;

    /** @param callable(string, CommandArg): void $onHandle */
    public function __construct(callable $onHandle)
    {
        $this->onHandle = $onHandle;
    }

    public function handle(string $name, CommandArg $arg): bool
    {
        ($this->onHandle)($name, $arg);

        return true;
    }
}
