<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Rendering\ToggleStyle;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * 开关类控件的渲染盒：方框 / 圆点 / 轨道 + 右侧标签。
 *
 * 三种形状共用一个盒（`int $kind` + 分支），**不是三个子类**：
 * 布局（控件 + 间距 + 标签）与命中完全同构，差别只在 `paint()` 里的几个图元。
 * 三个子类会立刻撞上 tpc 的去虚化缺陷（`11` C6：静态类型已知时子类覆写被静默
 * 忽略）—— 与 `RenderSpinner` 用方向表而不是 `cos()/sin()` 是同一种"把不确定性
 * 从代码里删掉"。
 *
 * ★ **只用 Core / Extended 层 op**（`drawRect` / `drawEllipse` / `drawLine`）：
 *   `drawPath` 是 Optional 层、`RasterCanvas` 显式报 `E-OP-UNSUPPORTED` ⇒ 一旦
 *   用路径画勾，preview / golden / smoke（全部走软光栅）会当场失败。勾是两条线段，
 *   本来就是 Core 层够用的形状。
 *
 * ★ **禁用态不是新形状**：只换形状色（`ToggleStyle::$disabled`），几何一个字不动 ——
 *   否则"禁用"会让控件在表单里跳一下。
 *
 * @api
 */
final class RenderToggle extends RenderProxyBox
{
    public const KIND_CHECKBOX = 1;
    public const KIND_RADIO = 2;
    public const KIND_SWITCH = 3;

    /** 开关轨道宽 / 高的比例（18 高 ⇒ 34.2 宽，接近 1.9:1 的常见比例） */
    private const SWITCH_RATIO = 1.9;

    public function __construct(
        private readonly int $kind,
        private readonly bool $on,
        string $label,
        private readonly ToggleStyle $style,
        TextMetricsProvider $metrics,
        // 不 promote：基类同名字段会在 AOT 下撞 "Cannot redeclare non-readonly property"
        ?Color $hoverOverlay = null,
        ?Color $pressedOverlay = null,
    ) {
        $this->setInteraction($hoverOverlay, $pressedOverlay);

        $this->setChild(new RenderText(
            $label,
            TextStyle::of($style->fontSize),
            $style->label,
            $metrics,
        ));
    }

    public function kind(): int
    {
        return $this->kind;
    }

    public function isOn(): bool
    {
        return $this->on;
    }

    /** 控件本体占的横向长度（开关比方框宽） */
    private function controlWidth(): float
    {
        return $this->kind === self::KIND_SWITCH
            ? $this->style->boxSize * self::SWITCH_RATIO
            : $this->style->boxSize;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $control = $this->controlWidth();
        $gap = $this->style->gap;

        // 标签只能吃剩下的横向空间（父若把宽定死，标签自己断行，不溢出到控件上）
        $labelMaxWidth = max(0.0, $constraints->maxWidth - $control - $gap);
        $this->child?->layout(new BoxConstraints(0.0, $labelMaxWidth, 0.0, $constraints->maxHeight));

        $label = $this->child?->size() ?? new Size(0.0, 0.0);
        $height = max($this->style->boxSize, $label->height);

        $this->child?->setOffset($control + $gap, max(0.0, ($height - $label->height) / 2.0));

        return $constraints->constrain(new Size($control + $gap + $label->width, $height));
    }

