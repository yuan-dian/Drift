<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderSpinner;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Widget\Element;
use Drift\Widget\Spinner;
use Drift\Widget\SpinnerState;

/**
 * Spinner（§11.5 三态 Loading 分支，2026-10-16 落地）。
 *
 * 三件事：**盒怎么定**（自然直径 / 紧约束两向）、**墨怎么画**（不拉伸、8 档渐隐、纯函数）、
 * **相位怎么走**（tick 只标脏 ⇒ 帧末才重建 ⇒ 墨变）。
 *
 * ★ 像素断言的画布是 200×200（不是共享 `inkBounds()` 固定的 320×240）⇒ 本文件自己扫，
 *   谓词与原段一致：非白即墨。共享的 `inkCount()` 只数点数，给不出"墨在哪儿"。
 * ★ "墨迹不拉伸"是图标语义：盒被父定死 200×200 时，点阵仍只有 ≈40×40 且居中。
 *   反例是把直径当盒尺寸画满 —— 那就成了一个会转的方块。
 */
final class SpinnerTest extends SmokeTestCase
{
    private function mountSpinner(float $diameter): Element
    {
        $root = Spinner::make()->diameter($diameter)->createElement();
        $root->mount(null, smokeContext());

        return $root;
    }

    /**
     * 一次扫描拿到全部墨迹统计（原段分两趟扫，谓词与口径完全一致）。
     *
     * @return array{ink: int, minX: int, maxX: int, minY: int, maxY: int, shades: int}
     */
    private function inkStats(RasterCanvas $canvas): array
    {
        $ink = 0;
        $minX = PHP_INT_MAX;
        $maxX = -1;
        $minY = PHP_INT_MAX;
        $maxY = -1;
        $shades = [];
        for ($y = 0; $y < $canvas->height(); $y++) {
            for ($x = 0; $x < $canvas->width(); $x++) {
                $pixel = $canvas->pixelAt($x, $y);
                if (!$pixel->equals(Color::white())) {
                    $ink++;
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                    $shades[$pixel->toArgb()] = true;
                }
            }
        }

        return [
            'ink' => $ink,
            'minX' => $minX,
            'maxX' => $maxX,
            'minY' => $minY,
            'maxY' => $maxY,
            'shades' => count($shades),
        ];
    }

    // ① 盒：挂载即有盒、宽松拿自然直径、内禀 = 直径、紧约束两向
    public function testBoxGeometry(): void
    {
        $root = $this->mountSpinner(40.0);
        $box = $root->effectiveBox();
        check(
            'Spinner · 挂载即建出渲染盒（State 直接产盒 → RenderSpinner）',
            $box instanceof RenderSpinner,
        );
        $box?->layout(BoxConstraints::loose(new Size(200.0, 200.0)));
        check(
            'Spinner · 宽松约束 ⇒ 拿自然直径（40×40）',
            $box?->size()?->width === 40.0 && $box?->size()?->height === 40.0,
        );
        check(
            'Spinner · 内禀尺寸 = 直径（另一轴不限）',
            $box?->intrinsicWidth(INF) === 40.0 && $box?->intrinsicHeight(INF) === 40.0,
        );

        // 约束比直径小 ⇒ 缩小而不报 C6（装饰性控件；C6 只罚"画到盒外"）
        $tight = $this->mountSpinner(40.0);
        $tight->effectiveBox()?->layout(BoxConstraints::tight(new Size(16.0, 16.0)));
        check(
            'Spinner · 紧约束(16) < 直径(40) ⇒ 缩到约束内（C6 不响）',
            $tight->effectiveBox()?->size()?->width === 16.0,
        );

        // 紧约束比直径大 ⇒ 盒被父定死（C6 下界提升，框架统一行为），但墨迹不拉伸
        $fill = $this->mountSpinner(40.0);
        $fillBox = $fill->effectiveBox();
        $fillBox?->layout(BoxConstraints::tight(new Size(200.0, 200.0)));
        check(
            'Spinner · 紧约束(200) > 直径(40) ⇒ 盒被父定死 200×200',
            $fillBox?->size()?->width === 200.0,
        );
        $s = $this->inkStats(spinCanvas($fillBox, 200, 200));
        check(
            'Spinner · 授予盒 200×200 但墨迹不拉伸（≈40×40 且居中 —— 图标语义）',
            $s['ink'] > 0
                && ($s['maxX'] - $s['minX'] + 1) <= 42 && ($s['maxY'] - $s['minY'] + 1) <= 42
                && abs($s['minX'] + $s['maxX'] - 199) <= 4 && abs($s['minY'] + $s['maxY'] - 199) <= 4,
            sprintf(
                'ink=%d bounds=%d×%d center=(%d,%d)',
                $s['ink'],
                $s['maxX'] - $s['minX'] + 1,
                $s['maxY'] - $s['minY'] + 1,
                (int) (($s['minX'] + $s['maxX']) / 2),
                (int) (($s['minY'] + $s['maxY']) / 2)
            ),
        );
    }

    // ② 墨：同相位两次同指纹（§14.1 纯函数）；8 个点 8 档渐隐（不是 8 个实心点）
    public function testDeterministicRenderAndShades(): void
    {
        $root = $this->mountSpinner(40.0);
        $box = $root->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(200.0, 200.0)));

        $sigA = md5(spinCanvas($box, 200, 200)->toRgba());
        $sigB = md5(spinCanvas($box, 200, 200)->toRgba());
        check('Spinner · 同相位渲染两次指纹一致（§14.1 纯函数）', $sigA === $sigB);

        $s = $this->inkStats(spinCanvas($box, 200, 200));
        check('Spinner · 8 个点都有墨（ink > 0）', $s['ink'] > 0, 'ink=' . $s['ink']);
        check(
            'Spinner · 8 档渐隐 = 8 种颜色（不是 8 个同样实心的点）',
            $s['shades'] === 8,
            'shades=' . $s['shades'],
        );
    }

    // ③ 相位：tick 只标脏 ⇒ 帧末重建 ⇒ 换新盒、相位 +1、墨变
    public function testTickAndFrameRebuild(): void
    {
        $root = $this->mountSpinner(40.0);
        $box = $root->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(200.0, 200.0)));
        $sigA = md5(spinCanvas($box, 200, 200)->toRgba());

        $state = $root->state();
        check('Spinner · State 只建一次（初相位 0）', $state instanceof SpinnerState && $state->step() === 0);

        $state?->tick();
        check(
            'Spinner · tick 只标脏、不立即重建（§5.2 裁决 3）',
            $state?->needsBuild() === true,
        );

        $surface = new SurfaceProbe();
        $surface->resize(200, 200, 1.0);
        $frame = new Frame($surface);
        $frame->run($root, BoxConstraints::tight(new Size(200.0, 200.0)), 16.0);
        $box2 = $root->effectiveBox();
        check(
            'Spinner · 帧末重建后换成新盒',
            $box2 !== $box && $box2 instanceof RenderSpinner,
        );
        check('Spinner · 相位前进一步（step 0 → 1）', $state instanceof SpinnerState && $state->step() === 1);
        check(
            'Spinner · 相位变 ⇒ 墨迹变（动画真的在走）',
            md5(spinCanvas($box2, 200, 200)->toRgba()) !== $sigA,
        );
    }
}
