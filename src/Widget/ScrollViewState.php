<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

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
final class ScrollViewState extends State
{
    private float $offset = 0.0;

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
     */
    public function scrollBy(float $delta): void
    {
        $next = max(0.0, $this->offset + $delta);

        if ($next === $this->offset) {
            return;   // 已在边界：不标脏（省一次重建）
        }

        $this->setState(function () use ($next): void {
            $this->offset = $next;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return ScrollViewBox::make($this->offset, $this->view()->vertical(), $this->view()->child());
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
