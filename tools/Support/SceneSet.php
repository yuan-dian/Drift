<?php
declare(strict_types=1);

namespace Drift\Dev\Support;

/**
 * 集合封闭性对账：声明集合 vs 实际集合，**双向**。
 *
 * ★ 单向的代价实测过：只判「场景有没有基线」时，删一页 ⇒ 场景数 −1、旧基线成孤儿、
 *   gate 静默全绿。少一个、多一个都要红，才叫封闭。
 * ★ 纯函数，不碰文件系统；期望由调用方给，`--selftest` 用合成集合反证它有牙。
 */
final class SceneSet
{
    /**
     * @param list<string> $declared 冻结清单
     * @param list<string> $actual   磁盘 / 基线实际集合
     * @return array{missing: list<string>, orphan: list<string>}
     */
    public static function compare(array $declared, array $actual): array
    {
        $missing = \array_values(\array_diff($declared, $actual));
        $orphan = \array_values(\array_diff($actual, $declared));
        \sort($missing);
        \sort($orphan);

        return ['missing' => $missing, 'orphan' => $orphan];
    }
}
