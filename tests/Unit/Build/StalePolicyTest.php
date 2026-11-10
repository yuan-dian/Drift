<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Build;

use Drift\Dev\Build\StalePolicy;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `StalePolicy` 决策表（REVIEW §1.4 / §5 第 1 项）。
 *
 * ★ 只验行为，不读 `drift-build.php`：接线由 `tools/build-stale-check.php` 对账，
 *   两道判据不同源。
 * ★ 核心判死条件：不显式 opt-in 时，任何输入组合都不该返回 INCREMENTAL。
 */
final class StalePolicyTest extends SmokeTestCase
{
    private const A = 'aaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbb';

    public function testUnchangedWithProductSkips(): void
    {
        check(
            'StalePolicy · 输入未变且产物在 ⇒ SKIP（保留短路）',
            StalePolicy::decide(self::A, self::A, true) === StalePolicy::SKIP
        );
        $this->flushChecks();
    }

    public function testChangedInputNeverTrustsTheCache(): void
    {
        $cases = [
            '输入变了 + 产物在' => [self::A, self::B, true],
            '输入变了 + 产物不在' => [self::A, self::B, false],
            '首次构建（无清单）' => ['', self::B, false],
            '首次构建 + 产物在' => ['', self::B, true],
            '输入未变但产物不在' => [self::A, self::A, false],
        ];
        foreach ($cases as $name => [$previous, $current, $exists]) {
            $got = StalePolicy::decide($previous, $current, $exists);
            check(
                "StalePolicy · {$name} ⇒ FULL（门不能信缓存）",
                $got === StalePolicy::FULL,
                'got=' . $got
            );
        }
        $this->flushChecks();
    }

    public function testForceFullOverridesEverything(): void
    {
        check(
            'StalePolicy · --full 压过短路',
            StalePolicy::decide(self::A, self::A, true, true) === StalePolicy::FULL
        );
        check(
            'StalePolicy · --full 压过 --incremental',
            StalePolicy::decide(self::A, self::A, true, true, true) === StalePolicy::FULL
        );
        $this->flushChecks();
    }

    public function testIncrementalOnlyWhenExplicitlyOptedIn(): void
    {
        check(
            'StalePolicy · 输入变了 + opt-in ⇒ INCREMENTAL',
            StalePolicy::decide(self::A, self::B, true, false, true) === StalePolicy::INCREMENTAL
        );
        check(
            'StalePolicy · 首次 + opt-in ⇒ INCREMENTAL',
            StalePolicy::decide('', self::B, false, false, true) === StalePolicy::INCREMENTAL
        );
        check(
            'StalePolicy · opt-in 不压过短路（输入未变仍 SKIP，不白重链 28s）',
            StalePolicy::decide(self::A, self::A, true, false, true) === StalePolicy::SKIP
        );
        $this->flushChecks();
    }

    public function testDefaultNeverReturnsIncremental(): void
    {
        $seen = [];
        foreach (['', self::A, self::B] as $previous) {
            foreach (['', self::A, self::B] as $current) {
                foreach ([false, true] as $exists) {
                    $seen[StalePolicy::decide($previous, $current, $exists)] = true;
                }
            }
        }
        check(
            'StalePolicy · 默认参数下穷举 18 组输入，绝不返回 INCREMENTAL',
            !isset($seen[StalePolicy::INCREMENTAL]),
            'seen=' . \implode(',', \array_keys($seen))
        );
        $this->flushChecks();
    }

    public function testLabelCoversAllDecisions(): void
    {
        $labels = [];
        foreach ([StalePolicy::SKIP, StalePolicy::FULL, StalePolicy::INCREMENTAL] as $decision) {
            $labels[] = StalePolicy::label($decision);
        }
        check(
            'StalePolicy · 三态 label 互不相同、非空、不是兜底文案',
            \count(\array_unique($labels)) === 3
                && !\in_array('', $labels, true)
                && !\in_array('未知决策', $labels, true),
            \implode(' | ', $labels)
        );
        check('StalePolicy · 未知决策不抛异常', StalePolicy::label('?') === '未知决策');
        $this->flushChecks();
    }
}
