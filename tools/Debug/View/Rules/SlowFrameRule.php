<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View\Rules;

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Facts;

/**
 * 慢帧：单帧耗时超过预算。
 *
 * 这是唯一一条"不需要解释就有意义"的规则 —— 预算就是 60fps 的一帧，
 * 超了就是掉帧。所以它是 `error`，也是报告里第一条：后面的规则都在解释
 * "这一帧为什么慢"，而它们只有在先有一帧被认定为慢时才值得读。
 *
 * 只报前三帧。报满会挤掉所有解释性发现，而读者最该看的是最贵的那一帧 ——
 * 前三帧已经足够说明"是不是普遍偏慢"（三帧都慢 ⇒ 系统性问题；只有一帧 ⇒ 定位到它）。
 */
final class SlowFrameRule extends Rule
{
    public function id(): string
    {
        return 'slow-frame';
    }

    public function severity(): Severity
    {
        return Severity::Error;
    }

    public function summary(): string
    {
        return '单帧耗时超过预算（默认 16.7ms）';
    }

    /** 帧耗时随调度抖动 ±7%，跨进程不可复现。 */
    public function deterministic(): bool
    {
        return false;
    }

    public function evaluate(array $facts, Thresholds $t): array
    {
        $slow = [];
        foreach (Facts::listOf($facts, 'frames') as $frame) {
            if ((float) ($frame['cost'] ?? 0.0) > $t->slowMs) {
                $slow[] = $frame;
            }
        }
        // 耗时降序 → 帧号升序：同耗时也要有确定次序，否则两次投影不同。
        \usort($slow, static fn(array $a, array $b): int => ((float) $b['cost'] <=> (float) $a['cost'])
            ?: ((int) $a['id'] <=> (int) $b['id']));

        $out = [];
        foreach (\array_slice($slow, 0, 3) as $frame) {
            $id = (int) $frame['id'];
            $cost = (float) $frame['cost'];
            $out[] = $this->finding(
                'slow-frame',
                \sprintf('慢帧 #%d %.2fms，超预算 %.1f×', $id, $cost, $cost / $t->slowMs),
                'frame:' . $id,
                \sprintf('%.2fms', $cost),
                \sprintf('%.1fms', $t->slowMs),
                \sprintf('explain(%d).cost 应给出同一数值', $id),
                $cost,
            );
        }

        return $out;
    }
}
