<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Core\Versioned;

/** @internal 冒烟夹具：一个可手推版本号的 `Versioned`（`Computed` 的依赖替身）。 */
final class VersionProbe implements Versioned
{
    private int $version = 0;

    public function __construct(private int $value = 0)
    {
    }

    public function version(): int
    {
        return $this->version;
    }

    /** 推一次版本（= "我变了"），值不动 */
    public function bump(): void
    {
        $this->version++;
    }

    public function value(): int
    {
        return $this->value;
    }
}
