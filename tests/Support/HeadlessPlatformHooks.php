<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\Platform\PlatformHooks;

/**
 * 无头第二实现的钩子空端口：不装 fatal box、不接 resize pump，
 * 保持 `HeadlessPlatform` 与接钩子之前的测试行为逐字相同。
 */
final class HeadlessPlatformHooks implements PlatformHooks
{
    public function install(): void
    {
    }

    public function setResizePump(?\Closure $pump): void
    {
    }

    public function release(): void
    {
    }
}
