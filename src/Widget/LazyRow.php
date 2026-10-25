<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * **按视口造子层**的横向列表（虚拟化，`LazyColumn` 的横版）。
 *
 * ```php
 * LazyRow::make(160.0)                                    // 每张卡片 160 宽
 *     ->items($cards, fn(Card $c): Widget => CardView::make($c))
 *     ->overscan(160.0);                                  // 视口外多造一张
 * ```
 *
 * ★ **与 `LazyColumn` 只差一根轴**：窗口换算、锚点保持、`sliceOrigin`、
 *   可滚上限全部在 `LazyList` / `LazyListState` 一处 —— 本类只回答
 *   "主轴是 x、子层是 `Row`、每格定宽"。**不要**照着 `LazyColumn` 抄第二份
 *   （两份拷贝意味着每条真机教训修两次，而"两处各写一遍必然漂"是本仓老账）。
 *
 * ★ **滚轮照样能滚**：滚轮路由（`ScrollInputRouter::scrollAt()`）找的是**最近的
 *   `Scrollable`** 并只递一个标量 Δ —— 轴在 `LazyListState` 里，不在事件里。
 *   ⇒ 在横向条上滚滚轮 = 横向滚动（浏览器/桌面应用的常见行为）。
 *   ★ 别去壳里找"横向滚轮"：`WM_MOUSEHWHEEL` 全仓没有消费者（倾斜滚轮不是
 *   每只鼠标都有），横向滚动条 thumb 拖动也走同一套 `Scrollable`。
 *
 * ★ 与 `LazyColumn` 相同的一条边界：**子项内不能有"随滚动保留的 UI 态"**
 *   （子项 Element 滚出窗口即回收，虚拟化的定义）。
 *
 * @api
 */
final class LazyRow extends LazyList
{
    /**
     * @param float $itemExtent 每格的宽度（逻辑像素，**必须 > 0**）
     */
    public static function make(float $itemExtent): self
    {
        self::checkExtent($itemExtent);

        return new self($itemExtent, false);
    }

    public function createState(): State
    {
        return new LazyListState();
    }
}
