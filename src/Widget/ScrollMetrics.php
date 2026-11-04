<?php
declare(strict_types=1);

namespace Drift\Widget;

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
}
