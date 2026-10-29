<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Geometry;

use Drift\Geometry\Matrix2D;
use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `Matrix2D` 的算术契约。
 *
 * ## 判据为什么落在"定点"和"次序"上
 *
 * 矩阵写错最典型的两类：① 复数/次序搞反（先转后移 vs 先移后转）—— 只有拿**两个
 * 不同形状的变换复合**才测得出来；② `around` 系列漏了平移回去 —— 判据是
 * "**中心点变换后不动**"，这是唯一不依赖具体数值的写法。
 *
 * ★ 旋转方向也钉死：屏幕坐标 y 向下，顺时针为正（与 Skia / CSS 同向）。
 *   `rotation(90)` 把 `(1,0)` 送到 `(0,1)` —— 若有人改成数学惯例（逆时针），
 *   这条会红，而不是"图标歪了 180°"这种要看图才发现的问题。
 */
final class Matrix2DTest extends TestCase
{
    #[Test]
    public function testIdentityLeavesPointsAlone(): void
    {
        $m = Matrix2D::identity();

        $this->assertTrue($m->isIdentity());
        $this->assertSame(3.0, $m->applyX(3.0, 7.0));
        $this->assertSame(7.0, $m->applyY(3.0, 7.0));
    }

    #[Test]
    public function testRotationIsClockwiseOnScreenAxes(): void
    {
        $m = Matrix2D::rotation(90.0);

        // (1,0) 顺时针 90° ⇒ 屏幕坐标的 (0,1)（向下）
        $this->assertEqualsWithDelta(0.0, $m->applyX(1.0, 0.0), 1.0E-9);
        $this->assertEqualsWithDelta(1.0, $m->applyY(1.0, 0.0), 1.0E-9);

        // (0,1) ⇒ (-1,0)（向左）
        $this->assertEqualsWithDelta(-1.0, $m->applyX(0.0, 1.0), 1.0E-9);
        $this->assertEqualsWithDelta(0.0, $m->applyY(0.0, 1.0), 1.0E-9);
    }

    #[Test]
    public function testRotationAroundKeepsCenterFixed(): void
    {
        $m = Matrix2D::rotationAround(37.0, 120.0, 80.0);

        $this->assertEqualsWithDelta(120.0, $m->applyX(120.0, 80.0), 1.0E-9);
        $this->assertEqualsWithDelta(80.0, $m->applyY(120.0, 80.0), 1.0E-9);
    }

    #[Test]
    public function testScalingAroundKeepsCenterFixed(): void
    {
        $m = Matrix2D::scalingAround(2.0, 0.5, 40.0, 10.0);

        $this->assertEqualsWithDelta(40.0, $m->applyX(40.0, 10.0), 1.0E-9);
        $this->assertEqualsWithDelta(10.0, $m->applyY(40.0, 10.0), 1.0E-9);

        // 相对中心缩放：距离中心 10 的点变成 20
        $this->assertEqualsWithDelta(60.0, $m->applyX(50.0, 10.0), 1.0E-9);
    }

    /**
     * `then()` 的次序：`$a->then($b)` = 先 a 后 b。
     *
     * ★ 这条必须用**两个不同形状**的变换（平移 + 旋转）来测：两个同形状的复合
     *   换次序结果可能一样，测不出错序。
     */
    #[Test]
    public function testThenAppliesThisBeforeNext(): void
    {
        $moveFirst = Matrix2D::translation(10.0, 0.0)->then(Matrix2D::rotation(90.0));
        // 先 (1,0) ⇒ (11,0)，再顺时针 90° ⇒ (0,11)
        $this->assertEqualsWithDelta(0.0, $moveFirst->applyX(1.0, 0.0), 1.0E-9);
        $this->assertEqualsWithDelta(11.0, $moveFirst->applyY(1.0, 0.0), 1.0E-9);

        $rotateFirst = Matrix2D::rotation(90.0)->then(Matrix2D::translation(10.0, 0.0));
        // 先 (1,0) ⇒ (0,1)，再平移 (10,0) ⇒ (10,1)
        $this->assertEqualsWithDelta(10.0, $rotateFirst->applyX(1.0, 0.0), 1.0E-9);
        $this->assertEqualsWithDelta(1.0, $rotateFirst->applyY(1.0, 0.0), 1.0E-9);
    }

