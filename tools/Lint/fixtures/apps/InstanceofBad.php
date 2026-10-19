<?php
declare(strict_types=1);

namespace App;

/**
 * 夹具：instanceof 用了 `Drift\PublicThing` 的短名但没 use、也不在它的命名空间
 * ⇒ 解析落到 `App\PublicThing`，运行期恒 false —— 本仓咬过 ≥3 次的静默缺陷
 * 模式（ThemeChangedEvent / SemanticsBuilder / RenderFlex Stretch）。
 */
final class InstanceofBad
{
    public function probe(object $x): bool
    {
        return $x instanceof PublicThing;   // ← 漏 use（该类在 Drift 全局）
    }
}
