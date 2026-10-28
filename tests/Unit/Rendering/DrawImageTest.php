<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;

/**
 * `RasterCanvas::drawImage` —— 最近邻 blit 的**像素判据**。
 *
 * ★ 判据全部按公式推，不拟合。每个数都能手算出来：整数放大就是"源像素铺成
 *   N×N 同色块"，半透明就是 `blend()` 那一式。
 * ★ 这一层不需要真 Skia：blit 是纯光栅运算，`RasterCanvas` 自己就是权威。
 *   "与 Skia 逐字节一致"由 1:1 那一档承担（整图原样拷贝时两边不可能不同）。
 */
final class DrawImageTest extends SmokeTestCase
{
    public function testOneToOneBlitAndSourceCrop(): void
    {
        // ① 1:1 整图：逐像素与源 RGBA 相同，且盒外不动
        $canvas = new RasterCanvas(4, 4, Color::white(), Scale::of(1.0));
        $canvas->drawImage($this->quad(), null, new Rect(0.0, 0.0, 2.0, 2.0));

        $got = [];
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1], [2, 2]] as [$x, $y]) {
            $got[] = $canvas->pixelAt($x, $y)->toArgb();
        }
        check(
            'Image · 1:1 整图 blit 逐像素 = 源 RGBA（含"盒外不动"）',
            $got === [
                self::argb(255, 0, 0), self::argb(0, 255, 0), self::argb(0, 0, 255), self::argb(255, 255, 0),
                self::argb(255, 255, 255),
            ],
            'got=' . implode(',', $got),
        );

        // ② 源矩形裁剪：只取 (1,0) 那颗绿像素，落到 (2,2)
        $cropped = new RasterCanvas(4, 4, Color::white(), Scale::of(1.0));
        $cropped->drawImage($this->quad(), new Rect(1.0, 0.0, 1.0, 1.0), new Rect(2.0, 2.0, 1.0, 1.0));
        check(
            'Image · 源矩形裁剪：src(1,0,1,1) ⇒ 只有 (2,2) 变绿，别处不动',
            $cropped->pixelAt(2, 2)->toArgb() === self::argb(0, 255, 0)
                && $cropped->pixelAt(1, 1)->toArgb() === self::argb(255, 255, 255)
                && $cropped->pixelAt(0, 0)->toArgb() === self::argb(255, 255, 255),
            sprintf('p22=%d p11=%d p00=%d', $cropped->pixelAt(2, 2)->toArgb(), $cropped->pixelAt(1, 1)->toArgb(), $cropped->pixelAt(0, 0)->toArgb()),
        );
    }

    public function testIntegerUpscaleStaysNearestNeighbour(): void
    {
        // ③ 整数放大 2×：2×2 → 4×4，每个源像素铺 2×2 同色块
        //    ★ 量的是 R 通道 = 源块 R，四颗源色各不相同 ⇒ 采样点错一格立刻红
        $quad = $this->quad();
        $canvas = new RasterCanvas(4, 4, Color::white(), Scale::of(1.0));
        $canvas->drawImage($quad, null, new Rect(0.0, 0.0, 4.0, 4.0));

        $ok = true;
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $want = $quad->rgba()[((intdiv($y, 2) * 2) + intdiv($x, 2)) * 4];
                if (ord($want) !== $canvas->pixelAt($x, $y)->r) {
                    $ok = false;
                }
            }
        }
        check(
            'Image · 整数放大 2×：每个源像素铺成 2×2 同色块（最近邻的可判定形态）',
            $ok,
            '见逐像素核对（R 通道 = 源块 R）',
        );
    }

    public function testOutOfBoundsClampAndClip(): void
    {
        // ④ 越界夹紧：dst 左上角在画布外 (-1,-1)，只有右下那颗黄像素可见 ⇒ (0,0) 黄
        $canvas = new RasterCanvas(2, 2, Color::white(), Scale::of(1.0));
        $canvas->drawImage($this->quad(), null, new Rect(-1.0, -1.0, 2.0, 2.0));
        check(
            'Image · 越界夹紧：dst(-1,-1,2,2) ⇒ 只有源右下角可见于 (0,0)，不炸不越界',
            $canvas->pixelAt(0, 0)->toArgb() === self::argb(255, 255, 0)
                && $canvas->pixelAt(1, 1)->toArgb() === self::argb(255, 255, 255),
            sprintf('p00=%d p11=%d', $canvas->pixelAt(0, 0)->toArgb(), $canvas->pixelAt(1, 1)->toArgb()),
        );

        // ⑦ 矩形裁剪生效：裁到 (0,0,1,1) 后画 2×2 ⇒ 只有 (0,0) 变红
        $clipped = new RasterCanvas(3, 3, Color::white(), Scale::of(1.0));
        $clipped->pushClip(new Rect(0.0, 0.0, 1.0, 1.0));
        $clipped->drawImage($this->quad(), null, new Rect(0.0, 0.0, 2.0, 2.0));
        $clipped->popClip();
        check(
            'Image · 矩形裁剪生效：clip(1×1) 内只画 (0,0)，(1,0)/(0,1) 保持白',
            $clipped->pixelAt(0, 0)->toArgb() === self::argb(255, 0, 0)
                && $clipped->pixelAt(1, 0)->toArgb() === self::argb(255, 255, 255)
                && $clipped->pixelAt(0, 1)->toArgb() === self::argb(255, 255, 255),
            sprintf('p00=%d p10=%d p01=%d', $clipped->pixelAt(0, 0)->toArgb(), $clipped->pixelAt(1, 0)->toArgb(), $clipped->pixelAt(0, 1)->toArgb()),
        );
    }

    public function testHalfTransparentSourceOverMatchesBlend(): void
    {
        // ⑤ 半透明：源 (0,0,0,128) 压白底 ⇒ 每通道 = round((0*128 + 255*127)/255)
        $half = ImageHandle::fromRgba(chr(0) . chr(0) . chr(0) . chr(128), 1, 1);
        $canvas = new RasterCanvas(2, 2, Color::white(), Scale::of(1.0));
        $canvas->drawImage($half, null, new Rect(0.0, 0.0, 1.0, 1.0));

        $want = (int) round((0 * 128 + 255 * 127) / 255);
        $got = $canvas->pixelAt(0, 0);
        check(
            'Image · 半透明 source-over = blend() 同式（解析值 round((0*128+255*127)/255)）',
            $got->r === $want && $got->g === $want && $got->b === $want && $got->a === 255,
            sprintf('got=(%d,%d,%d,%d) want=%d', $got->r, $got->g, $got->b, $got->a, $want),
        );
    }

    public function testRecordingReplayMatchesDirectCall(): void
    {
        // ⑥ 命令流 replay 与直接调用逐字节相同（OP_DRAW_IMAGE 回放路径，RenderImage 走这条）
        $direct = new RasterCanvas(6, 6, Color::white(), Scale::of(1.0));
        $direct->drawImage($this->quad(), null, new Rect(1.0, 1.0, 2.0, 2.0));

        $list = new DisplayList();
        (new RecordingCanvas($list))->drawImage($this->quad(), null, new Rect(1.0, 1.0, 2.0, 2.0));
        $replay = new RasterCanvas(6, 6, Color::white(), Scale::of(1.0));
        $list->replay($replay);

        check(
            'Image · 命令流 replay 与直接调用逐字节相同（录制/回放路径等价）',
            $replay->toRgba() === $direct->toRgba(),
            'md5 direct=' . md5($direct->toRgba()) . ' replay=' . md5($replay->toRgba()),
        );
    }

    public function testDegenerateInputsLeaveEveryPixelUntouched(): void
    {
        // ⑧ 反例：全透明图 / 源全在界外 / 0 面积目标 ⇒ 不炸、不改一个像素
        //    ★ 不用 `ImageHandle::fromRgba('', 0, 0)`：构造器对 width<=0 会 Errors::report
        //      （那条是防"图坏了静默错位"的响亮失败，不是给正常调用用的）。
        $quad = $this->quad();
        $canvas = new RasterCanvas(2, 2, Color::white(), Scale::of(1.0));
        $before = $canvas->toRgba();
        $canvas->drawImage(ImageHandle::fromRgba(chr(0) . chr(0) . chr(0) . chr(0), 1, 1), null, new Rect(0.0, 0.0, 1.0, 1.0));
        $canvas->drawImage($quad, new Rect(5.0, 5.0, 1.0, 1.0), new Rect(0.0, 0.0, 1.0, 1.0));
        $canvas->drawImage($quad, null, new Rect(0.0, 0.0, 0.0, 0.0));
        check(
            'Image · 反例：全透明图 / 源全在界外 / 目标 0 面积 ⇒ 一个像素都不改',
            $canvas->toRgba() === $before,
            'md5=' . md5($canvas->toRgba()),
        );
    }

    private static function argb(int $r, int $g, int $b, int $a = 255): int
    {
        return ($a << 24) | ($r << 16) | ($g << 8) | $b;
    }

    /** 2×2：左上红 / 右上绿 / 左下蓝 / 右下黄（全不透明）—— 四色互异，错格必红 */
    private function quad(): ImageHandle
    {
        return ImageHandle::fromRgba(
            chr(255) . chr(0) . chr(0) . chr(255) . chr(0) . chr(255) . chr(0) . chr(255)
                . chr(0) . chr(0) . chr(255) . chr(255) . chr(255) . chr(255) . chr(0) . chr(255),
            2,
            2,
        );
    }
}
