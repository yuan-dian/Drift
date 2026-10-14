<?php
declare(strict_types=1);

namespace Drift;

/** 夹具：没标公共面标注 ⇒ 默认内部（§10.1.3） */
final class InternalThing
{
    public static function marker(): string
    {
        return 'internal';
    }
}
