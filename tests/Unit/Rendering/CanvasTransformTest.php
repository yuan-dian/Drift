<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Paint;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;

/**
 * `Canvas::transform()` 的三后端口径（A1c）。
 *
 * ## 判据分两块，各测各的
 *
 * | 块 | 判据 | 为什么不能互相替代 |
 * |---|---|---|
 * | 录制 `RecordingCanvas` | 纯平移**降级**成 `OP_TRANSLATE`，真变换才录 `OP_TRANSFORM` | 这是"平移路径零成本"的结构保证，像素看不出来 |
 * | 光栅 `RasterCanvas` | 变换后的**落点**与公式推出来的一致 | 命令录对了不代表画对了（`toDevice` 漏一行就全偏） |
 *
 * ★ 像素判据全部**按公式推**：`scaling(2,2)` 把 `(10,10,20×10)` 变成
 *   `(20,20,40×20)`，每个断言点都能手算，不做"看哪里像"的拟合。
 *
 * ★ 为什么 `RasterCanvas` 的旋转只测"不崩、是 AABB"而不测精确像素：软光栅没有
 *   字形的仿射能力，矩形旋转降级为**外接框**（类注释里写明的取舍）。精确的旋转
 *   像素只有真机 Skia 有 —— 那条判据在 `TransformTest`（真机取证记录）里。
 */
final class CanvasTransformTest extends SmokeTestCase
{
    public function testRecordingDowngradesPureTranslation(): void
    {
        $list = new DisplayList();
        $canvas = new RecordingCanvas($list);

        $canvas->transform(Matrix2D::translation(7.0, -3.0));
        $canvas->transform(Matrix2D::rotation(15.0));
        $canvas->translate(1.0, 2.0);

        check(
            'Transform · 纯平移录成 OP_TRANSLATE（走老路，像素零变化）',
            $list->opAt(0) === DisplayList::OP_TRANSLATE,
            'op=' . $list->opAt(0),
        );
        check(
            'Transform · 平移的两个数原样进槽',
            $list->argAt(0, 0) === 7.0 && $list->argAt(0, 1) === -3.0,
        );
        check(
            'Transform · 真变换录成 OP_TRANSFORM（矩阵走引用槽）',
            $list->opAt(1) === DisplayList::OP_TRANSFORM && $list->argAt(1, 0) instanceof Matrix2D,
            'op=' . $list->opAt(1),
        );
        check(
            'Transform · 原有的 translate() 不受影响（仍是第三条命令）',
            $list->opAt(2) === DisplayList::OP_TRANSLATE && $list->count() === 3,
            'count=' . $list->count(),
        );
    }

    public function testRasterAppliesScale(): void
    {
        $canvas = new RasterCanvas(120, 120, Color::white(), Scale::of(1.0));
        $canvas->transform(Matrix2D::scaling(2.0, 2.0));
        $canvas->drawRect(new Rect(10.0, 10.0, 20.0, 10.0), Paint::fill(Color::fromRgb(255, 0, 0)));

        // (10,10,20×10) 放大 2 倍 ⇒ (20,20,40×20)
        check('Transform · 缩放后落在 (25,25)', $canvas->pixelAt(25, 25)->toArgb() === self::red());
        check('Transform · 缩放后落在 (55,35)（右下角内侧）', $canvas->pixelAt(55, 35)->toArgb() === self::red());
        check('Transform · 原位置 (15,15) 已经空了', $canvas->pixelAt(15, 15)->toArgb() === self::white());
        check('Transform · 缩放后外沿 (65,45) 仍然白', $canvas->pixelAt(65, 45)->toArgb() === self::white());
    }

