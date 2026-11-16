<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Layout\RenderScrollView;

/**
 * `ScrollView` 的纯 UI 态：**滚动偏移**（§11.5 / §12.3）。
 *
 * ★ **为什么偏移在 `State` 而不是 Store**：§11.5 的判据是"跨 build 活、且只影响
 *   这一棵子树"—— 滚动位置正是这一类。★ **外置到 Store 只对"会被虚拟化回收"的
 *   容器成立**（§12.3 明写：页面级滚动位置放 `State` **完全没问题**；判据是
 *   "承载它的 Element 会不会被回收"）。本容器不做虚拟化 ⇒ 放 `State`。
 *
 * ★ **唯一的写入口是 `scrollBy()`** ⇒ 夹取只有一处，不会出现"两处各自夹、
 *   边界不一致"。当前的生产调用者是 `Application::dispatchInput()` 的
 *   `ScrollEvent` 分支（滚轮）；键盘 / 触摸滚动等输入通道到位后走同一个入口。
 *
 * @api
 */
final class ScrollViewState extends State implements Scrollable, ScrollMetrics
{
    /**
     * 这根轴的滚动状态机（偏移 + 动画 + 淡出）。
     *
     * ★ 懒建只是为了让"从没滚过的视口"不多造两个对象；`ScrollLane` 与
     *   `ScrollBarFade` 内部都是懒的 ⇒ 建了也不改任何行为（`opacity()` 恒 1.0、
     *   `tick()` 恒 `false`）。**没有"有没有滚过"这个额外状态**，别再造一个。
     */
    private ?ScrollLane $lane = null;

    /** 当前偏移（逻辑像素，≥ 0） */
    public function offset(): float
    {
        return $this->lane()->offset();
    }

    /** 内容能滚到的最大偏移（盒没挂 ⇒ 0）。每次问盒：内容长度随布局变，缓存就是"用上一帧回答这一帧" */
    public function maxOffset(): float
    {
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        if ($box === null) {
            return 0.0;
        }

        return $box->maxScroll($this->view()->vertical() ? Axis::Vertical : Axis::Horizontal);
    }

    /** 滚到绝对偏移（用 Δ 表达 ⇒ 夹取仍只有 `ScrollLane::move()` 一条路径） */
    public function scrollTo(float $offset): void
    {
        $this->scrollBy($offset - $this->offset());
    }

    #[\Override]
    public function animateTo(float $offset, float $durationMs, Curve $curve): void
    {
        $this->lane()->animateTo($offset, $durationMs, $curve);
    }

    /** 主轴：由 Widget 声明决定（同一个 State 既可能纵向也可能横向） */
    public function scrollAxis(): Axis
    {
        return $this->view()->vertical() ? Axis::Vertical : Axis::Horizontal;
    }

    /** 视口尺寸；盒没挂 ⇒ 零尺寸（翻页键据此不吞键） */
    public function viewportExtent(): Size
    {
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);

