<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View\Rules;

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Facts;

/**
 * 阶段占比：最贵的那一帧里，某一段（rebuild / layout / paint / settle）吃掉大半耗时。
 *
 * ## 为什么它是 `info` 而不是 `warning`
 *
 * "settle 占 70%" 本身不是缺陷：一帧里只做一件事时，那件事必然占大头。
 * 它有价值的地方是**指向下一步查哪里** —— 所以它是说明，不是结论。
 *
 * ## 为什么这两个门槛不进 `Thresholds`
 *
 * 60% 与 2ms 不是预算，是"值得一说"的下限：低于它的段就算第一，也只是
 * 一帧里十几个小段之一，报出来是噪音。真正想少看它的人该用
 * `--min-severity=warning`，那是一条能表达"别跟我说说明"的开关；
 * 再给这条规则开两个阈值，等于同一个意思有两种表达方式。
 */
final class StageDominanceRule extends Rule
{
    /** 占比低于它不值得单独说。 */
    private const float SHARE = 0.6;

    /** 绝对耗时低于它，占比再高也只是小数目。 */
    private const float MIN_MS = 2.0;

    public function id(): string
    {
        return 'stage-dominance';
    }

    public function severity(): Severity
    {
        return Severity::Info;
    }

    public function summary(): string
    {
        return '最贵帧里某一段占了大半耗时（≥ 60% 且 ≥ 2ms）';
    }

    public function evaluate(array $facts, Thresholds $t): array
    {
        $explain = Facts::mapOf($facts, 'explain');
        $frameId = (int) ($explain['id'] ?? 0);
        $cost = (float) ($explain['cost'] ?? 0.0);
        if ($frameId <= 0 || $cost <= 0.0) {
            return [];
        }

        // `stageMs` 已按耗时降序（记录层做的），取第一段就是最大段。
        $name = '';
        $ms = 0.0;
        foreach (Facts::mapOf($explain, 'stageMs') as $stage => $value) {
            $name = (string) $stage;
            $ms = (float) $value;
            break;
        }
        if ($name === '') {
            return [];
        }

        $share = $ms / $cost;
        if ($share < self::SHARE || $ms < self::MIN_MS) {
            return [];
        }

        return [$this->finding(
            'stage-dominance',
            \sprintf('#%d 的 %.0f%% 花在 %s（%.2fms / %.2fms）', $frameId, $share * 100, $name, $ms, $cost),
            \sprintf('frame:%d stage:%s', $frameId, $name),
            \sprintf('%.2fms', $ms),
            \sprintf('占比 ≥ %.0f%% 且 ≥ %.2fms', self::SHARE * 100, self::MIN_MS),
            \sprintf('explain(%d).stageMs.%s', $frameId, $name),
        )];
    }
}
