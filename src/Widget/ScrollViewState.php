<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Axis;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Traits\ScrollViewHost;

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
 * ★ `Scrollable` / `ScrollMetrics` 的读面（`maxOffset` / `scrollTo` / 轴判据 /
 *   视口读数 / `ensureVisible`）在 `ScrollViewHost`，与 `LazyList` 共用；
 *   本类只留"这一种容器怎么写偏移"。
 *
 * @api
 */
final class ScrollViewState extends State implements Scrollable, ScrollMetrics
{
    use ScrollViewHost;

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

    /** @internal 外部句柄进来时登记（挂载后调一次） */
    #[\Override]
    public function initState(): void
    {
        $this->scrollController()?->attach($this);
    }

    /** @internal 卸载即解绑：句柄留着死 State 会滚到虚空的盒上 */
    #[\Override]
    public function dispose(): void
    {
        $this->scrollController()?->detach($this);
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
     * ★ 夹取与"回写盒夹过的值"的契约收在 `ScrollLane::move()`（四个滚动实现共用
     *   一份，见那边的 ★★）。本方法只剩"找盒 → 交轴"这层策略。
     */
    public function scrollBy(float $delta): void
    {
        // ★ 盒还没挂（首帧之前 / 盒被换掉）⇒ 只改字段：`build()` 会把值递下去。
        $box = $this->scrollBox();
        $axis = $this->mainAxis();

        $this->lane()->move(
            $delta,
            static function (float $value) use ($box, $axis): float {
                return $box === null ? $value : $box->setScrollOffset($axis, $value);
            },
        );

        // 唯一的写入口 ⇒ 句柄通知只挂这里（滑行动画帧也经此处落地）。
        $this->syncController();
    }

    public function build(BuildContext $ctx): Widget
    {
        return ScrollViewBox::make(
            $this->lane()->offset(),
            $this->view()->isVertical(),
            $this->view()->child(),
            null,
            0.0,
            $this->lane()->fade()->opacity(),
        );
    }

    protected function mainAxis(): Axis
    {
        return $this->view()->isVertical() ? Axis::Vertical : Axis::Horizontal;
    }

    protected function scrollBox(): ?RenderScrollView
    {
        $box = $this->element()?->effectiveBoxOf(RenderScrollView::class);

        return $box instanceof RenderScrollView ? $box : null;
    }

    protected function scrollController(): ?ScrollController
    {
        return $this->view()->controllerValue();
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
