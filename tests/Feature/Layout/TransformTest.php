<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Layout;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Matrix2D;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Widget\SizedBox;
use Drift\Widget\Widget;

/**
 * A1c（2026-10-29）：绘制期仿射变换（`->rotate()` / `->scale()` / `->transform()`）。
 *
 * ## 三条裁决，每条一个判据
 *
 * | # | 裁决 | 判据 |
 * |---|---|---|
 * | ① | 变换**不参与布局**（子照原约束、盒尺寸不变） | `testTransformDoesNotChangeLayout` |
 * | ② | **命中测试跟着变换**（画在哪就点哪） | `testHitTestFollowsTransform`（双向） |
 * | ③ | 退化矩阵**点不中**，不是"点得中但位置错" | `testDegenerateTransformNeverHits` |
 *
 * ★ ① 是"不破坏既有布局"的全部保证：变换只是一条绘制期命令，`performLayout()`
 *   一个字节都没改。代价是视觉溢出（放大 2 倍会盖到邻居），这是标准语义。
 *
 * ★ ② 的判据必须是**双向**的：只测"变换后位置命中"会让"永远命中/变换被忽略"
 *   也通过（`hitTest` 不反变换时，点原位反而命中 ⇒ 两条一起才逼得出正确实现）。
 *
 * ★ 为什么放 Feature 而不是 Unit：判据是"布局 + 命中 + 合成槽"三个子系统一起
 *   的结果，单独测任何一层都测不出"画在 A、点得中 B"。
 */
final class TransformTest extends SmokeTestCase
{
    private static function box(Widget $widget): RenderBox
    {
        $element = $widget->createElement();
        $element->mount(null, smokeContext());
        $box = $element->findBoxOf(RenderBox::class);
        if ($box === null) {
            throw new \RuntimeException('控件没有挂出 RenderBox');
        }
        $box->layout(BoxConstraints::loose(new Size(400.0, 400.0)));

        return $box;
    }

    /**
     * 变换不动布局。
     *
     * ★ 判据落在**两个数**上：本盒尺寸、以及"有没有声明变换"。只比尺寸不够 ——
     *   一个把矩阵折进 `width` 的实现也能让尺寸相同（那是对的实现之一），
     *   所以真正的判据是"尺寸相同 **且** 变换字段确实被记下了"。
     */
    public function testTransformDoesNotChangeLayout(): void
    {
        $plain = self::box(SizedBox::make()->size(100.0, 60.0));
        $turned = self::box(SizedBox::make()->size(100.0, 60.0)->rotate(30.0));

        check(
            'Transform · 声明变换后盒尺寸不变（100×60）',
            $turned->size()?->width === 100.0 && $turned->size()?->height === 60.0,
            'got=' . self::describe($turned->size()),
        );
        check(
            'Transform · 与不声明时逐字段相同',
            $turned->size()?->width === $plain->size()?->width
                && $turned->size()?->height === $plain->size()?->height,
        );
        check(
            'Transform · 变换确实被记下（否则上一条可以靠"完全没生效"通过）',
            $turned->hasComposeTransform() && !$plain->hasComposeTransform(),
        );

        $this->flushChecks();
    }

    /**
     * 命中跟着变换走（**双向**）。
     *
     * ★ 用平移而不是缩放：缩放 2 倍的视觉范围**包含**原位，测不出反例；
     *   平移 50 之后"视觉在 50..150、原位 0..100 里 0..50 那段已经空了"，
     *   两个方向都有话说。
     */
    public function testHitTestFollowsTransform(): void
    {
        $box = self::box(
            SizedBox::make()->size(100.0, 60.0)->transform(Matrix2D::translation(50.0, 0.0))
        );

        check(
            'Transform · 变换后可见位置命中（(110,30) 落在 50..150 里）',
            $box->hitTest(110.0, 30.0) !== null,
        );
        check(
            'Transform · 原位不命中（(10,30) 视觉上已经被移走了）',
            $box->hitTest(10.0, 30.0) === null,
        );
    }

    /**
     * 绕中心缩放：**中心点不动**。
     *
     * ★ 这是"`aroundCenter` 的意图有没有真的落到 `T(c)·M·T(-c)`"的唯一判据 ——
     *   少了两个 `T`，中心会跟着跑，而画面看起来只是"偏了一点"。
     */
    public function testScaleAroundCenterKeepsCenterFixed(): void
    {
        $box = self::box(SizedBox::make()->size(100.0, 60.0)->scale(2.0));
        $m = $box->effectiveTransform();

        check('Transform · scale(2) 生效', $m !== null);
        if ($m === null) {
            $this->flushChecks();

            return;
        }

        check(
            'Transform · 绕中心缩放：中心 (50,30) 不动',
            \abs($m->applyX(50.0, 30.0) - 50.0) < 1.0E-6
                && \abs($m->applyY(50.0, 30.0) - 30.0) < 1.0E-6,
            \sprintf('got=(%.3f,%.3f)', $m->applyX(50.0, 30.0), $m->applyY(50.0, 30.0)),
        );
        check(
            'Transform · 缩放倍数真的是 2（左上角 (0,0) ⇒ (-50,-30)）',
            \abs($m->applyX(0.0, 0.0) + 50.0) < 1.0E-6
                && \abs($m->applyY(0.0, 0.0) + 30.0) < 1.0E-6,
            \sprintf('got=(%.3f,%.3f)', $m->applyX(0.0, 0.0), $m->applyY(0.0, 0.0)),
        );
    }

    /**
     * 退化矩阵（压成一条线）：**点不中**。
     *
     * ★ 与"返回恒等"的区别不是细节：返回恒等 ⇒ 点在原处命中，而那个盒在视觉上
     *   根本不存在；用户点到了一个看不见的东西，是比"点不中"严重得多的错。
     */
    public function testDegenerateTransformNeverHits(): void
    {
        $box = self::box(
            SizedBox::make()->size(100.0, 60.0)->transform(Matrix2D::scaling(1.0, 0.0))
        );

        check('Transform · 退化矩阵：盒内任何点都不命中', $box->hitTest(50.0, 30.0) === null);
    }

    /**
     * 不声明变换 ⇒ 合成槽里没有矩阵（快路）。
     *
     * ★ 这条挡的是"为了统一而给每个盒都塞一个 identity 矩阵"的实现 ——
     *   那会让 `paintComposed()` 永远走 `save/transform/restore` 三条命令，
     *   golden 全变、滚动帧白花。
     */
    public function testIdentityTransformIsNotStored(): void
    {
        $box = self::box(
            SizedBox::make()->size(100.0, 60.0)->transform(Matrix2D::identity())
        );

        check('Transform · identity 不被当成声明（isDefault 仍为真）', !$box->hasComposeTransform());
    }

    private static function describe(?Size $size): string
    {
        if ($size === null) {
            return 'null';
        }

        return \sprintf('%.1f×%.1f', $size->width, $size->height);
    }
}
