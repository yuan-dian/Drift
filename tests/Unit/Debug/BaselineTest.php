<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\Dev\Debug\View\Baseline;
use Drift\Dev\Debug\View\BaselineText;
use Drift\Dev\Debug\View\Finding;
use Drift\Dev\Debug\View\Rules\Severity;
use PHPUnit\Framework\TestCase;

/**
 * 基线对比：七种状态、可复现性分级、基线的序列化边界。
 *
 * ## 这些判据防的是什么
 *
 * 基线报告最坏的错法是**假红**与**假绿**，两种都不报错：
 * 假红一次，人就不看了；假绿一次，CI 从此不守护任何东西。
 *
 * - 假红：把耗时当断言（±7% 抖动）、把 int/float 之差报成"阈值变了"。
 * - 假绿：文件缺失当空基线、兜底结论当度量进配对、规则换版当成"持平"。
 *
 * 所以判据不只断言"状态算对了"，还要断言**算不出的时候会说出来**
 * （`uncomparable` / `duplicates` / `excluded`），而不是悄悄取一个默认值。
 */
final class BaselineTest extends TestCase
{
    public function testSevenStatesEachGetTheirOwnRow(): void
    {
        $before = [
            self::f('keep', 'frame:1', 10.0),
            self::f('grow', 'frame:2', 10.0),
            self::f('shrink', 'frame:3', 10.0),
            self::f('gone', 'frame:4', 10.0),
            self::f('ver', 'frame:5', 10.0, 1),
            self::f('half', 'frame:6', 10.0),
        ];
        $after = [
            self::f('keep', 'frame:1', 10.2),
            self::f('grow', 'frame:2', 20.0),
            self::f('shrink', 'frame:3', 5.0),
            self::f('ver', 'frame:5', 12.0, 2),
            self::f('half', 'frame:6', null),
            self::f('born', 'frame:7', 1.0),
        ];

        $states = self::states(Baseline::compare(self::cap($before), self::cap($after), 5.0));

        self::assertSame(Baseline::SAME, $states['keep@frame:1'], '10 → 10.2 在 5% 容差内');
        self::assertSame(Baseline::WORSE, $states['grow@frame:2']);
        self::assertSame(Baseline::BETTER, $states['shrink@frame:3']);
        self::assertSame(Baseline::FIXED, $states['gone@frame:4'], '基线有、当前没有');
        self::assertSame(Baseline::RULE_CHANGED, $states['ver@frame:5'], '规则换版不算变差');
        self::assertSame(Baseline::UNCOMPARABLE, $states['half@frame:6'], '一侧没数值');
        self::assertSame(Baseline::NEW, $states['born@frame:7'], '当前有、基线没有');
    }

    /** 容差是闭区间：正好等于它算持平，超出一点才算变差。 */
    public function testToleranceIsInclusiveAtItsBoundary(): void
    {
        $at10 = Baseline::compare(
            self::cap([self::f('x', 'frame:1', 100.0)]),
            self::cap([self::f('x', 'frame:1', 110.0)]),
            10.0,
        );
        self::assertSame(Baseline::SAME, $at10['rows'][0]['state'], '+10% 正好等于容差 10%');

        $over = Baseline::compare(
            self::cap([self::f('x', 'frame:1', 100.0)]),
            self::cap([self::f('x', 'frame:1', 111.0)]),
            10.0,
        );
        self::assertSame(Baseline::WORSE, $over['rows'][0]['state'], '+11% 应算变差');
    }

    /** 基线 0 → 正数：百分比算不出来，但"从没有变成有"是变差。 */
    public function testZeroBaselineGrowsIntoAWorse(): void
    {
        $cmp = Baseline::compare(
            self::cap([self::f('x', 'frame:1', 0.0)]),
            self::cap([self::f('x', 'frame:1', 8.0)]),
        );

        self::assertSame(Baseline::WORSE, $cmp['rows'][0]['state']);
        self::assertNull($cmp['rows'][0]['percent'], '0 为分母时不编一个百分比出来');
        self::assertSame(8.0, $cmp['rows'][0]['delta']);
    }

    /**
     * 耗时变化要列出来，但不能进 `regressions` —— 它是 `--assert` 唯一看的数。
     *
     * 这条是整份设计的核心：本机实测帧耗时抖动 ±7%、计数零抖动。
     */
    public function testTimingRulesReportButDoNotGate(): void
    {
        $cmp = Baseline::compare(
            self::cap([self::f('slow-frame', 'frame:2', 40.0), self::f('fanout', 'frame:2 node:1', 360.0)]),
            self::cap([self::f('slow-frame', 'frame:2', 80.0), self::f('fanout', 'frame:2 node:1', 240.0)]),
        );

        $rows = self::states($cmp);
        self::assertSame(Baseline::WORSE, $rows['slow-frame@frame:2'], '耗时变差照样算状态');
        self::assertSame(Baseline::BETTER, $rows['fanout@frame:2 node:1']);

        self::assertSame(0, $cmp['regressions'], '耗时类变差不进断言');
        self::assertSame(1, $cmp['unstable'], '但要计入"偏离"让读者知道');
        self::assertFalse($cmp['rows'][0]['deterministic']);

        $worse = Baseline::compare(
            self::cap([self::f('fanout', 'frame:2 node:1', 240.0)]),
            self::cap([self::f('fanout', 'frame:2 node:1', 720.0)]),
        );
        self::assertSame(1, $worse['regressions'], '确定性度量变差必须进断言');
        self::assertSame(0, $worse['unstable']);
    }

