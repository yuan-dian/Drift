<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 无障碍客户端要求**滚动**一个语义节点（UIA `IScrollProvider::Scroll` /
 * `SetScrollPercent`）。
 *
 * ★ 两轴载荷都是**目标百分比**（0..100），`-1` = 该轴不动。壳已把 `Scroll()`
 *   的方向/步进折算成百分比 —— 与 `SetScrollPercent` 共用一条写回路径，PHP 侧
 *   只需按百分比定位，不必再区分"步进"和"绝对"两种语义。
 * ★ 消费方：`Application::dispatchInput()` ⇒ `setSemanticsScroll()`，沿命中链找
 *   实现 `ScrollMetrics` 的 `State`，换算成偏移后 `scrollTo()`。
 * ★ 走队列的理由同 `SemanticsSetValueEvent`（COM RPC 线程不能重入 PHP）。
 *
 * @api
 */
final readonly class SemanticsScrollEvent implements InputEvent
{
    /**
     * @param int $index 语义节点索引
     * @param float $horizontalPercent 水平目标百分比（`-1` = 不动）
     * @param float $verticalPercent 垂直目标百分比（`-1` = 不动）
     */
    public function __construct(
        public int $index,
        public float $horizontalPercent,
        public float $verticalPercent,
    ) {
    }
}
