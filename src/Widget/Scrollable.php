<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Axis;
use Drift\Geometry\Rect;

/**
 * 能接收滚动增量的 State（`ScrollView` / `LazyColumn`）。
 *
 * 滚轮路由沿命中链找最近的滚动目标，靠本接口而非 `instanceof` 具体 State。
 * 接口只管"收到 Δ 怎么办"，偏移存 UI 态还是 Store 由实现决定。
 *
 * @api
 */
interface Scrollable
{
    /**
     * 滚动 Δ（逻辑像素；正 = 内容上移）。格→像素换算在策略层已完成。
     * 实现只夹下界 0；上界由盒在布局期夹，State 里夹会用上一帧的长度。
     */
    public function scrollBy(float $delta): void;

    /**
     * 按轴滚动 Δ（正 = 内容在该轴负向移动）。`scrollBy()` = 主轴，本方法 = 指定轴。
     * 单轴容器收到另一个轴时静默忽略，不报错。
     */
    public function scrollByAxis(Axis $axis, float $delta): void;

    /**
     * 按轴滚一段带动画的 Δ（滚轮专用；滚动条 thumb 拖动与 `ensureVisible()` 用立即版）。
     * 每帧位移仍交给 `scrollBy()`，复用夹取 / 换窗 / 锚点 / 提交编辑那一条路。
     * 轴问错时静默忽略。
     */
    public function glideByAxis(Axis $axis, float $delta): void;

    /**
     * 把内容坐标（与滚动偏移无关）里的矩形滚进视口，四周留 `$margin` 逻辑像素。
     * 只滚最近的一个滚动宿主；目标已在视口内则不标脏。
     */
    public function ensureVisible(Rect $target, float $margin = 8.0): void;
}
