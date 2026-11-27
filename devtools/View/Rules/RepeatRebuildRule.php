<?php
declare(strict_types=1);

namespace Drift\DevTools\View\Rules;

use Drift\Debug\Session\Thresholds;
use Drift\DevTools\View\Facts;
use Drift\DevTools\View\Fit;

/**
 * 重复重建：同一节点在窗口里几乎每帧都被重建。
 *
 * 与 `fanout` 的分工：扇出看**一帧内**的集中度（谁把这一帧撑起来的），
 * 这条看**跨帧**的持续性（谁每帧都在重来）。一个每帧只重建 3 个节点的组件
 * 不触发扇出，但它意味着"这个订阅在每帧都变" —— 那是另一类 bug。
 *
 * 窗口短于 5 帧不出结论：2 帧里重建 2 次不构成"每帧"。
 */
final class RepeatRebuildRule extends Rule
{
    public function id(): string
    {
        return 'repeat-rebuild';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function summary(): string
    {
        return '同一节点在多数帧都被重建（默认 ≥ 50% 帧）';
    }

    public function evaluate(array $facts, Thresholds $t): array
    {
        $window = Facts::mapOf($facts, 'window');
        $count = (int) ($window['count'] ?? 0);
        if ($count < 5) {
            return [];
        }

        $need = (int) \ceil($count * $t->repeatPercent / 100);
        $out = [];
        foreach (Facts::listOf($facts, 'nodes') as $node) {
            $rebuilds = (int) ($node['rebuilds'] ?? 0);
            if ($rebuilds < $need) {
                continue;
            }
            $out[] = $this->finding(
                'repeat-rebuild',
                \sprintf(
                    '%s 在 %d/%d 帧都重建（自写 %d 次）',
                    Fit::tailPath((string) ($node['path'] ?? ('#' . $node['id'])), 48),
                    $rebuilds,
                    $count,
                    (int) ($node['self'] ?? 0),
                ),
                \sprintf('node:%d', (int) ($node['id'] ?? 0)),
                \sprintf('%d/%d', $rebuilds, $count),
                \sprintf('≥ %d', $need),
                'nodes(window).rebuilds ÷ window.count',
                (float) $rebuilds,
            );
            if (\count($out) >= 2) {
                break;
            }
        }

        return $out;
    }
}