    /** 兜底结论不是度量：它连配对都不参加。 */
    public function testFallbackConclusionsNeverPair(): void
    {
        $clean = Finding::fallback('clean', '没有触发规则', 'window:1-1', '0 条', '—', '再看一次');
        $cmp = Baseline::compare(self::cap([$clean]), self::cap([$clean]));

        self::assertSame([], $cmp['rows'], '兜底结论不该生成一行对比');
        self::assertSame(0, $cmp['regressions']);

        $snap = Baseline::snapshot(self::cap([$clean, self::f('fanout', 'frame:2', 1.0)]));
        self::assertSame(1, $snap['excluded'], 'excluded 要解释空基线是"真干净"');
        self::assertCount(1, $snap['findings']);
    }

    /**
     * 兜底编号只有 `RuleRegistry::FALLBACK_IDS` 一份：拿普通规则名造兜底要当场炸。
     *
     * 不炸的后果是"一条慢帧发现被当成结论" —— 它在报告里看起来完全正常，
     * 只是从基线配对里静默消失了。
     */
    public function testFallbackFactoryRefusesANonFallbackId(): void
    {
        $this->expectException(\LogicException::class);
        Finding::fallback('slow-frame', 'x', 'frame:1', '1', '—', 'recheck');
    }

    /** 两侧都没数值 = 同一句结论说了两遍，那是持平；只有一侧没有才是不可比。 */
    public function testBothNullIsSameButOneNullIsUncomparable(): void
    {
        $null = self::f('x', 'frame:1', null);

        self::assertSame(
            Baseline::SAME,
            Baseline::compare(self::cap([$null]), self::cap([$null]))['rows'][0]['state'],
        );
        self::assertSame(
            Baseline::UNCOMPARABLE,
            Baseline::compare(self::cap([self::f('x', 'frame:1', 10.0)]), self::cap([$null]))['rows'][0]['state'],
        );
    }

    /** 同键重复要计数带出来：它是"地址不够唯一"这条不变量的监视器。 */
    public function testDuplicateAddressesAreCountedNotSwallowed(): void
    {
        $dup = [self::f('fanout', 'frame:2', 1.0), self::f('fanout', 'frame:2', 2.0)];
        $cmp = Baseline::compare(self::cap($dup), self::cap([]));

        self::assertSame(1, $cmp['duplicates']['before']);
        self::assertSame(0, $cmp['duplicates']['after']);
        self::assertSame(2, $cmp['findings']['before'], '原始条数仍然是 2');
    }

    /** 变差的排最前，同状态内差得最多的先看。 */
    public function testWorseRowsSortByMagnitude(): void
    {
        $cmp = Baseline::compare(
            self::cap([self::f('a', 'frame:1', 10.0), self::f('b', 'frame:2', 10.0), self::f('c', 'frame:3', 10.0)]),
            self::cap([self::f('a', 'frame:1', 20.0), self::f('b', 'frame:2', 30.0), self::f('c', 'frame:3', 12.0)]),
        );

        self::assertSame(['b', 'a', 'c'], \array_column($cmp['rows'], 'rule'));
    }

    /** 基线存下**当时**的规则版本：拿今天的版本表解释上周的基线，永远看不出换版。 */
    public function testSnapshotKeepsTheVersionItWasRecordedWith(): void
    {
        $snap = Baseline::snapshot(self::cap([self::f('fanout', 'frame:2 node:1', 360.0, 3)]));
        $back = Baseline::restore(\json_decode((string) \json_encode($snap), true));

        self::assertSame(Baseline::FORMAT, $snap['format']);
        self::assertSame(360.0, $back['findings'][0]->value);
        self::assertSame(3, $back['findings'][0]->version(), '不能回退成当前表里的版本');
    }

