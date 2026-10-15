<?php
declare(strict_types=1);

namespace Drift\Dev\Preview;

use Drift\App\Application;
use Drift\App\Frame;
use Drift\Geometry\BoxConstraints;
use Drift\Layout\RenderBox;
use Drift\Geometry\Size;
use Drift\Text\TextMetricsProvider;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * @internal **无头预览**：一棵 Widget 树 → 一帧 SVG（§16.3 / §A.5）。
 *
 * 存在的理由是 §16.3 那句话："AOT 构建 20–50s，内环不编译" —— 软光栅 + 纯 PHP
 * 能在 `php.exe` 里跑完整 layout + paint，改一页只跑一页（§12.2 的"每页可独立
 * 预览"因此才成立）。
 *
 * ★ 它**不 new Surface**、不碰平台：表面由调用方注入（`SvgSurface` 或测试里的
 *   任何 `RenderSurface`）⇒ 与 `Application` 同一条注入纪律（§7.2 裁决 4）。
 */
final class Renderer
{
    private ?Element $lastRoot = null;
    public function __construct(
        private readonly SvgSurface $surface,
        private readonly TextMetricsProvider $metrics,
        private readonly ThemeData $theme,
    ) {
    }

    /**
     * 渲染一帧。
     *
     * @param float $dpr 设备像素比（§6.1）：逻辑宽高按它量化成物理像素
     * @param callable|null $prepare 挂载后、布局前调用（`Element` 已建、State 已
     *        `initState`）—— `drift-preview --focus` 用它先把焦点给某个可聚焦节点，
     *        于是**编辑态插入符**能被画进 SVG（§9.1 的输入层"被看见"）。
     *
     * @api
     */
    public function render(Widget $root, float $width, float $height, float $dpr = 1.0, ?callable $prepare = null): string
    {
        $this->surface->resize((int) round($width), (int) round($height), $dpr);

        $context = new BuildContext($width, $height, $this->theme, $this->metrics);
        $element = $root->createElement();
        $element->mount(null, $context);
        $this->lastRoot = $element;

        if ($prepare !== null) {
            $prepare($element);
        }

        $frame = new Frame($this->surface);
        $frame->run($element, BoxConstraints::tight(new Size($width, $height)), 0.0);

        return $this->surface->svg();
    }

    /**
     * 渲染**一个应用**的一帧（§12.2：应用也能独立预览，不只是单页）。
     *
     * 与 `render()` 的差别：走 `Application::attach()`，于是 `buildRoot()` /
     * `title()` / `commands()` 一起被验到 —— 而不只是验一棵 Widget 树。
     * 组合根（无盒的 `StatelessWidget`）这条路也因此被真机覆盖到。
     */
    public function renderApp(Application $app, float $width, float $height, float $dpr = 1.0): string
    {
        $this->surface->resize((int) round($width), (int) round($height), $dpr);

        $app->attach(
            $this->surface,
            BoxConstraints::tight(new Size($width, $height)),
            new BuildContext($width, $height, $this->theme, $this->metrics),
        );
        $this->lastRoot = $app->root();

        return $this->surface->svg();
    }

    /**
     * @internal drift-preview 的尺寸摘要：最近一次 render 的**有效根盒**
     */
    public function effectiveRoot(): ?RenderBox
    {
        return $this->lastRoot?->effectiveBox();
    }

    /** @internal 测试要看 Element 树（例如断言行数 / State） */
    public function mount(Widget $root, float $width, float $height): Element
    {
        $this->surface->resize((int) round($width), (int) round($height), 1.0);
        $context = new BuildContext($width, $height, $this->theme, $this->metrics);
        $element = $root->createElement();
        $element->mount(null, $context);

        return $element;
    }
}
