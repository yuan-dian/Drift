<?php
declare(strict_types=1);

namespace Drift\Dev\Preview;

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
     *
     * @api
     */
    public function render(Widget $root, float $width, float $height, float $dpr = 1.0): string
    {
        $this->surface->resize((int) round($width), (int) round($height), $dpr);

        $context = new BuildContext($width, $height, $this->theme, $this->metrics);
        $element = $root->createElement();
        $element->mount(null, $context);
        $this->lastRoot = $element;

        $frame = new Frame($this->surface);
        $frame->run($element, BoxConstraints::tight(new Size($width, $height)), 0.0);

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
