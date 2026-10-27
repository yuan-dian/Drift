<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Axis;
use Drift\Geometry\Rect;

/**
 * **带滚动条**的盒（`RenderScrollView` / `RenderTable`）。
 *
 * ★ 存在的理由与 `Widget\Scrollable` 完全同源：滚轮路由 / 滚动条拖动原先写死
 *   `instanceof RenderScrollView` ⇒ 每加一种滚动容器就要回去改 `ScrollInputRouter`
 *   与 `ScrollBarFade`（框架核心认具体控件 = 依赖方向反了，而且漏改是**静默**的：
 *   Table 的条会画出来但抓不住）。收成接口后新增滚动器只实现本接口，两处不动。
 *
 * ★ **所有几何方法都带 `Axis`**（2026-10-27，阶段 5）。旧签名是"一个盒一根条"
 *   （`isVertical()` 定轴），表格接横向滚动时那个形状直接卡死：它需要两根条，
 *   而接口表达不了"第二根"。带轴之后 `scrollAxes()` 的返回值就是答案 ——
 *   单轴视口返回一个轴，表格返回两个，路由层一次循环即可，不需要知道谁是谁。
 *
 * ★ **不承诺"内容是什么形状"**：`RenderScrollView` 是一根轴上的内容平移；
 *   `RenderTable` 是"固定表头 + 双向平移"。接口只问"条该多长 / 在哪 / 拖多远"，
 *   两个实现各自算自己的 `maxScroll`（同一轴上）。
 *
 * ★ 轴不适用时各方法返回 `0.0` / `null`（而不是抛）：`scrollAxes()` 是"可能出条"
 *   的清单，实际出不出还取决于"内容溢出没有"—— 那个判断每帧都在变，报错会刷屏。
 *
 * ★ `setBarOpacity()` 在接口里是**不得已**：淡出动画（`ScrollBarFade`）在两次
 *   重建之间要就地改透明度（否则为了一个 alpha 每帧重建整棵滚动内容）。
 *   它是 `@internal` 语义 —— 应用不该调。
 *
 * @internal 只有滚动容器与框架的事件路由实现 / 使用它
 */
interface ScrollBarHost
{
    /**
     * 本盒会画条的轴，**按绘制顺序**（先画的在前）。
     *
     * ★ 顺序有视觉后果：表格先横后竖 ⇒ 竖条压在右下角上，与浏览器同观感。
     * ★ 它是"可能"，不是"当前有"：内容不溢出时 `thumbRect()` 返回 `null`。
     *
     * @return list<Axis>
     */
    public function scrollAxes(): array;

    /** thumb 矩形（**盒内**坐标）；`null` = 该轴不适用 / 内容不溢出 / 还没布局 */
    public function thumbRect(Axis $axis): ?Rect;

    /**
     * thumb 矩形换成**渲染树根坐标**（事件命中用）。
     *
     * ★ 为什么由实现给而不是调用方加：调用方要算它就得先拿到 `RenderBox`
     *   （`absoluteOrigin()` 只收那个类型），而这里要的恰好是"不依赖具体盒类"。
     *   把"绝对化"留在实现里一行，调用方就只认接口。
     */
    public function thumbRectInRoot(Axis $axis): ?Rect;

    /** thumb 在轨道里可走的距离（拖动映射的分母） */
    public function thumbTravel(Axis $axis): float;

    /** 该轴的可滚动上限（布局后才有意义；`0` = 不滚） */
    public function maxScroll(Axis $axis): float;

    /** 该轴实际生效的偏移（已按 `maxScroll` 夹取） */
    public function appliedOffset(Axis $axis): float;

    /** @internal `ScrollBarFade` 每帧把当前透明度推过来（不重建、不重排） */
    public function setBarOpacity(float $value): void;
}
