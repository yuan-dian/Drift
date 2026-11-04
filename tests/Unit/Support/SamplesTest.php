<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Support;

use Drift\Dev\Bench\Samples;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `Samples` 分位摘要（2026-11-04 夜）。
 *
 * ★ 这个小类值得单测，是因为**它的错法都很安静**：偶数个取错中间项、`of()` 顺手
 *   改了调用方的数组、空数组算出 `min = INF` —— 都不会抛异常，只会让量具报出
 *   一个"看起来很正常"的数。而所有以它为主数的判据（帧预算余量、归一化比值、
 *   重建占比）都建在这个数上。
 *
 * ## 四条口径
 *
 * | # | 口径 | 错的后果 |
 * |---|---|---|
 * | ① | 奇数取正中、偶数取中间两个的均值 | 主数系统性偏一侧，且永远不红 |
 * | ② | `spread()` 是 `max/min`，n<2 或 min≤0 时 `1.0` | 差点被零除掉，或离散度虚报 |
 * | ③ | `of()` **不改调用方的数组** | 采样循环还要用它算别的东西，被排序后静默错位 |
 * | ④ | 空数组给全 0 而不是 `INF`/`NAN` | `INF` 会顺着判据传下去，最后印出 `inf ms` |
 */
final class SamplesTest extends TestCase
{
    #[Test]
    public function testOddCountTakesTheMiddleOne(): void
    {
        $s = Samples::of([50.0, 10.0, 30.0, 20.0, 40.0]);

        self::assertSame(5, $s->n);
        self::assertSame(30.0, $s->median);
        self::assertSame(10.0, $s->min);
        self::assertSame(50.0, $s->max);
    }

    #[Test]
    public function testEvenCountAveragesTheTwoMiddles(): void
    {
        // 中间两个是 20 与 40 ⇒ 30。★ 若实现取"下标 n/2"会得 40，差 33%。
        $s = Samples::of([10.0, 20.0, 40.0, 80.0]);

        self::assertSame(4, $s->n);
        self::assertSame(30.0, $s->median);
    }

    #[Test]
    public function testOrderDoesNotMatter(): void
    {
        $asc = Samples::of([1.0, 2.0, 3.0, 4.0, 5.0]);
        $shuffled = Samples::of([3.0, 1.0, 5.0, 2.0, 4.0]);

        self::assertSame($asc->median, $shuffled->median);
        self::assertSame($asc->min, $shuffled->min);
        self::assertSame($asc->max, $shuffled->max);
    }

    /** ★ 这条是"副作用"判据：`of()` 内部排序必须在副本上，否则采样数组会被打乱。 */
    #[Test]
    public function testDoesNotReorderTheCallersArray(): void
    {
        $input = [50.0, 10.0, 30.0];
        Samples::of($input);

        self::assertSame([50.0, 10.0, 30.0], $input);
    }

    #[Test]
    public function testSpreadIsMaxOverMin(): void
    {
        self::assertEqualsWithDelta(2.0, Samples::of([10.0, 15.0, 20.0])->spread(), 1e-9);
    }

    #[Test]
    public function testSingleSampleHasNoSpread(): void
    {
        $s = Samples::of([42.0]);

        self::assertSame(1, $s->n);
        self::assertSame(42.0, $s->median);
        self::assertSame(1.0, $s->spread(), '只有一个样本时离散度必须是 1.0，不是 0/0');
    }

    /** ★ 空数组不能给 `INF`/`NAN`：`sprintf('%.1f', INF)` 会印出 `inf`，顺着判据传下去。 */
    #[Test]
    public function testEmptyInputIsAllZero(): void
    {
        $s = Samples::of([]);

        self::assertSame(0, $s->n);
        self::assertSame(0.0, $s->min);
        self::assertSame(0.0, $s->median);
        self::assertSame(0.0, $s->max);
        self::assertSame(1.0, $s->spread());
        self::assertStringContainsString('没采到样', $s->text());
    }

    /** ★ `min = 0` 时不能除：除零会让 spread 变成 `INF`，而不是 1.0。 */
    #[Test]
    public function testZeroMinDoesNotDivideByZero(): void
    {
        self::assertSame(1.0, Samples::of([0.0, 5.0])->spread());
    }

    #[Test]
    public function testTextCarriesAllThreeNumbersAndSpread(): void
    {
        $text = Samples::of([10.0, 20.0, 30.0])->text();

        self::assertStringContainsString('med 20.0', $text);
        self::assertStringContainsString('min 10.0', $text);
        self::assertStringContainsString('max 30.0', $text);
        self::assertStringContainsString('3.00×', $text);
        self::assertStringContainsString('n=3', $text);
    }
}
