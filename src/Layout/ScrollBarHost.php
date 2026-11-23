<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Axis;
use Drift\Geometry\Rect;

/**
 * 带滚动条的盒（`RenderScrollView` / `RenderTable`），内部实现与事件路由用。
 *
 * 几何方法一律带 `Axis`：同一盒可能画多根条，新增滚动容器也不该改路由。
 * 接口只问"条多长 / 在哪 / 拖多远"，内容形状由实现自己算。
 * 轴不适用时返回 `0.0` / `null`，不抛；`setBarOpacity()` 仅为淡出动画就地改透明度。
 *
 * @internal 只有滚动容器与框架的事件路由实现 / 使用它
 */
interface ScrollBarHost
{
    /**
     * 本盒会画条的轴，按绘制顺序（先画在前）。
     * 是"可能出条"的清单，内容不溢出时 `thumbRect()` 返回 null。
     *
     * @return list<Axis>
     */
    public function scrollAxes(): array;

    /** thumb 矩形（**盒内**坐标）；`null` = 该轴不适用 / 内容不溢出 / 还没布局 */
    public function thumbRect(Axis $axis): ?Rect;

    /**
     * thumb 矩形换成渲染树根坐标（事件命中用）。
     * 绝对化由实现负责，调用方只认接口，不必拿具体 RenderBox。
     */
    public function thumbRectInRoot(Axis $axis): ?Rect;

    /** thumb 在轨道里可走的距离（拖动映射的分母） */
    public function thumbTravel(Axis $axis): float;

    /** 该轴的可滚动上限（布局后才有意义；`0` = 不滚） */
    public function maxScroll(Axis $axis): float;

    /** 该轴实际生效的偏移（已按 `maxScroll` 夹取） */
    public function appliedOffset(Axis $axis): float;

    /**
     * 就地改滚动偏移：不重建 Widget 树、不重排，只重画。
     *
     * 调用方必须拿返回值回写自己的字段，否则内容变短后旧值长期大于上限，
     * 后续增量会先消耗死区（表现为拖条画面不动）。
     * 实现必须把新偏移应用到子盒 `offset`：paint 门控与绘制读的都是它；
     * 只改字段或借 `markNeedsLayout()` 都会让画面停在旧位置且白耗一次全树重排。
     *
     * @param float $value 目标偏移（内容坐标，调用方已夹下界 0）
     * @return float 实际生效的偏移（已按 maxScroll 夹取）
     * @internal 只有 `Scrollable` 的 State 实现调它
     */
    public function setScrollOffset(Axis $axis, float $value): float;

    /** @internal `ScrollBarFade` 每帧把当前透明度推过来（不重建、不重排） */
    public function setBarOpacity(float $value): void;
}
