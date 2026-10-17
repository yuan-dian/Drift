<?php

declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;

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
 * ★ **滚动条**（2026-10-17）：`paint()` 在内容之上画一条胶囊形 thumb
 *   （`maxScroll == 0` 不画）。纯视觉层 —— 不新增状态、不改夹取、不碰命中。
 *
 * @api
 */
final class RenderScrollView extends RenderProxyBox
{
    /** 滚动条形体（逻辑像素） */
    private const BAR_THICKNESS = 6.0;
    private const BAR_MARGIN = 3.0;
    private const BAR_MIN_THUMB = 24.0;

    /** 本次布局算出的可滚动上限（供夹具与未来滚动条读） */
    private float $maxScroll = 0.0;

    /**
     * @param float $scrollOffset 已夹取的滚动偏移（逻辑像素，≥ 0）
     * @param Color $thumb        滚动条色（主题 Muted：两主题都够清晰、语义"次要"）
     * @param bool  $vertical     true = 纵向滚动（内容沿 y 平移）
     *
     * ★ 参数序：**必选在前、可选在后** —— 反了 PHP 解释器只发 Deprecated，
     *   tpc 预处理器直接 Fatal（"optional parameter cannot be declared before
     *   required parameter"）⇒ 解释器三门全绿也过不了 codegen（2026-10-17 踩过）。
     */
    public function __construct(
        private readonly float $scrollOffset,
        private readonly Color $thumb,
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
        // ★★ 平移只包住内容：滚动条画在**视口坐标系**（restore 掉平移再画）。
        //   否则 thumb 跟着内容一起被偏移带走 —— offset=max 时整条飞出视口
        //   被裁干净（"该贴底时什么都没有"，2026-10-17 被 smoke 的 sv2 抓到：
        //   offset 小的用例有墨、offset=max 的没有 ⇒ 单看一条会以为是位置公式错）。
        $canvas->save();
        $canvas->translate($child->offsetX(), $child->offsetY());
        $child->paint($canvas);
        $canvas->restore();
        // ★ 滚动条画在**内容之上**（后画即在上）、裁剪区之内 ⇒ 内容滚到
        //   边角时被它盖住一角，与浏览器/Flutter 的 Scrollbar 同行为。
        $this->paintScrollbar($canvas, $size);
        $canvas->popClip();
        $canvas->restore();
    }

    /**
     * 滚动条（2026-10-17 补齐的第一个滚动缺口：**纯视觉层**，不碰滚动机制）。
     *
     * 形体：胶囊形 thumb，贴视口右侧（纵向）/ 底侧（横向）。
     * - 长度 = 视口占比 × 轨道（最短 24，超长夹进轨道）⇒ 内容越长条越短；
     * - 位置 = 偏移 / maxScroll × 可走距离 ⇒ 条即"当前位置"的可视化；
     * - `maxScroll == 0`（内容不溢出）⇒ **不画**（浏览器同行为；画一条
     *   满轨 thumb 是撒谎——它暗示"可以滚"）。★ 这个早退还是**结构必需**：
     *   `appliedOffset() / max` 在 max=0 时是 0/0 ⇒ PHP 8 抛
     *   `DivisionByZeroError`（删掉它 smoke 当场 Fatal，见反例自检 ③）。
     */
    private function paintScrollbar(Canvas $canvas, Size $size): void
    {
        $max = $this->maxScroll;
        if ($max <= 0.0) {
            return;
        }

        $vertical = $this->vertical;
        $track = $vertical ? $size->height : $size->width;
        $cross = $vertical ? $size->width : $size->height;

        $thumbLen = min($track, max(self::BAR_MIN_THUMB, $track * $track / ($track + $max)));
        $travel = max(0.0, $track - 2.0 * self::BAR_MARGIN - $thumbLen);
        $thumbPos = self::BAR_MARGIN + ($this->appliedOffset() / $max) * $travel;

        $bar = $vertical
            ? new Rect($cross - self::BAR_THICKNESS - self::BAR_MARGIN, $thumbPos, self::BAR_THICKNESS, $thumbLen)
            : new Rect($thumbPos, $cross - self::BAR_THICKNESS - self::BAR_MARGIN, $thumbLen, self::BAR_THICKNESS);

        $canvas->drawRect($bar, Paint::fill($this->thumb), self::BAR_THICKNESS * 0.5);
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
