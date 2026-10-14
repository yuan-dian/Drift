<?php
declare(strict_types=1);

namespace App;

use Drift\PublicThing;

/** 夹具：业务引用 @api 类型 —— 不该响 */
final class Good
{
    public function marker(): string
    {
        return PublicThing::marker();
    }
}
