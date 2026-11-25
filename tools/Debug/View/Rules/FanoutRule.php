<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View\Rules;

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Facts;
use Drift\Dev\Debug\View\Finding;

/**
 * 扇出：一次重建的节点里，大半落在一个根底下。
 *
 * 这是"重建量大"与"重建量集中"的分界：前者可能只是页面大，后者说明**一个
 * 状态变更把整棵子树标脏了** —— 修法完全不同（前者靠减少节点，后者靠收窄
 * 订阅或加边界）。所以它必须同时看总数与单支占比，只看总数会把大页面一律报出来。
 */
final class FanoutRule extends Rule
{
    public function id(): string
    {
        return 'fanout';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function summary(): string
    {
        return '单帧重建里大部分集中在一个根子树（默认 ≥ 100 个且 ≥ 60%）';
    }

    public function evaluate(array $facts, Thresholds $t): array
    {
        $cascade = Facts::mapOf($facts, 'cascade');
        $rebuilt = (int) ($cascade['rebuilt'] ?? 0);
        if ($rebuilt < $t->fanoutNodes) {
            return [];
        }

        $roots = Facts::listOf($cascade, 'roots');
        if ($roots === []) {
            return [];
        }

        // 第一大支：组内每份子树 × 份数（`cascade` 的口径就是"每实例"）。
        // ★ `ids` 只在 `count > 1` 时存在（单个节点不给，免得同一件事两种写法），
        //   所以代表 id 与份数要分别从 `id` / `count` 取 —— 直接读 `ids[0]` 会拿到 0，
        //   而 0 不在有效 id 域里，错误会静默地印进报告。
        $top = $roots[0];
        $topId = (int) ($top['id'] ?? 0);
        $topCount = \max(1, (int) ($top['count'] ?? 1));
        $subtree = (int) ($top['subtree'] ?? 1) * $topCount;
        $share = (int) \round($subtree * 100 / $rebuilt);
        if ($share < $t->fanoutShare) {
            return [];
        }

        $frameId = (int) ($cascade['frame'] ?? 0);
        return [$this->finding(
            'fanout',
            \sprintf(
                '#%d 重建 %d 个，其中 %d 落在一个 %s 根（%d%%）',
                $frameId,
                $rebuilt,
                $subtree,
                (string) ($top['type'] ?? '?'),
                $share,
            ),
            Finding::at($frameId, $topId),
            (string) $rebuilt,
            '≥ ' . $t->fanoutNodes . ' 且单支 ≥ ' . $t->fanoutShare . '%',
            \sprintf('cascade(%d).roots[0] 与 rebuilt', $frameId),
        )];
    }
}
