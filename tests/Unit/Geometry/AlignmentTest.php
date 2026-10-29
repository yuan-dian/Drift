<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Geometry;

use Drift\Geometry\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `Alignment` 值对象（2026-10-29，A4c 的地基）。
 *
 * ★ **不继承 `SmokeTestCase`**：那个基类要求每条判据用 `check()` 记录并
 *   `flushChecks()`，直接 `assert*` 会被判"一条断言都没跑"（空跑即失败）。
 *   纯值对象没有夹具、没有帧，用 `TestCase` 更直白。
 *
 * ## 三条口径
 *
 * | # | 口径 | 为什么需要判据 |
 * |---|---|---|
 * | ① | `-1 ⇒ 0`、`0 ⇒ 半`、`+1 ⇒ 全` | 这是它与"九宫格枚举"的**全部换算**，错一个系数九宫格就整体偏移 |
 * | ② | 中间值有意义（`0.5 ⇒ 3/4`） | 这是它存在的理由；只测九个端点的话，一个"取整到九宫格"的实现也能全绿 |
 * | ③ | `describe()` 认得九宫格、认不出给坐标 | 报错信息 / 示例台靠它；认错会让排查方向错 |
 */
final class AlignmentTest extends TestCase
{
    #[Test]
    public function testResolveCoversStartCenterEnd(): void
    {
        // 自由空间 100：起点 0 / 居中 50 / 终点 100
        self::assertSame(0.0, Alignment::topLeft()->resolveX(100.0));
        self::assertSame(50.0, Alignment::center()->resolveX(100.0));
        self::assertSame(100.0, Alignment::bottomRight()->resolveX(100.0));

        // 两轴独立：`topRight` 的 y 是起点、x 是终点
        $tr = Alignment::topRight();
        self::assertSame(100.0, $tr->resolveX(100.0));
        self::assertSame(0.0, $tr->resolveY(100.0));
    }

    #[Test]
    public function testResolveInterpolatesBetweenAnchors(): void
    {
        // ★ 这条是"连续 vs 九宫格"的分水岭：0.5 必须落在 3/4 处，
        //   而不是被取整成 0（居中）或 1（终点）。
        self::assertSame(75.0, (new Alignment(0.5, 0.5))->resolveX(100.0));
        self::assertSame(25.0, (new Alignment(-0.5, -0.5))->resolveY(100.0));
    }

    #[Test]
    public function testAllNineAnchorsAreDistinctAndInBox(): void
    {
        $all = [
            'topLeft' => Alignment::topLeft(),
            'topCenter' => Alignment::topCenter(),
            'topRight' => Alignment::topRight(),
            'centerLeft' => Alignment::centerLeft(),
            'center' => Alignment::center(),
            'centerRight' => Alignment::centerRight(),
            'bottomLeft' => Alignment::bottomLeft(),
            'bottomCenter' => Alignment::bottomCenter(),
            'bottomRight' => Alignment::bottomRight(),
        ];

        // ① 九个两两不等（写错一个工厂会让两个名字撞成同一个点）
        $names = \array_keys($all);
        foreach ($names as $i => $left) {
            foreach ($names as $j => $right) {
                if ($i >= $j) {
                    continue;
                }
                self::assertFalse(
                    $all[$left]->equals($all[$right]),
                    "{$left} 与 {$right} 不应该相等",
                );
            }
        }

        // ② 九个都在盒内、且都要么居中要么不居中（自洽）
        foreach ($all as $name => $a) {
            self::assertTrue($a->isInBox(), "{$name} 应该 isInBox");
        }
        self::assertTrue(Alignment::center()->isCentered());
        self::assertFalse(Alignment::topLeft()->isCentered());
    }

    #[Test]
    public function testOutOfBoxIsAllowed(): void
    {
        // ★ 不夹取是**刻意的**（滑入/滑出动效需要把内容推出去）。
        //   夹在消费方（`RenderBox` / `RenderStack` 都把自由空间夹到 ≥ 0）。
        $outside = new Alignment(2.0, -2.0);
        self::assertFalse($outside->isInBox());
        self::assertSame(150.0, $outside->resolveX(100.0));
        self::assertSame(-50.0, $outside->resolveY(100.0));
    }

    #[Test]
    public function testDescribeNamesTheNineAndPrintsTheRest(): void
    {
        self::assertSame('topRight', Alignment::topRight()->describe());
        self::assertSame('center', Alignment::center()->describe());
        self::assertSame('bottomLeft', Alignment::bottomLeft()->describe());
        self::assertSame('Alignment(0.50, -0.25)', (new Alignment(0.5, -0.25))->describe());
    }

    #[Test]
    public function testEqualsToleratesFloatNoise(): void
    {
        self::assertTrue((new Alignment(1.0, -1.0))->equals(Alignment::topRight()));
        self::assertTrue(
            (new Alignment(1.0 + 1.0E-12, -1.0))->equals(Alignment::topRight()),
            '浮点噪声应该被 EPSILON 吸收',
        );
        self::assertFalse((new Alignment(1.0, 0.0))->equals(Alignment::topRight()));
    }
}
