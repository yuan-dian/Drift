<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;

/**
 * 卡片盒：**底色 + 边框 + 圆角 + 内边距 + 柔和投影**，一个内容块。
 *
 * ## 为什么需要一个新盒（而不是复用 `RenderPopupPanel`）
 *
 * `RenderPopupPanel` 的投影是**一块实心半透明垫层**（`alpha 48`，外扩 `elevation`
 * 像素）—— 那是"浮起来"的语义，菜单要的就是硬投影 + 留白。卡片要的是相反的东西：
 * **几乎看不见的贴地阴影**（LayUI / Material 的 `0 1px 2px rgba(0,0,0,.05)`）。
 * 两者共用会逼出一个 `mode` 开关，而它们的布局规则也不同（面板有统一宽 `minWidth`）。
 *
 * ## 柔和阴影怎么画（没有 blur，只有 `drawRect`）
 *
 * 叠 **3 层**同色矩形，从外到内 alpha 递增、外扩递减 —— 这是"廉价高斯"：
 *
 * ```
 * 外扩 3px · alpha × 0.35
 * 外扩 2px · alpha × 0.60
 * 外扩 1px · alpha × 1.00
 * ```
 *
 * 单层实心矩形看起来像描边（用户第一眼的判断就是"生硬"），三层叠出过渡带才像影子。
 * 代价 = 每张卡片多 3 次 `drawRect`（圆角路径，软光栅下可忽略）。
 *
 * ★ **阴影画在盒外**，所以父布局不会为它留白 —— 卡片之间要有间距（≥ 4px），
 *   否则邻块的底色会盖掉阴影。这是有意的：为 3px 影子让所有容器多一层 insets
 *   得不偿失，而"块之间留间距"本来就是排版该做的事。
 *
 * @api
 */
final class RenderCard extends RenderProxyBox
{
    public function __construct(
        private readonly EdgeInsets $padding = new EdgeInsets(0.0, 0.0, 0.0, 0.0),
        private readonly float $shadow = 0.0,
        private readonly BoxStyle $style = new BoxStyle(),
    ) {
    }

    /** 内容原点（盒坐标系）—— 调试 / 命中换算用 */
    public function contentOrigin(): array
    {
        return [$this->padding->left, $this->padding->top];
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $child = $this->child;
        if ($child === null) {
            return $constraints->constrain(new Size(
                $this->padding->horizontal(),
                $this->padding->vertical(),
            ));
        }

        // ★ **不要 `loosen()`**：父把宽度定死（例如 `Column::cross(Stretch)`）时，
        //   松掉紧约束会让内容缩成自然宽 —— 卡片撑满、内容挤在左边，观感像"没对齐"。
        //   与 `RenderPadding` 同口径：只 deflate，不放松。
        $inner = $constraints->deflate($this->padding);
        $child->layout($inner);
        $child->setOffset($this->padding->left, $this->padding->top);

        $size = $child->size() ?? new Size(0.0, 0.0);

        return $constraints->constrain(new Size(
            $size->width + $this->padding->horizontal(),
            $size->height + $this->padding->vertical(),
        ));
    }

    /**
     * ★ **有内边距的盒必须覆写内禀尺寸**（§13.1 C3）：不加上 insets 的话，
     *   `Row` / `Dock` 量出来的自然宽会比实际窄一个 padding，卡片的文字会被挤。
     */
    public function intrinsicWidth(float $height): float
    {
        return ($this->child?->intrinsicWidth(max(0.0, $height - $this->padding->vertical())) ?? 0.0)
            + $this->padding->horizontal();
    }

    public function intrinsicHeight(float $width): float
    {
        return ($this->child?->intrinsicHeight(max(0.0, $width - $this->padding->horizontal())) ?? 0.0)
            + $this->padding->vertical();
    }

    public function paint(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null) {
            return;
        }

        $rect = new Rect(0.0, 0.0, $size->width, $size->height);

        // ① 柔和阴影：三层递减（见类头）
        if ($this->shadow > 0.0) {
            $base = (int) round(255.0 * min(1.0, $this->shadow));
            $steps = [3 => 0.35, 2 => 0.60, 1 => 1.00];
            foreach ($steps as $grow => $weight) {
                $alpha = max(1, (int) round($base * $weight));
                $canvas->drawRect(
                    new Rect(-$grow, -$grow, $size->width + 2 * $grow, $size->height + 2 * $grow),
                    Paint::fill(new Color(0, 0, 0, $alpha)),
                    $this->style->radius + $grow,
                );
            }
        }

        // ② 底 ③ 边框
        if ($this->style->background !== null) {
            $canvas->drawRect($rect, Paint::fill($this->style->background), $this->style->radius);
        }
        if ($this->style->borderColor !== null && $this->style->borderWidth > 0.0) {
            $canvas->drawRect(
                $rect,
                Paint::stroke($this->style->borderColor, $this->style->borderWidth),
                $this->style->radius,
            );
        }

        $child = $this->child;
        if ($child === null) {
            return;
        }

        $canvas->save();
        $canvas->translate($child->offsetX(), $child->offsetY());
        $child->paint($canvas);
        $canvas->restore();
    }
}
