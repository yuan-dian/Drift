<?php
declare(strict_types=1);

namespace App;

use Drift\InternalThing;

/**
 * 夹具：业务引用 @internal 类型，但有显式豁免 —— 该响（降为 warn）
 *
 * @internal-use-allowed: 等 upstream 修 #123 后移除
 */
final class Exempt
{
    public function marker(): string
    {
        return InternalThing::marker();
    }
}
