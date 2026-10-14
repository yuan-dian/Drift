<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\InternalThing;

/** 夹具：框架内部引用内部类型 —— 不该响（internal-leak 只管非框架代码） */
final class UsesInternal
{
    public function marker(): string
    {
        return InternalThing::marker();
    }
}
