<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\DividerStyle;
use Drift\Rendering\Paint;

/**
 * 分割线渲染盒：一根**横向发丝线**（LayUI 的 `hr` / 卡片内的分节线）。
 *
 * ★ **叶子**（没有子渲染对象）：分割线没有内容，文字由调用方自己用 `Text` 排。
 *   做成 `RenderProxyBox` + 可选标签会把"标签居中在线上"的几何（打断线 / 垫底色）
 *   提前引进来 —— 而今天没有消费者。
 *
 * ★ **宽度语义是"吃满授予"**（`$width <= 0` 时取约束上限），因为一根线只在
 *   横贯可用宽度时才是"分割"。**副作用就是 `divider-in-row` 那条 lint 的症状**：
 *   放进 `Row` 里它会吃掉该行剩余的宽度（§16.6.1 R3，通常不是你要的）。
 *   ⇒ 想给线固定宽度就 `->width(120.0)`；想在一行里只占自己那份就配 `Expanded`。
 *
 * ★ **无界宽度不回退到 INF**（§14 / `RenderDock` 的教训）：`maxWidth` 不限时
 *   画 `minWidth` 那么长 —— 无限长的线会把坐标链污染成 INF。
 *
 * ★ 只画 `drawRect`（Core 层）：软光栅的 preview / golden / smoke 必须能画它。
 *
 * @api
 */
final class RenderDivider extends RenderBox
{
    /** 无界宽度时的兜底线长（与 `PopupPanel` 的 `minWidth` 同一思路） */
    private const float MIN_WIDTH = 24.0;

    public function __construct(
        private readonly DividerStyle $style,
        private readonly float $width,
    ) {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $w = $this->width > 0.0 ? min($this->width, $constraints->maxWidth) : $constraints->maxWidth;
        if (!is_finite($w)) {
            $w = self::MIN_WIDTH; // 无限（或 NaN）⇒ 兜底，绝不把 INF 放进坐标链
        }

        return $constraints->constrain(new Size($w, $this->style->thickness));
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        // 0 = "不表达自然宽"（宽度由父给）—— 与 performLayout 的"吃满"是同一条语义
        return $this->width;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->style->thickness;
    }

    /** 装饰性叶子：不给语义角色（§9.2 的"纯装饰一律不给"） */
    public function visitChildren(callable $visitor): void
    {
    }

    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null || $size->width <= 0.0 || $size->height <= 0.0) {
            return; // 没排过 ⇒ 无墨
        }

        // 方角（radius=0）：1px 发丝线做圆角只会让边缘两个像素发虚
        $canvas->drawRect(new Rect(0.0, 0.0, $size->width, $size->height), Paint::fill($this->style->color));
    }
}
