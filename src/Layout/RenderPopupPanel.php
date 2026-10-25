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
use Drift\Rendering\ShadowPainter;

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
        private readonly float $maxHeight = 0.0,
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

        // ① 约束允许的内容高（投影 + 内边距都要从盒高里扣掉）
        $roomH = $constraints->maxHeight === INF
            ? INF
            : max(0.0, $constraints->maxHeight - 2.0 * $e - $this->padding->vertical());

        // ② 本盒声明的内容高上限（`0` = 不限）；与约束取小
        //   ★ 与 `$constraints->maxHeight` 取小是**必须**的：`placeEntry()` 的"超大退化"
        //     会给整个客户区那么高的**紧**约束，那时上限不能超出客户区。
        $cap = $this->maxHeight;
        if ($cap > 0.0 && $cap > $roomH) {
            $cap = $roomH;
        }

        if ($cap <= 0.0) {
            // ── 不限高：与 2026-10-23 之前逐字节相同（无第二遍排）──
            $child->layout(new BoxConstraints($innerW, $innerW, 0.0, $roomH));
            $child->setOffset($e + $this->padding->left, $e + $this->padding->top);

            return $constraints->constrain(new Size(
                $boxW,
                $child->size()->height + $this->padding->vertical() + 2.0 * $e,
            ));
        }

        // ── 限高：**先无界排一遍量自然高**，再决定"贴着内容"还是"按上限滚" ──
        // ★ 为什么不能在 Widget 侧按条目数猜：行高由主题与应用的字号定，猜错就是
        //   "明明只有 5 项却拖出 260px 的空面板"或反过来"6 项就溢出被裁掉"。
        //   ★ 为什么不能在 `RenderScrollView` 里 hug：视口的语义就是"父给多大占多大"
        //     （`BigListPage` 依赖它撑满）—— 改它会动到所有既有布局。
        //   ★ 第二遍只发生在"真的超高"时 ⇒ 常见路径（少选项）只多一次 intrinsic。
        $natural = $child->layout(new BoxConstraints($innerW, $innerW, 0.0, INF));
        $naturalH = $natural->height + $this->padding->vertical() + 2.0 * $e;

        if ($naturalH > $cap) {
            // 超高 ⇒ 内容按上限重排：`ScrollView` 的视口 = 上限 ⇒ 可滚；超出部分
            // 仍被它自己的视口裁剪（那是**滚动**，不是"点不到"）。
            $innerCap = max(0.0, $cap - 2.0 * $e - $this->padding->vertical());
            $child->layout(new BoxConstraints($innerW, $innerW, 0.0, $innerCap));
        }
        // 否则沿用第一遍的自然高（贴着内容，不凭空撑高一截空白）

        $child->setOffset($e + $this->padding->left, $e + $this->padding->top);

        return $constraints->constrain(new Size(
            $boxW,
            min($cap, $child->size()->height + $this->padding->vertical() + 2.0 * $e),
        ));
    }

    #[\Override]
    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null) {
            return; // 没排过 ⇒ 无墨
        }

        $panel = $this->panelRect();

        // ① 投影：有 `CAP_SHADOW` 就在**面板矩形**上做真模糊（扩散 ~1.5×elevation，
        //    落在 `performLayout()` 预留的 `2×elevation` 里 ⇒ 不溢出盒外）；否则降级成
        //    "整盒垫一层半透明黑"（面板随后盖住中间，视觉上只剩外圈）。
        //    ★ 用 `$panel` 而不是整盒：盒的留白是给投影用的，阴影必须从面板边起算。
        if ($this->elevation > 0.0) {
            $drawn = ShadowPainter::tryPaint(
                $canvas,
                $panel,
                $this->style->radius,
                $this->elevation,
                $this->elevation * 0.35,
                48,
            );

            if (!$drawn) {
                $canvas->drawRect(
                    Rect::fromOrigin(new Point(0.0, 0.0), $size),
                    Paint::fill(\Drift\Rendering\Color::fromRgba(0, 0, 0, 48)),
                    $this->style->radius,
                );
            }
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
        $child->paintComposed($canvas);
        $canvas->restore();
    }
}