    public function testRasterRestoresTransformOnRestore(): void
    {
        $canvas = new RasterCanvas(120, 120, Color::white(), Scale::of(1.0));
        $canvas->drawRect(new Rect(0.0, 0.0, 6.0, 6.0), Paint::fill(Color::fromRgb(255, 0, 0)));

        $canvas->save();
        $canvas->transform(Matrix2D::translation(40.0, 40.0));
        $canvas->drawRect(new Rect(0.0, 0.0, 6.0, 6.0), Paint::fill(Color::fromRgb(255, 0, 0)));
        $canvas->restore();

        $canvas->drawRect(new Rect(0.0, 10.0, 6.0, 6.0), Paint::fill(Color::fromRgb(255, 0, 0)));

        check('Transform · 变换内画在 (42,42)', $canvas->pixelAt(42, 42)->toArgb() === self::red());
        check('Transform · 变换外仍画在原点附近 (2,2)', $canvas->pixelAt(2, 2)->toArgb() === self::red());
        check(
            'Transform · restore 之后不再带偏移（(2,12) 有、(42,12) 没有）',
            $canvas->pixelAt(2, 12)->toArgb() === self::red()
                && $canvas->pixelAt(42, 12)->toArgb() === self::white(),
        );
    }

    /**
     * 矩阵模式下 `translate()` 必须**折进矩阵**（`CTM · T`），不能累加偏移。
     *
     * ★ 这是本轮最容易写错的一条：`rotate(90); translate(10,0)` 的正确落点是
     *   `R(p + (10,0))`，而"累加 offset"的实现给出 `R(p) + (10,0)` —— 两者只在
     *   旋转为 0 时相同，所以不写这条判据就永远发现不了。
     */
    public function testTranslateAfterTransformComposesInside(): void
    {
        $canvas = new RasterCanvas(120, 120, Color::white(), Scale::of(1.0));
        $canvas->transform(Matrix2D::rotation(90.0));
        $canvas->translate(10.0, 0.0);

        // 局部 (0,0,4×4) 先平移 ⇒ (10,0,4×4)，再旋转 90° 绕原点 ⇒ x∈[-4,0), y∈[10,14)
        // ⇒ 这块画不出来（x<0）。换一个能落进画布的点来判：局部 (10,-40) 那块。
        $canvas->drawRect(new Rect(10.0, -40.0, 4.0, 4.0), Paint::fill(Color::fromRgb(255, 0, 0)));

        // 局部矩形先 +(10,0) ⇒ (20,-40,4×4)；旋转 90° ⇒ (x,y)=(0,20) 到 (40,24) 区域旋转
        // 手算：四角 (20,-40),(24,-40),(24,-36),(20,-36) ⇒ R90 ⇒ (40,20),(40,24),(36,24),(36,20)
        check(
            'Transform · rotate 之后再 translate 是「先平移再旋转」（落点 (38,22)）',
            $canvas->pixelAt(38, 22)->toArgb() === self::red(),
            'argb=' . \dechex($canvas->pixelAt(38, 22)->toArgb()),
        );
        check(
            'Transform · 反向位置（只旋转不平移会落在 (22,42) 附近）是空的',
            $canvas->pixelAt(22, 42)->toArgb() === self::white(),
            'argb=' . \dechex($canvas->pixelAt(22, 42)->toArgb()),
        );
    }

    public function testDegenerateTransformCollapses(): void
    {
        $canvas = new RasterCanvas(60, 60, Color::white(), Scale::of(1.0));
        $canvas->transform(Matrix2D::scaling(1.0, 0.0));
        $canvas->drawRect(new Rect(5.0, 5.0, 20.0, 20.0), Paint::fill(Color::fromRgb(255, 0, 0)));

        // 高被压成 0 ⇒ 一个像素都不该亮（"压成一条线"在像素网格上就是没有面积）
        $ink = 0;
        for ($y = 0; $y < 60; ++$y) {
            for ($x = 0; $x < 60; ++$x) {
                if ($canvas->pixelAt($x, $y)->toArgb() === self::red()) {
                    ++$ink;
                }
            }
        }

        check('Transform · 退化矩阵压成零高 ⇒ 零像素', $ink === 0, 'ink=' . $ink);
    }

    private static function red(): int
    {
        return Color::fromRgb(255, 0, 0)->toArgb();
    }

    private static function white(): int
    {
        return Color::white()->toArgb();
    }
}
