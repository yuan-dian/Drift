<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View\Rules;

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Facts;

/**
 * 几何没覆盖目标帧时，必须说"没看见"。
 *
 * 几何窗口比帧窗口短（60 对 600），而"几何没变"本来就不记新行 —— 所以
 * `window.to < frame` 是正常情况，不是缺口；只有一整帧都没有任何盒、或
 * 请求的帧早于窗口起点，才算规则没有看到几何。
 *
 * 这条与兜底的 `inconclusive` 是两件事：后者说"因果树被截断"，这条说
 * "布局规则一条都没看到"。它们可以同时出现 —— 一份证据可以既截断了树，
 * 又没有几何。
 */
final class GeometryGapRule extends Rule
{
    public function id(): string
    {
        return 'geometry-inconclusive';
    }

    public function severity(): Severity
    {
        return Severity::Info;
    }

    public function summary(): string
    {
        return '几何窗口没覆盖目标帧，布局规则没有输入';
    }

    public function evaluate(array $facts, Thresholds $t): array
    {
        $geometry = Facts::mapOf($facts, 'geometry');
        $rows = Facts::listOf($geometry, 'rows');
        $frame = (int) ($geometry['frame'] ?? 0);
        $window = Facts::mapOf($geometry, 'window');
        $from = (int) ($window['from'] ?? 0);
        $to = (int) ($window['to'] ?? 0);
        if ($rows !== [] && ($from === 0 || $frame === 0 || $from <= $frame)) {
            return [];
        }

        $address = $frame > 0
            ? 'frame:' . $frame
            : \sprintf('window:%d-%d', $from, $to);

        return [$this->finding(
            'geometry-inconclusive',
            $frame > 0 && $from > $frame
                ? \sprintf('几何窗口从 #%d 开始，#%d 不在其中，布局规则没有看到任何盒', $from, $frame)
                : '没有几何事实，布局规则一条也没有看',
            $address,
            '0 个盒',
            '至少 1 个盒',
            'boxes(frame).window 看窗口范围；换一帧或加大几何窗口',
        )];
    }
}
