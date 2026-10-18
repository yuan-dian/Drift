<?php
declare(strict_types=1);

namespace Drift\Widget;

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
}
