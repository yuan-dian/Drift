<?php
declare(strict_types=1);

namespace Drift\Geometry;

use Drift\Errors;

/** 夹具：Geometry → Errors 在 §4.3 的允许集内 —— 不该响 */
final class Good
{
    public function fail(string $message): void
    {
        Errors::report($message);
    }
}
