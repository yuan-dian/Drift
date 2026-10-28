<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use App\Hello\HelloApplication;
use Drift\Dev\Preview\Renderer;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Paint;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Text\TextStyle;
use Drift\Theme\ThemeData;

/**
 * apps/hello：第一个真实消费者（§4.5 / §A.1）。
 *
 * 它是"框架能跑真应用"的最小证据：应用只写 `buildRoot` / `title` / `commands`，
 * 表面是**注入**的（§7.2 裁决 4），所以无平台也能挂载、也能无头出图。
 *
 * ## 最后一组判据守的是"偏移算对但没画出来"
 *
 * `drawRect` 走 `toDevice()`（跟变换），而 `drawParagraph` 曾直接用传入坐标（不跟）⇒
 * `Center` 布局算出的偏移在 paint 时被丢掉 ⇒ 文字永远贴左上角，
 * **而墨点数一模一样**（最难查的一档）。所以必须看**包围盒**而不是墨点数。
 */
final class HelloAppTest extends SmokeTestCase
{
    public function testHelloAppRenders(): void
    {
        $hello = new HelloApplication();
        $helloSurface = new SurfaceProbe();
        $helloSurface->resize(320, 240, 1.0);
        $hello->attach($helloSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
        check('apps/hello · 应用只写 buildRoot/title/commands（§4.5）', $hello->title() === 'Hello Drift');
        check('apps/hello · 无平台也能挂载（表面是注入的，§7.2 裁决 4）', $hello->root() !== null);
        check(
            'apps/hello · 屏幕中央有「Hello Drift」（画出墨了）',
            $helloSurface->raster() !== null && inkCount($helloSurface->raster()) > 0,
            '墨点数 ' . (string) ($helloSurface->raster() !== null ? inkCount($helloSurface->raster()) : -1)
        );
        check(
            'apps/hello · 根排满视口（Center 撑满紧约束）',
            $hello->rootBox()?->size()->width === 320.0 && $hello->rootBox()?->size()->height === 240.0
        );

        $this->flushChecks();
    }

    /** preview 的 --app 路径：Application 也能无头出图（§12.2） */
    public function testPreviewAppRendersSvg(): void
    {
        $context = smokeContext();

        $helloSvgSurface = new SvgSurface();
        $helloSvg = (new Renderer($helloSvgSurface, $context->metrics(), ThemeData::light()))
            ->renderApp(new HelloApplication(), 320.0, 240.0, 1.0);
        check(
            'apps/hello · drift-preview --app 出 SVG（应用可独立预览）',
            substr_count($helloSvg, '<rect x=') > 0,
            'run 数 ' . (string) substr_count($helloSvg, '<rect x=')
        );

        $this->flushChecks();
    }

    /**
     * 回归：文本**必须吃画布变换**。
     *
     * ★ 这条是下面"文字居中"的机制前提：居中就是靠布局期算出的偏移在 paint 时生效。
     *   两条一起看才知道"偏移算对了"与"偏移画出来了"各是哪一步。
     */
    public function testTextRespectsCanvasTranslation(): void
    {
        $context = smokeContext();

        $shiftCanvas = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
        $shiftCanvas->translate(50.0, 20.0);
        $shiftPara = $context->metrics()->layout('Hi', TextStyle::of(14.0), INF, Scale::of(1.0));
        $shiftList = new DisplayList();
        (new RecordingCanvas($shiftList))->drawParagraph($shiftPara, Paint::fill(Color::black()), 0.0, 0.0);
        $shiftList->replay($shiftCanvas);
        $shiftBounds = inkBounds($shiftCanvas);
        check(
            'Rendering · 文本吃画布平移（translate 之后墨迹整体挪位）',
            $shiftBounds !== null && $shiftBounds[0] >= 50 && $shiftBounds[2] >= 20,
            '包围盒 ' . ($shiftBounds === null ? 'null' : implode(',', $shiftBounds))
        );

        $this->flushChecks();
    }

    /** 回归：hello 的「Hello Drift」是**居中**的，不是贴着左上角 */
    public function testHelloTextIsCentered(): void
    {
        $hello = new HelloApplication();
        $helloSurface = new SurfaceProbe();
        $helloSurface->resize(320, 240, 1.0);
        $hello->attach($helloSurface, BoxConstraints::tight(new Size(320.0, 240.0)));

        $helloBounds = inkBounds($helloSurface->raster());
        check(
            'apps/hello · 文字居中（Center 的偏移真被画出来）',
            $helloBounds !== null
                && abs(($helloBounds[0] + $helloBounds[1]) / 2 - 160) <= 4
                && abs(($helloBounds[2] + $helloBounds[3]) / 2 - 120) <= 4,
            '包围盒 ' . ($helloBounds === null ? 'null' : implode(',', $helloBounds))
        );

        $this->flushChecks();
    }
}
