<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Rect;

/**
 * **能接收滚动增量**的 State（`ScrollView` / `LazyColumn`）。
 *
 * ★ 存在的理由只有一条：滚轮路由（`Application::dispatchInput()` 的
 *   `ScrollEvent` 分支）要"沿命中链找**最近的**滚动目标"。没有这个接口时
 *   那里写死 `instanceof ScrollViewState` ⇒ 每加一种滚动容器就得改
 *   `Application`（框架核心去认具体控件 = 依赖方向反了）。
 *
 * ★ **它不承诺"偏移存在哪"**：`ScrollView` 的偏移是纯 UI 态（放 `State`），
 *   而按 §12.3，**会被回收的**容器的滚动位置必须外置到 Store。接口只管
 *   "收到 Δ 怎么办"，不管它怎么存 —— 两条路都成立。
 *
 * @api
 */
interface Scrollable
{
    /**
     * 滚动 Δ（**逻辑像素**；正 = 内容上移，即"往下看"）。
     *
     * ★ 入参是像素不是"格"：格→像素换算发生在 `Application::dispatchInput()`
     *   （策略层），到这里的都已经是像素。
     *
     * ★ 实现必须只夹**下界 0**：上界要等布局后才知道内容多长，由盒在布局期夹
     *   （`RenderScrollView::appliedOffset()`）。在 State 里夹上界就等于"用上一帧
     *   的内容长度决定这一帧能不能滚"，内容变长后要多滚一帧才生效。
     */
    public function scrollBy(float $delta): void;

    /**
     * 把**内容坐标**里的矩形滚进视口，四周留 `$margin` 逻辑像素。
     *
     * ★ 坐标系是**内容坐标**（内容自己的坐标，与滚动偏移无关），不是视口坐标：
     *   视口坐标会随滚动变化 ⇒ 调用方得先知道当前偏移才能算，等于把滚动知识
     *   漏给每一处调用点。
     *
     * ★ **只滚最近的一个**（与滚轮路由同口径）：实现各自找自己的盒，不去通知
     *   外层 —— 嵌套滚动的裁决见 `04` §6.2（不做 delta 传递）。
     *
     * ★ **已经在视口里 ⇒ 什么都不做**（不标脏）：Tab 在可见区域里移动时不能
     *   因为"顺便检查一下"就每帧重建。夹具 `04` §12.2 #11 守这条。
     */
    public function ensureVisible(Rect $target, float $margin = 8.0): void;
}
