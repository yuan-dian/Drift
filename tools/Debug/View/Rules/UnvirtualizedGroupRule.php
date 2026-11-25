<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View\Rules;

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Facts;
use Drift\Dev\Debug\View\Finding;

/**
 * 同形兄弟：一个容器底下 N 个一模一样的子树一起重建，基本就是"该虚拟化却没虚拟化"。
 *
 * 要**往下走**，不能只看根：表格页的根是整棵 `PageShell`（份数 1），
 * 真正重复的是它内部某个容器底下的 12 个同形兄弟 —— 只扫根这条规则永远不触发，
 * 而它恰恰是最常见、最容易改的一类问题。
 */
final class UnvirtualizedGroupRule extends Rule
{
    public function id(): string
    {
        return 'unvirtualized-group';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function summary(): string
    {
        return '一个容器底下多个同形子树一起重建（默认 ≥ 8 个）';
    }

    public function evaluate(array $facts, Thresholds $t): array
    {
        $cascade = Facts::mapOf($facts, 'cascade');
        $roots = Facts::listOf($cascade, 'roots');
        if ($roots === []) {
            return [];
        }

        $group = self::biggestGroup($roots, $t);
        if ($group === []) {
            return [];
        }

        $count = (int) ($group['count'] ?? 1);
        $type = (string) ($group['type'] ?? '?');
        $frameId = (int) ($cascade['frame'] ?? 0);

        return [$this->finding(
            'unvirtualized-group',
            \sprintf(
                '一个 %s 底下有 %d 个同形兄弟一起重建（每个 %d 个节点）',
                $type,
                $count,
                (int) ($group['subtree'] ?? 1),
            ),
            Finding::at($frameId, (int) ($group['id'] ?? 0)),
            (string) $count,
            '≥ ' . $t->groupSame,
            \sprintf('cascade(%d).roots[].count 沿 children 往下找', $frameId),
            (float) $count,
        )];
    }

    /**
     * 树上份数最大的同形组。
     *
     * 深度上限只是**防环的保险**，不负责裁剪：树本身已经被 `cascade` 的 `maxDepth`
     * 截过，并且截断处带 `cut` / `omitted`。这里再按"看起来够深了"另设一个上限，
     * 就会与查询层的深度打架 —— 第一次写的时候限成 4，而表格页的 12 个同形兄弟
     * 在第 8 层，规则于是永远不触发，报告看起来"没问题"。
     *
     * @param list<array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private static function biggestGroup(array $nodes, Thresholds $t, int $depth = 0): array
    {
        if ($depth > 32) {
            return [];
        }

        $best = [];
        $bestCount = 0;
        foreach ($nodes as $node) {
            $count = (int) ($node['count'] ?? 1);
            if ($count >= $t->groupSame && $count > $bestCount) {
                $best = $node;
                $bestCount = $count;
            }
            $kids = Facts::listOf($node, 'children');
            if ($kids === []) {
                continue;
            }
            $deep = self::biggestGroup($kids, $t, $depth + 1);
            $deepCount = (int) ($deep['count'] ?? 0);
            if ($deepCount > $bestCount) {
                $best = $deep;
                $bestCount = $deepCount;
            }
        }

        return $best;
    }
}