        return $box?->size() ?? Size::zero();
    }

    /** @internal 外部句柄进来时登记（挂载后调一次） */
    #[\Override]
    public function initState(): void
    {
        $this->view()->controllerValue()?->attach($this);
    }

    /** @internal 卸载即解绑：句柄留着死 State 会滚到虚空的盒上 */
    #[\Override]
    public function dispose(): void
    {
        $this->view()->controllerValue()?->detach($this);
    }

    /**
     * 滚动 Δ（**逻辑像素**；正 = 内容上移，即"往下看"）。
     *
     * ★ 入参是像素不是"格"：`ScrollEvent::$delta` 的格→像素换算发生在
     *   `Application::dispatchInput()`（策略层），到这里的都已经是像素。
     *
     * ★ 夹取只做**下界 0**（不看内容）：上界在盒里按本次布局的 `maxScroll` 夹
     *   （`RenderScrollView::appliedOffset()`）—— 因为只有布局后才知道内容多长。
     *   ⇒ 滚到头再滚不会飞走；内容变短了（列表过滤）下一帧自动回弹。
     *
     * ★★ **不调 `setState()`**（2026-10-27，A 批第 1 项）：偏移只是**两个数**，
     *   它不改"有哪些子"。旧实现每次滚动都重建整棵滚动内容 —— 实测（AOT，
     *   `bench` 虚拟化表格档）`settle`（重建）占滚动帧的 55%。
     *   现在走"推给盒 + 标重排"：盒就地改偏移、本帧重排重画，Widget 树一动不动。
     *   ⇒ 滚动帧 11.68ms → **7.21ms**，其中 `settle` 6.74 → 0.83ms（−88%）。
     *
     * ★ 夹取与"回写盒夹过的值"的契约收在 `ScrollLane::move()`（三个滚动实现共用
     *   一份，见那边的 ★★）。本方法只剩"找盒 → 交轴"这层策略。
     */
    public function scrollBy(float $delta): void
    {
        // ★ 盒还没挂（首帧之前 / 盒被换掉）⇒ 只改字段：`build()` 会把值递下去。
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        $axis = $this->view()->vertical() ? Axis::Vertical : Axis::Horizontal;

        $this->lane()->move(
            $delta,
            static function (float $value) use ($box, $axis): float {
                return $box === null ? $value : $box->setScrollOffset($axis, $value);
            },
        );

        // 唯一的写入口 ⇒ 句柄通知只挂这里（滑行动画帧也经此处落地）。
        $this->view()->controllerValue()?->sync();
    }

    /**
     * 按轴滚动：**只有主轴接受**，交叉轴忽略（见 `Scrollable::scrollByAxis()`）。
     *
     * ★ 主轴由 Widget 决定（`ScrollView::vertical()`），不由调用方猜 —— 同一个
     *   `ScrollViewState` 既可能是纵向也可能是横向。
     */
    public function scrollByAxis(Axis $axis, float $delta): void
    {
        if (($axis === Axis::Vertical) !== $this->view()->vertical()) {
            return;
        }

        $this->scrollBy($delta);
    }

    /**
     * 按轴滚动，**带动画**（滚轮专用；细节见 `Scrollable::glideByAxis()`）。
     *
     * ★ 本方法只剩"轴对不对"这一层判据 —— 起飞前对齐、目标累加、点亮条都在
     *   `ScrollLane::glide()`（三个实现共用一份）。
     */
    public function glideByAxis(Axis $axis, float $delta): void
    {
        if (($axis === Axis::Vertical) !== $this->view()->vertical()) {
            return;
        }

        $this->lane()->glide($delta);
    }

    /** 这根轴的滚动状态机（懒建：从没滚过的视口不建） */
    private function lane(): ScrollLane
    {
        $lane = $this->lane;
        if ($lane === null) {
            $lane = new ScrollLane(0.0, null, $this);
            $this->lane = $lane;
        }

        return $lane;
    }

    /**
     * 推进这一帧的**滚动动画**与**滚动条淡出**。
     *
     * ★ 顺序：先滚动、后淡出。`scrollBy()` 里会 `noteScroll()`（把条打回全亮），
     *   反过来就会"这一帧先按旧空闲时长淡一点、再被滚动打回全亮"—— 抖动。
     *
     * ★ 返回值是"还要下一帧吗"，由 `Application` 聚合进 `animating` ⇒
     *   帧末 `continueFrame()` 据此 `scheduleNextFrame()`；不需要在等待层加判据。
     */
    #[\Override]
    public function tickFrame(float $timestampMs): bool
    {
        $lane = $this->lane();

        $busy = $lane->tick($timestampMs, function (float $delta): void {
            $this->scrollBy($delta);
        });

        if ($lane->fade()->tickFor($this, $timestampMs)) {
            $busy = true;
        }

        return $busy;
    }

    /**
     * 把内容坐标里的矩形滚进视口（`04` §6.3）。
     *
     * ★ 算术全在 `RenderScrollView::revealDelta()`，这里只做"找盒 → 交给
     *   `scrollBy()`"。`LazyListState` 是**同一形状** —— 两个实现不各算一遍。
     *
     * ★ 还没挂盒（首帧之前 / 盒被换掉）⇒ **什么都不做**：没有视口就没有
     *   "可见"可言。不猜一个默认视口高 —— 猜错就是滚到错的位置，比不滚更坏。
     */
    public function ensureVisible(Rect $target, float $margin = 8.0): void
    {
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        if ($box === null) {
            return;
        }

        // ★ 基准传**自己待生效的偏移**：盒里那个是上一帧的值，同一帧连按两次
        //   Tab 会把上一段增量再算一遍（见 `revealDelta()` 的 `$base` 说明）。
        $delta = $box->revealDelta($target, $margin, $this->lane()->offset());
        if ($delta !== 0.0) {
            $this->scrollBy($delta);
        }
    }

    public function build(BuildContext $ctx): Widget
    {
        return ScrollViewBox::make(
            $this->lane()->offset(),
            $this->view()->vertical(),
            $this->view()->child(),
            null,
            0.0,
            $this->lane()->fade()->opacity(),
        );
    }

    private function view(): ScrollView
    {
        $widget = $this->widget();
        if (!$widget instanceof ScrollView) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('ScrollViewState 只能配 ScrollView（框架内部不变量被破坏）');
        }

        return $widget;
    }
}
