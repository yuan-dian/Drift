<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View\Rules;

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Facts;
use Drift\Dev\Debug\View\Fit;

/**
 * 重画风暴：同一节点在窗口里几乎每帧都被重画。
 *
 * ## 为什么这条比"这一帧画得多"有用
 *
 * 单帧画 400 个节点不一定是问题（首帧、整页切换都这样）。**每帧**都画同一批
 * 节点才是问题：说明包住它们的重绘边界没起作用，或者根本没有边界。
 *
 * 判据是"该节点被画的帧数 ÷ 窗口帧数"，两个数都来自同一次 `repaints()` ——
 * 分母不另取，否则"扫过的帧"与"节点的帧"会各算一遍而悄悄分家。
 *
 * 优先报**自己就是边界**的节点（`misses > 0`）：它们是"要不要画"的决策点，
 * 修法落在它们身上（挪边界、拆子树）。没有边界候选时才退回报普通节点，
 * 那时结论是"这一片都没有边界"，而不是"这几个节点各自有问题"。
 */
final class RepaintStormRule extends Rule
{
    public function id(): string
    {
        return 'repaint-storm';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function summary(): string
    {
        return '同一节点几乎每帧都被重画（默认 ≥ 80% 绘制窗口帧）';
    }

    public function evaluate(array $facts, Thresholds $t): array
    {
        $agg = Facts::mapOf($facts, 'repaints');
        $frames = (int) ($agg['frames'] ?? 0);
        // 窗口太短时"每帧都画"是必然：2 帧里画 2 帧不构成任何结论。
        if (($agg['captured'] ?? false) !== true || $frames < 5) {
            return [];
        }

        $need = (int) \ceil($frames * $t->repaintPercent / 100);
        $hot = [];
        $plain = [];
        foreach (Facts::listOf($agg, 'nodes') as $row) {
            if ((int) ($row['frames'] ?? 0) < $need) {
                continue;
            }
            if ((int) ($row['misses'] ?? 0) > 0) {
                $hot[] = $row;
            } else {
                $plain[] = $row;
            }
        }

        $pick = $hot !== [] ? $hot : $plain;
        $note = $hot === [] ? '（窗口里没有重绘边界）' : '';
        $out = [];
        foreach ($pick as $row) {
            $painted = (int) ($row['frames'] ?? 0);
            $out[] = $this->finding(
                'repaint-storm',
                \sprintf(
                    '%s 在 %d/%d 帧都被重画%s',
                    Fit::tailPath((string) ($row['path'] ?? ('#' . $row['node'])), 48),
                    $painted,
                    $frames,
                    $note,
                ),
                \sprintf('node:%d', (int) ($row['node'] ?? 0)),
                \sprintf('%d/%d', $painted, $frames),
                \sprintf('≥ %d', $need),
                'repaints(window).nodes[].frames ÷ repaints.frames',
            );
            if (\count($out) >= 2) {
                break;
            }
        }

        return $out;
    }
}
