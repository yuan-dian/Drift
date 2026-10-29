<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Geometry\Rect;
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
final class ScrollViewState extends State implements Scrollable
{
    private float $offset = 0.0;

    /** 滚动条淡出节律（懒建；`null` = 这个视口还没滚过） */
    private ?ScrollBarFade $barFade = null;

    /** 滚轮的动画节律（懒建；`null` = 这个视口没被滚轮滚过） */
    private ?ScrollMotion $motion = null;

    /** 当前偏移（逻辑像素，≥ 0） */
    public function offset(): float
    {
        return $this->offset;
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
     * ★ 回写**盒夹过的值**（不是 `$next`）：内容变短时 State 里那个"增量累加值"
     *   会长期大于上限，之后每笔增量都先消耗死区 —— 真机表现是"滚轮转了画面不动"。
     */
    public function scrollBy(float $delta): void
    {
        $next = max(0.0, $this->offset + $delta);

        if ($next === $this->offset) {
            return;   // 已在边界：不推、不重排（省一帧）
        }

        $this->bar()->noteScroll();

        // ★ 盒还没挂（首帧之前 / 盒被换掉）⇒ 只改字段：`build()` 会把值递下去。
        /** @var RenderScrollView|null $box */
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);
        if ($box === null) {
            $this->offset = $next;

            return;
        }

        $this->offset = $box->setScrollOffset(
            $this->view()->vertical() ? Axis::Vertical : Axis::Horizontal,
            $next,
        );
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
     * ★ 起飞前对齐：motion 不在跑时，先 `snapTo($this->offset)` —— 这个 offset
     *   可能刚被 `scrollBy()` / `ensureVisible()` / 盒夹取改过，motion 里那个
     *   名义值已经过期；不对齐就会"从旧位置滑到新目标"，画面跳一下。
     *   ★ 在跑时**不对齐**：连续滚轮必须让目标累加（对齐会打断正在跑的动画）。
     */
    public function glideByAxis(Axis $axis, float $delta): void
    {
        if (($axis === Axis::Vertical) !== $this->view()->vertical()) {
            return;
        }

        $motion = $this->motion();
        if (!$motion->isAnimating()) {
            $motion->snapTo($this->offset);
        }
        $motion->glideBy($delta);

        // 动画可能首帧还不推进（`AnimatedValue` 首帧只记起点）⇒ 条要现在就亮，
        // 否则"滚了但条没反应"要等第二帧。
        $this->bar()->noteScroll();
    }

    /**
     * 滚动的动画节律（懒建：没被滚轮滚过的视口不建）。
     */
    private function motion(): ScrollMotion
    {
        $motion = $this->motion;
        if ($motion === null) {
            $motion = new ScrollMotion($this->offset);
            $this->motion = $motion;
        }

        return $motion;
    }

    /**
     * 滚动条的显隐节律（懒建：没滚过的视口不建，滚动条恒亮）。
     */
    private function bar(): ScrollBarFade
    {
        $bar = $this->barFade;
        if ($bar === null) {
            $bar = new ScrollBarFade();
            $this->barFade = $bar;
        }

        return $bar;
    }

    /**
     * 推进这一帧的**滚动动画**与**滚动条淡出**。
     *
     * ★ 顺序：先滚动、后淡出。`scrollBy()` 里会 `noteScroll()`（把条打回全亮），
     *   反过来就会"这一帧先按旧空闲时长淡一点、再被滚动打回全亮"—— 抖动。
     *
     * ★ 返回值是"还要下一帧吗"，由 `Application` 聚合进 `animating` ⇒
     *   `wantsFrame()` 自动命中"有动画在跑"。**不需要给 `wantsFrame()` 加判据。**
     */
    #[\Override]
    public function tickFrame(float $timestampMs): bool
    {
        $busy = false;

        $motion = $this->motion;
        if ($motion !== null) {
            $next = $motion->tick($timestampMs);
            if ($next !== null) {
                $before = $this->offset;
                $this->scrollBy($next - $before);

                // 推不动了（到边界 / 内容变短被夹）⇒ 就地收口。否则 motion 会继续朝
                // 一个**到不了**的目标收敛：指数衰减保证它终会停，但要多转一百多帧
                // （实测：收口后 81 帧落闸，不收口 190 帧）—— 每帧都白跑一次
                // `tickFrame` + 一次全量 paint，滚动条也跟着多亮一百多帧。
                if ($this->offset === $before) {
                    $motion->snapTo($before);
                }
                $busy = true;
            }
        }

        $bar = $this->barFade;
        if ($bar !== null && $bar->tickFor($this, $timestampMs)) {
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
        $delta = $box->revealDelta($target, $margin, $this->offset);
        if ($delta !== 0.0) {
            $this->scrollBy($delta);
        }
    }

    public function build(BuildContext $ctx): Widget
    {
        $bar = $this->barFade;
        $opacity = 1.0;
        if ($bar !== null) {
            $opacity = $bar->opacity();
        }

        return ScrollViewBox::make(
            $this->offset,
            $this->view()->vertical(),
            $this->view()->child(),
            null,
            0.0,
            $opacity,
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
