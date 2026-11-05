<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Axis;
use Drift\Geometry\Size;

/**
 * 滚动容器的**位置读数 + 绝对定位**（`ScrollController` 挂载时认的就是它）。
 *
 * ★ 与 `Scrollable` 分开：那个只承诺"收到 Δ 怎么办"，不承诺偏移存在哪
 *   （`ScrollView` 住 State、`Table` 外置到 Store）。本接口反过来说"有绝对偏移"，
 *   所以只能给确实持有偏移的容器实现。
 *
 * @api
 */
interface ScrollMetrics
{
    /** 当前偏移（逻辑像素，≥ 0） */
    public function offset(): float;

    /** 内容能滚到的最大偏移（`0` = 内容装得下 / 盒还没挂） */
    public function maxOffset(): float;

    /** 滚到绝对偏移（越界由盒按本次布局夹，不报错） */
    public function scrollTo(float $offset): void;

    /**
     * 主轴（`ScrollView` 可以横向 ⇒ 调用方不能假设是竖的）。
     *
     * ★ 存在的理由：`PageUp` / `PageDown` 要按**这个容器自己的**方向翻页。
     *   让调用方猜"是竖的" ⇒ 横向容器里翻页键静默变成空操作（`scrollByAxis()`
     *   问错轴什么都不做），那正是"按了没反应"里最难查的一种。
     */
    public function scrollAxis(): Axis;

    /**
     * 视口尺寸（逻辑像素，两轴）。
     *
     * - 翻页键要**主轴那一维**当步长。读盒而不是读 Widget 声明：视口高由布局
     *   决定（`Expanded` / `lines(n)` 都会改它）。
     * - 面积用来在「焦点为空」时认出**主视口**：一页里侧栏往往也是滚动容器，
     *   但它是窄条（见 `FocusManager::scrollTarget()`）。
     * - 盒没挂 ⇒ `Size::zero()`：调用方据此当成"没有可翻的内容"，不吞键。
     */
    public function viewportExtent(): Size;
}
