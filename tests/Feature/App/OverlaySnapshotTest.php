<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\App\FocusRingApplication;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RenderSurface;
use Drift\Widget\Element;

/**
 * 快照必须是"应用长什么样"，不是"应用 + 调试器刚才点了谁"（A81）。
 *
 * 三条各自能咬红的坏法：
 *
 * | 方法 | 坏法 |
 * |---|---|
 * | `testSnapshotExcludesDebugHighlight` | 高亮画在拍照之前 ⇒ 底图带着上一秒的选中 |
 * | `testHighlightStillReachesTheScreen` | 把高亮挪到拍照之后顺手**删掉** ⇒ 分离变成"没画" |
 * | `testResizeRepaintDoesNotCapture` | 拖动重排沿用这条出口 ⇒ 拍到上一帧帧号的图，底图与线框错配 |
 *
 * ★ 第二条不能省。只验"两次快照相同"会奖励"根本不画高亮"——那是把 bug 修成另一个
 *   bug。判定必须成对：**底图不变 + 屏幕变了**。
 * ★ 用真 `RasterCanvas` 而不是命令表：这里要回答的是"底图那张 PNG 里有没有那圈描边"，
 *   命令流换个顺序看不出来。160×96 的像素账可以忽略。
 */
final class OverlaySnapshotTest extends SmokeTestCase
{
    private const W = 160;

    private const H = 96;

    public function testSnapshotExcludesDebugHighlight(): void
    {
        [$app, $surface, $shots] = $this->open();

        check('首帧拍了一张', \count($shots) === 1, 'count=' . \count($shots));

        $target = $this->firstSizedElement($app);
        check('找得到高亮目标', $target !== null);
        if ($target === null) {
            return;
        }

        $app->setDebugHighlight($target);
        $app->frame(16.0, self::constraints());

        check('高亮帧也拍了', \count($shots) === 2, 'count=' . \count($shots));
        check(
            '底图不含调试装饰：开高亮前后逐字节相同',
            ($shots[1] ?? '') === ($shots[0] ?? ''),
            '开高亮后底图变了 —— 快照拍在了调试叠加之后',
        );
    }

    public function testHighlightStillReachesTheScreen(): void
    {
        [$app, $surface, $shots] = $this->open();

        $target = $this->firstSizedElement($app);
        check('找得到高亮目标', $target !== null);
        if ($target === null) {
            return;
        }

        $app->setDebugHighlight($target);
        $app->frame(16.0, self::constraints());

        check(
            '高亮真的上屏了：最终像素与快照不同',
            $surface->raster()?->toRgba() !== ($shots[1] ?? ''),
            '像素没变 ⇒ 高亮根本没画（分离写成了删除）',
        );
    }

    public function testResizeRepaintDoesNotCapture(): void
    {
        [$app, $surface, $shots] = $this->open();
        $before = \count($shots);
        $framesBefore = $surface->frames;

        // 换一个尺寸才有重排可跑：同一约束下 `Frame::run()` 自己会跳过整帧，
        // 那样"没拍照"是恒真的，判据等于没写。
        $app->repaintForResize(BoxConstraints::tight(new Size(self::W + 40.0, self::H + 24.0)));

        check(
            '拖动重排真的重画了',
            $surface->frames === $framesBefore + 1,
            "frames={$surface->frames} before={$framesBefore}",
        );
        check(
            '拖动重排不拍照',
            \count($shots) === $before,
            'count=' . \count($shots) . " before={$before}",
        );
    }

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size((float) self::W, (float) self::H));
    }

    /** @return array{0: Application, 1: PixelSurface, 2: \ArrayObject<int, string>} */
    private function open(): array
    {
        $app = new FocusRingApplication();
        $surface = new PixelSurface(self::W, self::H);
        $app->attach($surface, self::constraints());

        // 用对象而不是数组：数组按值返回，闭包捕获的那份在返回后就和调用方分家了
        // （第一版就是这么静默记了 0 张）。
        $shots = new \ArrayObject();
        $app->setFrameObserver(function (Canvas $canvas, int $frame) use ($shots): void {
            $shots[] = $canvas->toRgba();
        });

        // `attach()` 内部已经跑过首帧（那时观察者还不存在）⇒ 树已静止，再调 `frame()`
        // 会被整帧跳过。主动要一帧，等价于画面通道补第一张图的做法。
        $app->requestPaint();
        $app->frame(0.0, self::constraints());

        return [$app, $surface, $shots];
    }

    private function firstSizedElement(Application $app): ?Element
    {
        $found = null;
        $walk = function (Element $element) use (&$walk, &$found): void {
            if ($found !== null) {
                return;
            }
            $box = $element->effectiveBox();
            if ($box !== null && $box->hasSize()) {
                $found = $element;
                return;
            }
            $element->visitChildren($walk);
        };

        $root = $app->root();
        if ($root !== null) {
            $walk($root);
        }

        return $found;
    }
}

/** @internal 每帧真开一块像素缓冲的表面：快照判据要读"那一刻"的像素 */
final class PixelSurface implements RenderSurface
{
    public int $frames = 0;

    private ?RasterCanvas $raster = null;

    private Color $background;

    public function __construct(private readonly int $width, private readonly int $height)
    {
        $this->background = Color::white();
    }

    public function resize(int $width, int $height, float $dpr): void
    {
    }

    public function setBackground(Color $color): void
    {
        $this->background = $color;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        ++$this->frames;

        return $this->raster = new RasterCanvas($this->width, $this->height, $this->background);
    }

    public function endFrame(): void
    {
    }

    public function raster(): ?RasterCanvas
    {
        return $this->raster;
    }
}
