<?php
declare(strict_types=1);

namespace App;

use Drift\InternalThing;

/** 夹具：业务引用 @internal 类型 —— 该响（error） */
final class Bad
{
    public function marker(): string
    {
        return InternalThing::marker();
    }
}
