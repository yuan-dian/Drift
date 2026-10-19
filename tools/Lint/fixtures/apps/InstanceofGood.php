<?php
declare(strict_types=1);

namespace App;

use Drift\PublicThing;

/**
 * 夹具：同类名的三种正确形态 —— 都不该响。
 *   ① use 过后 instanceof（可见）；
 *   ② 全限定名（写全了不会错，规则跳过）；
 *   ③ 变量右侧（`$x instanceof $y`，规则不判）。
 */
final class InstanceofGood
{
    public function probe(object $x, object $y): bool
    {
        $a = $x instanceof PublicThing;
        $b = $x instanceof \RuntimeException;
        $c = $x instanceof $y;

        return $a || $b || $c;
    }
}