    #[\Override]
    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->controlWidth() + $this->style->gap
            + ($this->child?->intrinsicWidth($height) ?? 0.0);
    }

    #[\Override]
    protected function contentIntrinsicHeight(float $width): float
    {
        $labelWidth = max(0.0, $width - $this->controlWidth() - $this->style->gap);

        return max($this->style->boxSize, $this->child?->intrinsicHeight($labelWidth) ?? 0.0);
    }

    /**
     * 光晕只罩**控件本体**，标签区不铺色 —— 整行铺色会像一条列表高亮，
     *   而这里点标签和点方框是同一件事，视觉上不该有两种"选中感"。
     *
     * 形状跟本体走：方框 ⇒ 圆、开关 ⇒ 两端圆弧的胶囊（`radius` 取半高 + 外扩）。
     * `grow` 必须小于 `gap`，否则光晕会压到标签第一个字上。
     */
    #[\Override]
    protected function paintInteraction(Canvas $canvas, ?Size $size): void
    {
        if ($size === null) {
            return;
        }

        $h = $this->style->boxSize;
        $grow = $h * 0.4;
        $top = max(0.0, ($size->height - $h) / 2.0);

        $this->paintStateLayer(
            $canvas,
            new Rect(-$grow, $top - $grow, $this->controlWidth() + 2.0 * $grow, $h + 2.0 * $grow),
            $h / 2.0 + $grow,
        );
    }

    #[\Override]
    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size ?? new Size(0.0, 0.0);
        if ($size->width <= 0.0 || $size->height <= 0.0) {
            return; // 没排过 ⇒ 无墨（与 RenderSpinner 同判据）
        }

        $box = $this->style->boxSize;
        $top = max(0.0, ($size->height - $box) / 2.0);
        // ★ 不存自己的 `$enabled` 字段：`disabled` 的唯一来源是 `Modifier`（`RenderBox`
        //   在 `setMods()` 里落成 `$this->disabled`）。控件另存一份 ⇒ 两份真相。
        $enabled = !$this->isDisabled();
        $accent = $enabled ? $this->style->accent : $this->style->disabled;
        $idle = $enabled ? $this->style->idle : $this->style->disabled;
        $mark = $enabled ? $this->style->mark : $this->style->thumb;
        $ring = max(1.0, $box * 0.09);

        if ($this->kind === self::KIND_SWITCH) {
            $this->paintSwitch($canvas, $top, $accent, $idle, $mark);
        } elseif ($this->kind === self::KIND_RADIO) {
            $this->paintRadio($canvas, $top, $accent, $idle, $ring);
        } else {
            $this->paintCheckbox($canvas, $top, $accent, $idle, $mark, $ring);
        }

        // 标签（偏移已在 performLayout 里定好；RenderProxyBox 负责 translate + 还原）
        parent::paintContent($canvas);
    }

    /** 方框 + 两条线段拼的勾（勾的坐标全按 box 比例，盒子缩放时跟着缩放） */
    private function paintCheckbox(
        Canvas $canvas,
        float $top,
        Color $accent,
        Color $idle,
        Color $mark,
        float $ring,
    ): void {
        $box = $this->style->boxSize;
        $rect = new Rect(0.0, $top, $box, $box);
        $radius = $box * 0.18;

        if (!$this->on) {
            $canvas->drawRect($rect, Paint::stroke($idle, $ring), $radius);

            return;
        }

        $canvas->drawRect($rect, Paint::fill($accent), $radius);
        $pen = Paint::stroke($mark, max(1.5, $box * 0.11));
        $canvas->drawLine($box * 0.24, $top + $box * 0.52, $box * 0.43, $top + $box * 0.72, $pen);
        $canvas->drawLine($box * 0.43, $top + $box * 0.72, $box * 0.76, $top + $box * 0.30, $pen);
    }

    /** 圆环 + 内点（两个椭圆：一个描边、一个填充） */
    private function paintRadio(
        Canvas $canvas,
        float $top,
        Color $accent,
        Color $idle,
        float $ring,
    ): void {
        $box = $this->style->boxSize;
        $canvas->drawEllipse(new Rect(0.0, $top, $box, $box), Paint::stroke($this->on ? $accent : $idle, $ring));

        if (!$this->on) {
            return;
        }

        $dot = $box * 0.44;
        $canvas->drawEllipse(
            new Rect(($box - $dot) / 2.0, $top + ($box - $dot) / 2.0, $dot, $dot),
            Paint::fill($accent),
        );
    }

    /** 胶囊轨道 + 圆滑块（滑块左右位置就是"开/关"的全部视觉差异） */
    private function paintSwitch(
        Canvas $canvas,
        float $top,
        Color $accent,
        Color $idle,
        Color $thumb,
    ): void {
        $height = $this->style->boxSize;
        $width = $this->controlWidth();
        $radius = $height / 2.0;

        $canvas->drawRect(
            new Rect(0.0, $top, $width, $height),
            Paint::fill($this->on ? $accent : $idle),
            $radius,
        );

        $pad = max(1.0, $height * 0.11);
        $dot = $height - 2.0 * $pad;
        $x = $this->on ? $width - $pad - $dot : $pad;
        $canvas->drawEllipse(new Rect($x, $top + $pad, $dot, $dot), Paint::fill($thumb));
    }
}
