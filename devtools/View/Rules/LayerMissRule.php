<?php
declare(strict_types=1);

namespace Drift\DevTools\View\Rules;

use Drift\Debug\Session\Thresholds;
use Drift\DevTools\View\Facts;
use Drift\DevTools\View\Fit;

/**
 * 层缓存失效：某个重绘边界在窗口里反复重录。
 *
 * ## 为什么这条必须单独有
 *
 * 边界存在的意义是"子树没变就重放命令流"。它每帧都重录，等于边界白放 ——
 * 而这件事**看不见**：帧耗时正常、重建数正常，只是每帧多画几百个节点。
 * 只有把命中 / 未命中记下来，这类"结构性浪费"才第一次可检。
 *
 * ## 为什么与 `repaint-storm` 是两条规则而不是一条
 *
 * 它们指向的**修法不同**：`repaint-storm` 是"缺边界"（去加一个），这条是
 * "边界失效"（去查谁在 `invalidate()`）。合并成一条，读者会照着"加边界"
 * 去修一个已经有边界的地方，改完报告还是红的。
 *
 * 报**原因**而不只报次数：四种原因的修法完全不同 —— `invalidated` 要查谁在
 * 主动丢缓存，`resized` 要查布局抖动，`capabilities` 要查跨端回放，
 * `first` 只是新边界。只给次数等于让读者自己去猜是哪一种。
 */
final class LayerMissRule extends Rule
{
    public function id(): string
    {
        return 'layer-miss';
    }

    public function severity(): Severity
    {
        return Severity::Info;
    }

    public function summary(): string
    {
        return '重绘边界反复重录层缓存（默认 ≥ 3 次），带最近原因';
    }

    public function evaluate(array $facts, Thresholds $t): array
    {
        $agg = Facts::mapOf($facts, 'repaints');
        if (($agg['captured'] ?? false) !== true) {
            return [];
        }

        $frames = (int) ($agg['frames'] ?? 0);
        $rate = (int) \round((float) ($agg['hitRate'] ?? 0.0) * 100);

        $out = [];
        foreach (Facts::listOf($agg, 'nodes') as $row) {
            $misses = (int) ($row['misses'] ?? 0);
            if ($misses < $t->layerMissCount) {
                continue;
            }
            $out[] = $this->finding(
                'layer-miss',
                \sprintf(
                    '%s 的层缓存重录 %d/%d 帧（最近原因 %s，窗口命中率 %d%%）',
                    Fit::tailPath((string) ($row['path'] ?? ('#' . $row['node'])), 48),
                    $misses,
                    $frames,
                    (string) ($row['reason'] ?? '?'),
                    $rate,
                ),
                \sprintf('node:%d', (int) ($row['node'] ?? 0)),
                \sprintf('%d 次', $misses),
                \sprintf('≥ %d', $t->layerMissCount),
                'repaints(window).nodes[].misses ÷ byReason',
                (float) $misses,
            );
            if (\count($out) >= 2) {
                break;
            }
        }

        return $out;
    }
}