    /** 五种坏文件各自报错，一种都不能当空基线读进来。 */
    public function testRestoreRefusesEveryKindOfBrokenFile(): void
    {
        $cases = [
            '版本不认识' => ['format' => 9, 'findings' => []],
            '缺 findings' => ['format' => 1],
            'findings 不是数组' => ['format' => 1, 'findings' => 'x'],
            '缺 address' => ['format' => 1, 'findings' => [['rule' => 'x']]],
            'value 不是数字' => ['format' => 1, 'findings' => [['rule' => 'x', 'address' => 'frame:1', 'value' => 'high']]],
        ];

        foreach ($cases as $label => $bad) {
            $caught = null;
            try {
                Baseline::restore($bad);
            } catch (\InvalidArgumentException $e) {
                $caught = $e->getMessage();
            }
            self::assertNotNull($caught, "{$label} 应该报错，而不是当空基线");
        }
    }

    /** 口径变了只报不平：阈值差异要出现在头部，且 int/float 之差不算"变了"。 */
    public function testThresholdDiffIsReportedWithoutNumericNoise(): void
    {
        $before = Baseline::capture('t', 'd', [self::f('fanout', 'frame:2', 1.0)], ['slowMs' => 16, 'reshapePx' => 8], []);
        $after = Baseline::capture('t', 'd', [self::f('fanout', 'frame:2', 1.0)], ['slowMs' => 10, 'reshapePx' => 8.0], []);

        $cmp = Baseline::compare($before, $after);
        self::assertSame(['slowMs'], $cmp['context']['thresholds'], '8 与 8.0 是同一个阈值');
    }

    /** 呈现层：耗时类行标 `~`，确定性变差标 `▼`，两者都在正文里。 */
    public function testTextMarksTimingRowsAndKeepsThemVisible(): void
    {
        $cmp = Baseline::compare(
            self::cap([self::f('slow-frame', 'frame:2', 40.0), self::f('fanout', 'frame:2 node:1', 100.0)]),
            self::cap([self::f('slow-frame', 'frame:2', 90.0), self::f('fanout', 'frame:2 node:1', 360.0)]),
        );

        $text = \implode("\n", BaselineText::render($cmp, 'base.json')['lines']);

        self::assertStringContainsString('~ slow-frame', $text);
        self::assertStringContainsString('▼ fanout', $text);
        self::assertStringContainsString('回归 1', $text);
        self::assertStringContainsString('偏离 1', $text);
        self::assertStringContainsString('base.json', $text);
    }

    /**
     * 耗时类完全落在容差内时也要自报"不计断言"。
     *
     * 钉的是"符号没有说明"：`~` 行在正文里，但若只有偏离 > 0 才解释，读者会以为
     * 那一行参与了断言。CLI 判据曾因此偶发假红 —— 它断言"必然出现偏离"，
     * 而真实抖动只有 2.6%。
     */
    public function testTimingRowsSelfReportEvenWhenNothingDrifts(): void
    {
        $cmp = Baseline::compare(
            self::cap([self::f('slow-frame', 'frame:2', 40.0)]),
            self::cap([self::f('slow-frame', 'frame:2', 41.0)]),
        );

        self::assertSame(0, $cmp['unstable'], '1% 在容差内，不算偏离');
        $text = \implode("\n", BaselineText::render($cmp, 'base.json')['lines']);
        self::assertStringContainsString('~ slow-frame', $text);
        self::assertStringContainsString('1 行耗时类不计断言', $text);
        self::assertStringNotContainsString('偏离', $text, '没超容差就不该说偏离');
    }

    /** 耗时类"消失"也是信息：不进回归，但不能连行一起藏掉。 */
    public function testTimingRowThatDisappearsIsStillListed(): void
    {
        $cmp = Baseline::compare(self::cap([self::f('slow-frame', 'frame:2', 40.0)]), self::cap([]));

        self::assertSame(Baseline::FIXED, $cmp['rows'][0]['state']);
        self::assertSame(0, $cmp['regressions'], '消失不是回归');
        $text = \implode("\n", BaselineText::render($cmp, 'base.json')['lines']);
        self::assertStringContainsString('~ slow-frame', $text);
        self::assertStringContainsString('不计断言', $text);
    }

    /**
     * @param list<Finding> $findings
     * @return array{label: string, digest: string, thresholds: array<string, float|int>, selection: array<string, mixed>, excluded: int, findings: list<Finding>}
     */
    private static function cap(array $findings, string $digest = 'd'): array
    {
        return Baseline::capture('t', $digest, $findings, ['slowMs' => 16.7], ['rules' => ['a']]);
    }

    private static function f(string $rule, string $address, ?float $value, int $version = 1): Finding
    {
        return new Finding(
            $rule,
            $rule . ' ' . $address,
            $address,
            (string) $value,
            '—',
            'recheck',
            Severity::Info,
            $value,
            $version,
        );
    }

    /**
     * @param array<string, mixed> $cmp
     * @return array<string, string>
     */
    private static function states(array $cmp): array
    {
        $map = [];
        foreach ($cmp['rows'] as $row) {
            $map[$row['rule'] . '@' . $row['address']] = $row['state'];
        }

        return $map;
    }
}
