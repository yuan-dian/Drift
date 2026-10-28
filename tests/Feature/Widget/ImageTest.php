<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Layout\ImageFit;
use Drift\Layout\RenderImage;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\ImageLoader;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Widget\Image;

/**
 * `RenderImage` 的几何与出墨，以及 `Image` 控件到盒的接法。
 *
 * ★ 两条量具纪律：
 *   ① 几何用**比例关系**推（96×24 只给宽 48 ⇒ 高 12），不拟合像素坐标；
 *   ② Cover 的量具是**命令流**（`DisplayList` 的 OP_DRAW_IMAGE 实参），不是
 *      桥的调用 —— 被测对象产出的是命令，"裁源还是裁目标"在这层就已经定死，
 *      再往下问桥等于替被测对象担保。
 */
final class ImageTest extends SmokeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ImageLoader::clearCache();
    }

    protected function tearDown(): void
    {
        ImageLoader::clearCache();
        parent::tearDown();
    }

    public function testNaturalSizeAndProportionalScaling(): void
    {
        $quad = ImageLoader::load('demo/quad.png');    // 64×64
        $strip = ImageLoader::load('demo/strip.png');  // 96×24

        $natural = new RenderImage($quad, ImageFit::Contain, 0.0, 0.0);
        $natSize = $natural->layout(new BoxConstraints(0.0, INF, 0.0, INF));
        check(
            'Image · 自然尺寸 = 原图像素尺寸（64×64）',
            abs($natSize->width - 64.0) < 0.01 && abs($natSize->height - 64.0) < 0.01,
            sprintf('%.2f×%.2f', $natSize->width, $natSize->height),
        );

        $wOnly = new RenderImage($strip, ImageFit::Contain, 48.0, 0.0);
        $wOnlySize = $wOnly->layout(new BoxConstraints(0.0, INF, 0.0, INF));
        check(
            'Image · 只给宽 48（原图 96×24）⇒ 高按比例 = 12',
            abs($wOnlySize->width - 48.0) < 0.01 && abs($wOnlySize->height - 12.0) < 0.01,
            sprintf('%.2f×%.2f', $wOnlySize->width, $wOnlySize->height),
        );

        // 内禀必须与实排同式：错式（比如内禀只用原图尺寸、忽略显式宽）会让
        // 父容器的 Flex/Table 分到与实排不一致的空间，症状是"量一次和画一次不一样"
        check(
            'Image · 内禀 = 实排（contentIntrinsic* 与 performLayout 同式）',
            abs($wOnly->intrinsicWidth(0.0) - 48.0) < 0.01 && abs($wOnly->intrinsicHeight(0.0) - 12.0) < 0.01,
            sprintf('intr=%.2f×%.2f', $wOnly->intrinsicWidth(0.0), $wOnly->intrinsicHeight(0.0)),
        );
    }

    public function testContainLetterboxesInSquareBox(): void
    {
        // ⑪ 2×1 图进 4×4 盒 ⇒ scale = min(4/2, 4/1) = 2 ⇒ dst = (0,1,4,2)，上下各留 1 行
        $twoByOne = ImageHandle::fromRgba(
            chr(220) . chr(40) . chr(40) . chr(255) . chr(40) . chr(170) . chr(80) . chr(255),
            2,
            1,
        );
        $box = new RenderImage($twoByOne, ImageFit::Contain, 4.0, 4.0);
        $box->layout(new BoxConstraints(4.0, 4.0, 4.0, 4.0));
        $canvas = new RasterCanvas(4, 4, Color::white(), Scale::of(1.0));
        $box->paint($canvas);

        check(
            'Image · Contain 在方盒里 letterbox（上下留白、左右铺满、比例不变形）',
            $canvas->pixelAt(0, 1)->toArgb() === self::argb(220, 40, 40)
                && $canvas->pixelAt(0, 2)->toArgb() === self::argb(220, 40, 40)
                && $canvas->pixelAt(3, 1)->toArgb() === self::argb(40, 170, 80)
                && $canvas->pixelAt(0, 0)->toArgb() === self::argb(255, 255, 255)
                && $canvas->pixelAt(0, 3)->toArgb() === self::argb(255, 255, 255),
            sprintf('(0,0)=%d (0,1)=%d (0,2)=%d (3,1)=%d (0,3)=%d', $canvas->pixelAt(0, 0)->toArgb(), $canvas->pixelAt(0, 1)->toArgb(), $canvas->pixelAt(0, 2)->toArgb(), $canvas->pixelAt(3, 1)->toArgb(), $canvas->pixelAt(0, 3)->toArgb()),
        );
    }

    public function testCoverCropsTheSourceNotTheTarget(): void
    {
        // ⑫ 4×2 图进 2×4 盒 ⇒ 盒更窄 ⇒ 保高裁宽
        //    boxAspect=0.5, imgAspect=2 ⇒ sw = ih*0.5 = 1, sh = ih = 2
        //    ⇒ src=(1.5,0,1,2), dst=(0,0,2,4)
        //    ★ src 非 null 本身就是"裁源"的证据：裁目标的写法不会动 src。
        $fourByTwo = ImageHandle::fromRgba(str_repeat("\x00\x00\x00\xFF", 8), 4, 2);
        $box = new RenderImage($fourByTwo, ImageFit::Cover, 2.0, 4.0);
        $box->layout(new BoxConstraints(2.0, 2.0, 4.0, 4.0));

        $list = new DisplayList();
        $box->paint(new RecordingCanvas($list));

        $src = null;
        $dst = null;
        for ($i = 0; $i < $list->count(); $i++) {
            if ($list->opAt($i) === DisplayList::OP_DRAW_IMAGE) {
                $src = $list->argAt($i, 1);
                $dst = $list->argAt($i, 2);
            }
        }
        check(
            'Image · Cover 用**裁源矩形**（不是裁目标）：src=(1.5,0,1,2) dst=(0,0,2,4)',
            $src instanceof Rect && $dst instanceof Rect
                && abs($src->left - 1.5) < 0.01 && abs($src->top) < 0.01
                && abs($src->width - 1.0) < 0.01 && abs($src->height - 2.0) < 0.01
                && abs($dst->left) < 0.01 && abs($dst->top) < 0.01
                && abs($dst->width - 2.0) < 0.01 && abs($dst->height - 4.0) < 0.01,
            $src === null || $dst === null ? 'no OP_DRAW_IMAGE' : sprintf(
                'src=(%.2f,%.2f,%.2f,%.2f) dst=(%.2f,%.2f,%.2f,%.2f)',
                $src->left, $src->top, $src->width, $src->height,
                $dst->left, $dst->top, $dst->width, $dst->height,
            ),
        );
    }

    public function testWidgetBindsAssetThroughLoader(): void
    {
        // ⑬ 走 createRenderObject，源来自资源路径；只给宽按比例
        $box = Image::make()->asset('demo/strip.png')->width(48.0)->fit(ImageFit::Contain)
            ->createRenderObject(smokeContext());
        $size = $box->layout(new BoxConstraints(0.0, INF, 0.0, INF));
        check(
            'Image 控件 · asset() 走 ImageLoader，只给宽 48 ⇒ 48×12',
            abs($size->width - 48.0) < 0.01 && abs($size->height - 12.0) < 0.01,
            sprintf('%.2f×%.2f', $size->width, $size->height),
        );
    }

    public function testMissingAssetFallsBackToTransparentPlaceholder(): void
    {
        // ⑭ 资源不存在 ⇒ 退回 1×1 透明占位（不炸、也不静默空白报错）
        $box = Image::make()->asset('demo/no-such-image.png')->createRenderObject(smokeContext());
        $size = $box->layout(new BoxConstraints(0.0, INF, 0.0, INF));
        $canvas = new RasterCanvas(2, 2, Color::white(), Scale::of(1.0));
        $box->paint($canvas);
        check(
            'Image 控件 · 缺图 ⇒ 1×1 透明占位（尺寸退化 + 画不出墨，诊断已留痕）',
            abs($size->width - 1.0) < 0.01 && abs($size->height - 1.0) < 0.01
                && $canvas->pixelAt(0, 0)->toArgb() === self::argb(255, 255, 255),
            sprintf('%.2f×%.2f', $size->width, $size->height),
        );
    }

    public function testInMemoryHandleSkipsFileSystem(): void
    {
        // ⑮ image() 内存句柄路径（不碰文件系统）
        $quad = ImageLoader::load('demo/quad.png');
        $box = Image::make()->image($quad)->width(32.0)->height(32.0)->fit(ImageFit::Fill)
            ->createRenderObject(smokeContext());
        $size = $box->layout(new BoxConstraints(0.0, INF, 0.0, INF));
        $canvas = new RasterCanvas(32, 32, Color::white(), Scale::of(1.0));
        $box->paint($canvas);
        check(
            'Image 控件 · image() 内存句柄（32×32 Fill）⇒ 左上红、右上绿、右下黄',
            abs($size->width - 32.0) < 0.01 && abs($size->height - 32.0) < 0.01
                && $canvas->pixelAt(4, 4)->toArgb() === self::argb(220, 40, 40)
                && $canvas->pixelAt(28, 4)->toArgb() === self::argb(40, 170, 80)
                && $canvas->pixelAt(28, 28)->toArgb() === self::argb(235, 190, 40),
            sprintf('%.2f×%.2f (4,4)=%d (28,4)=%d (28,28)=%d', $size->width, $size->height, $canvas->pixelAt(4, 4)->toArgb(), $canvas->pixelAt(28, 4)->toArgb(), $canvas->pixelAt(28, 28)->toArgb()),
        );
    }

    private static function argb(int $r, int $g, int $b, int $a = 255): int
    {
        return ($a << 24) | ($r << 16) | ($g << 8) | $b;
    }
}