    #[Test]
    public function testInverseRoundTrips(): void
    {
        $m = Matrix2D::rotationAround(23.0, 15.0, 15.0)
            ->then(Matrix2D::scalingAround(1.7, 0.6, 15.0, 15.0))
            ->then(Matrix2D::translation(-4.0, 9.0));

        $this->assertTrue($m->isInvertible());

        $inv = $m->inverted();
        $this->assertNotNull($inv);

        $p = new Point(7.0, 3.0);
        $there = $m->applyPoint($p);
        $back = $inv->applyPoint($there);

        $this->assertEqualsWithDelta($p->x, $back->x, 1.0E-9);
        $this->assertEqualsWithDelta($p->y, $back->y, 1.0E-9);
    }

    #[Test]
    public function testTranslatedComposesInside(): void
    {
        // 内层平移（`CTM · T`）：点先平移、再被已有旋转转走
        $m = Matrix2D::rotation(90.0)->translated(10.0, 0.0);
        $this->assertEqualsWithDelta(0.0, $m->applyX(1.0, 0.0), 1.0E-9);
        $this->assertEqualsWithDelta(11.0, $m->applyY(1.0, 0.0), 1.0E-9);

        // 对照：`then(translation)` 是**外层**平移，落点完全不同 ⇒ 两者不可互换
        $outer = Matrix2D::rotation(90.0)->then(Matrix2D::translation(10.0, 0.0));
        $this->assertEqualsWithDelta(10.0, $outer->applyX(1.0, 0.0), 1.0E-9);
        $this->assertEqualsWithDelta(1.0, $outer->applyY(1.0, 0.0), 1.0E-9);
    }

    /**
     * 退化矩阵 ⇒ `inverted()` 返回 `null`（不是恒等、不是抛）。
     *
     * ★ 语义决定命中测试：压成一条线的盒**点不中**。悄悄返回恒等会变成
     *   "点得中但位置错"，那是更难查的错。
     */
    #[Test]
    public function testSingularMatrixHasNoInverse(): void
    {
        $flat = Matrix2D::scaling(1.0, 0.0);

        $this->assertNull($flat->inverted());
        $this->assertFalse($flat->isInvertible());
    }

    #[Test]
    public function testApplyRectTakesAxisAlignedBounds(): void
    {
        $square = new Rect(0.0, 0.0, 10.0, 10.0);

        // 绕中心 45° 旋转的外接框边长 = 10·√2
        $bounds = Matrix2D::rotationAround(45.0, 5.0, 5.0)->applyRect($square);

        $this->assertEqualsWithDelta(10.0 * \sqrt(2.0), $bounds->width, 1.0E-6);
        $this->assertEqualsWithDelta(10.0 * \sqrt(2.0), $bounds->height, 1.0E-6);
        // 中心不动 ⇒ 外接框中心仍是 (5,5)
        $this->assertEqualsWithDelta(5.0, $bounds->left + $bounds->width / 2.0, 1.0E-6);
        $this->assertEqualsWithDelta(5.0, $bounds->top + $bounds->height / 2.0, 1.0E-6);
    }

    /**
     * `isTranslationOnly()` 必须容忍浮点误差。
     *
     * ★ 这是**兼容性闸门**：为真 ⇒ `Canvas` 走"累加 offset"的老路，golden 一个字节
     *   都不动。`rotation(360)` / `scaling(1,1)` 算出来是 `0.9999999999999998` 这类值，
     *   若用 `===` 判断就会把"等价于不平移"的盒推进矩阵路径。
     */
    #[Test]
    public function testTranslationOnlyTolerance(): void
    {
        $this->assertTrue(Matrix2D::translation(5.0, -3.0)->isTranslationOnly());
        $this->assertTrue(Matrix2D::rotation(360.0)->isTranslationOnly());
        $this->assertTrue(Matrix2D::scaling(1.0, 1.0)->isTranslationOnly());

        $this->assertFalse(Matrix2D::scaling(1.0001, 1.0)->isTranslationOnly());
        $this->assertFalse(Matrix2D::rotation(1.0)->isTranslationOnly());
    }

    /**
     * 90° 旋转**不算**轴对齐。
     *
     * ★ 反直觉但必须如此：90° 旋转后矩形仍是矩形（轴交换），但**内容**也跟着转了
     *   （文字的朝向、圆角的位置）。光栅后端的"轴对齐快路"是按"改尺寸"实现的，
     *   放 90° 进去会画出未旋转的内容 ⇒ 宁可在 `isAxisAligned()` 上保守。
     */
    #[Test]
    public function testRightAngleRotationIsNotAxisAligned(): void
    {
        $this->assertTrue(Matrix2D::scaling(2.0, 3.0)->isAxisAligned());
        $this->assertFalse(Matrix2D::rotation(90.0)->isAxisAligned());
    }
}
