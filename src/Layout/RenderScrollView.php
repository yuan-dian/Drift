<?php

declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 滚动视口（§13.1 的新盒）—— **内容比视口长时，按偏移平移内容并裁剪**。
 *
 * ★★ **坐标链纪律（2026-10-17 padding 缺陷的同族）**：滚动偏移**进子盒的
 *   `offsetX/offsetY()`**，不是 paint 期的额外 `translate()`。否则
 *   `hitTest()` 与 `Application::absoluteOrigin()` 拿到的坐标会比画出来的
 *   位置差一个滚动量 —— 表现为"滚动后点击错位"（滚动越远偏得越多，且只在
 *   滚过之后才出现，是最难查的一类）。
 *   ⇒ 命中测试：本盒先按**视口** size 判 (x,y) ⇒ 视口外的点根本进不了子树，
 *     于是"裁掉的部分点不到"是结构保证，不需要 hitTest 认识裁剪。
 *
 * ★ 布局策略（M2 级，够用即止）：
 *   - 内容按**主轴无界**布局 ⇒ 才量得出"到底有多长"；
 *   - 视口主轴 = 约束上限（INF ⇒ 取内容长度，即"不滚、撑满"）；
 *   - `maxScroll = max(0, 内容主轴 − 视口主轴)`，偏移夹进 `[0, maxScroll]`
 *     ⇒ 滚不到头、也滚不出界（越界是"内容飞走"的静默形态）。
 *
 * ★ 偏移量是**构造参数**（不提供 setter）：Widget 不可变 ⇒ 参数只会整体换新
 *   （`RenderBoxWidget::createRenderObject()` 的纪律），不存在字段级半同步。
 *   滚动由 `ScrollViewState` 持值、经重建把新偏移递进来（与 `TextField`
 *   打字的路径完全同构）。
 *
 * @api
 */
final class RenderScrollView extends RenderProxyBox
{
    /** 本次布局算出的可滚动上限（供夹具与未来滚动条读） */
    private float $maxScroll = 0.0;

    /**
     * @param float $scrollOffset 已夹取的滚动偏移（逻辑像素，≥ 0）
     * @param bool  $vertical     true = 纵向滚动（内容沿 y 平移）
     */
    public function __construct(
        private readonly float $scrollOffset,
        private readonly bool $vertical = true,
    ) {
    }

    /** 可滚动上限（布局后才有意义） */
    public function maxScroll(): float
    {
        return $this->maxScroll;
    }

    /** 实际生效的偏移（已按 maxScroll 夹取） */
    public function appliedOffset(): float
    {
        return min(max(0.0, $this->scrollOffset), $this->maxScroll);
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $child = $this->child;

        if ($child === null) {
            $this->maxScroll = 0.0;

            return $constraints->constrain(new Size(0.0, 0.0));
        }

        // ① 内容：主轴**无界** —— 有界的话内容会被压进视口，永远量不出溢出
        $content = $child->layout($this->vertical
            ? new BoxConstraints($constraints->minWidth, $constraints->maxWidth, 0.0, BoxConstraints::INFINITY)
            : new BoxConstraints(0.0, BoxConstraints::INFINITY, $constraints->minHeight, $constraints->maxHeight));

        $contentMain = $this->vertical ? $content->height : $content->width;
        $contentCross = $this->vertical ? $content->width : $content->height;

        // ② 视口：取约束上限；上界无限 ⇒ 视口跟着内容走（不滚动）
        $mainMax = $this->vertical ? $constraints->maxHeight : $constraints->maxWidth;
        $crossMax = $this->vertical ? $constraints->maxWidth : $constraints->maxHeight;
        $viewportMain = is_infinite($mainMax) ? $contentMain : $mainMax;
        $viewportCross = is_infinite($crossMax) ? $contentCross : $crossMax;

        // ③ 夹取（越界 = 内容飞走的静默形态 ⇒ 结构上不可能）
        $this->maxScroll = max(0.0, $contentMain - $viewportMain);
        $offset = $this->appliedOffset();

        // ④ ★ 偏移进子盒 offset（不是 paint 期 translate）—— 见类注释
        if ($this->vertical) {
            $child->setOffset(0.0, -$offset);
        } else {
            $child->setOffset(-$offset, 0.0);
        }

        return $this->vertical
            ? new Size($viewportCross, $viewportMain)
            : new Size($viewportMain, $viewportCross);
    }

    public function paint(Canvas $canvas): void
    {
        $child = $this->child;
        $size = $this->size();

        if ($child === null || $size === null) {
            return;
        }

        // ★ 裁剪是**视口矩形**：内容平移到视口外的部分不该画出来。
        //   命中侧不需要它 —— 视口 size 判定已经把视口外的点拦在子树之外。
        $canvas->save();
        $canvas->pushClip(new Rect(0.0, 0.0, $size->width, $size->height));
        $canvas->translate($child->offsetX(), $child->offsetY());
        $child->paint($canvas);
        $canvas->popClip();
        $canvas->restore();
    }

    /**
     * ★ 溢出策略是 **Scroll**（不是默认的 Clip）：本盒会超出视口，超出部分按
     *   滚动偏移平移 + 视口裁剪 —— 这是 `Overflow::Scroll` 的第一次真实实现
     *   （此前只有声明，行为靠 `RenderDock` 的 `Errors::report` 兜）。
     */
    public function overflow(): Overflow
    {
        return Overflow::Scroll;
    }
}
