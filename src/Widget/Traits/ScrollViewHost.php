<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Geometry\Axis;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Curve;
use Drift\Widget\ScrollController;

/**
 * `ScrollView` / `LazyList` 共用的 `Scrollable` + `ScrollMetrics` 面。
 *
 * ★ 两者只在"Δ 落地后要不要重造窗口"上分岔（`LazyListState::scrollBy()` 多一步换窗），
 *   其余七个方法逐字相同 ⇒ 收在这里，"主轴判据"与"找盒"各只有一份。
 * ★ 宿主须 `use SingleAxisScrollHost` 提供 `$lane`，并实现三个事实来源。
 * ★ `TextArea` 不进来：它的盒是 `RenderTextArea`（没有 `revealDelta()`），
 *   塞进来会白得两个恒零读数。
 *
 * @internal
 */
trait ScrollViewHost
{
    use SingleAxisScrollHost;

    /** 主轴：由 Widget 声明决定（同一个 State 既可能纵向也可能横向） */
    abstract protected function mainAxis(): Axis;

    /** 自己的滚动盒；首帧之前 / 盒被换掉 ⇒ `null` */
    abstract protected function scrollBox(): ?RenderScrollView;

    /** 外部句柄；没配 ⇒ `null` */
    abstract protected function scrollController(): ?ScrollController;

    /**
     * 内容能滚到的最大偏移（盒没挂 ⇒ 0）。
     * 每次问盒：内容长度随布局变，缓存就是"用上一帧回答这一帧"。
     */
    public function maxOffset(): float
    {
        $box = $this->scrollBox();

        return $box === null ? 0.0 : $box->maxScroll($this->mainAxis());
    }

    /** 滚到绝对偏移（用 Δ 表达 ⇒ 夹取仍只有 `ScrollLane::move()` 一条路径） */
    public function scrollTo(float $offset): void
    {
        $this->scrollBy($offset - $this->lane()->offset());
    }

    public function animateTo(float $offset, float $durationMs, Curve $curve): void
    {
        $this->lane()->animateTo($offset, $durationMs, $curve);
    }

    public function scrollAxis(): Axis
    {
        return $this->mainAxis();
    }

    /** 视口尺寸；盒没挂 ⇒ 零尺寸（翻页键据此不吞键） */
    public function viewportExtent(): Size
    {
        return $this->scrollBox()?->size() ?? Size::zero();
    }

    /** 只有主轴接受，交叉轴静默忽略（`Scrollable::scrollByAxis()`） */
    public function scrollByAxis(Axis $axis, float $delta): void
    {
        if ($axis !== $this->mainAxis()) {
            return;
        }

        $this->scrollBy($delta);
    }

    /** 滚轮专用：起飞前对齐、目标累加、点亮滚动条都在 `ScrollLane::glide()` */
    public function glideByAxis(Axis $axis, float $delta): void
    {
        if ($axis !== $this->mainAxis()) {
            return;
        }

        $this->lane()->glide($delta);
    }

    /**
     * 把内容坐标里的矩形滚进视口。
     *
     * ★ 算术全在 `RenderScrollView::revealDelta()`，这里只"找盒 → 走 `scrollBy()`"。
     * ★ 盒没挂（首帧之前 / 被换掉）⇒ 什么都不做：没有视口就没有"可见"可言，
     *   猜一个默认视口高只会把内容滚到错的位置。
     */
    public function ensureVisible(Rect $target, float $margin = 8.0): void
    {
        $box = $this->scrollBox();
        if ($box === null) {
            return;
        }

        // 基准传**自己待生效的偏移**：盒里那个是上一帧的值，同帧连按两次
        // Tab 会把上一段增量再算一遍（见 `revealDelta()` 的 `$base` 说明）。
        $delta = $box->revealDelta($target, $margin, $this->lane()->offset());
        if ($delta !== 0.0) {
            $this->scrollBy($delta);
        }
    }

    /** 偏移可能变了 —— 写入口之后叫一声句柄（去重由 `ScrollController::sync()` 负责） */
    protected function syncController(): void
    {
        $this->scrollController()?->sync();
    }
}
