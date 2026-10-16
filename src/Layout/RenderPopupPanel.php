<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Canvas;
use Drift\Rendering\Paint;

/**
 * 浮层面板的渲染盒（O-20 A 的必要子项，§5.1 裁决 1 / §9.1 菜单行）。
 *
 * 单子代理：内容（一列菜单项 / 模态内容）住 `child`，本盒给它加三层 chrome。
 *
 * ★ **投影必须画到自己矩形之外**（GUI 仓教训）：`BoxStyle` 只管盒内（O-17 定的边界）
 *   ⇒ 投影是本盒自己的事。所以**盒尺寸 = 面板 + 2×elevation**，面板矩形内缩
 *   `elevation` —— 「夹紧按面板算」的前提（§9.1 语义 #6：`Frame` 钉原点时用的是
 *   面板矩形，不是含留白的盒）。
 *
 * ★ **统一宽 = max(minWidth, 最宽子项的自然宽)**：菜单项要"整行可点"（语义 #7），
 *   行内 `Spacer` 只在**有界宽**下才撑得开 ⇒ 面板必须先把内容量成统一宽度，再以
 *   **tight 宽 / loose 高**布局。测自然宽走 `intrinsicWidth()`（§13.1 C3）——
 *   而不是 GUI 仓的 `Canvas::measureText`（★ Drift 的 `Canvas` 没有这个方法，
 *   核验过接口），于是"确定性注入"这条纪律顺带成立。
 *
 * @api
 */
final class RenderPopupPanel extends RenderProxyBox
{
    public function __construct(
        private readonly EdgeInsets $padding = new EdgeInsets(0.0, 0.0, 0.0, 0.0),
        private readonly float $minWidth = 0.0,
        private readonly float $elevation = 6.0,
        private readonly BoxStyle $style = new BoxStyle(),
    ) {
    }

    /** 面板矩形（**不含**投影留白）在盒内的位置与尺寸 —— `Frame` 夹紧用 */
    public function panelRect(): Rect
    {
        $size = $this->size ?? new Size(0.0, 0.0);
        $e = $this->elevation;

        return Rect::fromLtrb($e, $e, max($e, $size->width - $e), max($e, $size->height - $e));
    }

    /** 内容原点（盒坐标系）—— 框选 / 调试用 */
    public function contentOrigin(): Point
    {
        return new Point(
            $this->elevation + $this->padding->left,
            $this->elevation + $this->padding->top,
        );
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $e = $this->elevation;
        $child = $this->child;

        if ($child === null) {
            return $constraints->constrain(new Size(2.0 * $e, 2.0 * $e));
        }

        // 内容自然宽（不含内边距）⇒ 面板宽；再夹进"约束允许的盒宽 − 两倍投影留白"
        $contentW = max($this->minWidth, $child->intrinsicWidth(INF));
        $panelW = $contentW + $this->padding->horizontal() + 2.0 * $e;

        $boxW = min($panelW, $constraints->maxWidth);
        $innerW = max(0.0, $boxW - 2.0 * $e - $this->padding->horizontal());

        // 有界宽 + 尽量自然高：tight 宽让 `Spacer` 撑满整行，loose 高让内容自尺寸
        $innerMaxH = $constraints->maxHeight === INF
            ? INF
            : max(0.0, $constraints->maxHeight - 2.0 * $e - $this->padding->vertical());

        $child->layout(new BoxConstraints($innerW, $innerW, 0.0, $innerMaxH));
        $child->setOffset($e + $this->padding->left, $e + $this->padding->top);

        return $constraints->constrain(new Size(
            $boxW,
            $child->size()->height + $this->padding->vertical() + 2.0 * $e,
        ));
    }

    public function paint(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null) {
            return; // 没排过 ⇒ 无墨
        }

        $panel = $this->panelRect();

        // ① 投影：整盒矩形垫一层半透明黑（面板随后盖住中间 ⇒ 视觉上只剩外圈）
        if ($this->elevation > 0.0) {
            $canvas->drawRect(
                Rect::fromOrigin(new Point(0.0, 0.0), $size),
                Paint::fill(\Drift\Rendering\Color::fromRgba(0, 0, 0, 48)),
                $this->style->radius,
            );
        }

        // ② 底 ③ 边框（BoxStyle 只管盒内 —— 见类 docblock）
        if ($this->style->background !== null) {
            $canvas->drawRect($panel, Paint::fill($this->style->background), $this->style->radius);
        }
        if ($this->style->borderColor !== null && $this->style->borderWidth > 0.0) {
            $canvas->drawRect(
                $panel,
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
