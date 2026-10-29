<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Axis;
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
     * 按**轴**滚动 Δ（正 = 内容在该轴上负向移动，即"往下 / 往右看"）。
     *
     * ★ 为什么不是把 `scrollBy()` 改成两参：`scrollBy()` 一直是**主轴**语义
     *   （`ScrollView` 可以横向，那时它的主轴就是 x）—— 改成"必须传轴"会让
     *   既有的每次调用都要先知道控件的朝向，而那些调用点恰恰不知道。
     *   两条入口分工明确：`scrollBy()` = 主轴、`scrollByAxis()` = 指定轴。
     *
     * ★ 单轴容器收到另一个轴 ⇒ **什么都不做**（不是报错）：`ScrollInputRouter`
     *   按 `ScrollBarHost::scrollAxes()` 派发，正常不会问错；但"问一下没关系"
     *   是接口该有的宽容度 —— 报错只会让将来加轴向时的试探变成噪声。
     *
     * ★ 表（`TableState`）是第一个真的用得上的实现：横条拖动走这里，
     *   竖条/滚轮仍走 `scrollBy()`，两者共享同一份夹取。
     */
    public function scrollByAxis(Axis $axis, float $delta): void;

    /**
     * 按**轴**滚一段**带动画**的 Δ（滚轮专用；滚动条拖动**不该**用它）。
     *
     * ★ 与 `scrollByAxis()` 的分工是**语义**而不是"快慢"：
     *   - `scrollByAxis()` = **立即**（原语）。滚动条 thumb 拖动必须 1:1 跟手，
     *     `ensureVisible()`（Tab 跳转）必须当帧到位 —— 动画化会让"拖动漂移"、
     *     让"跳过去"变成"滑过去"而中间那几帧的锚点是错的。
     *   - `glideByAxis()` = **摊成若干帧**（滚轮）。滚轮是离散事件（一格 48px），
     *     瞬间跳一格在 120Hz 屏上就是"闪现"；摊开之后是"滑过去"。
     *
     * ★★ **为什么不直接把 `scrollByAxis()` 改成动画**：全仓 **81 处**调用依赖
     *   "调完就到"（21 个测试文件 + `ensureVisible` + thumb 拖动）。改语义等于
     *   让每一条都变成"跑 N 帧再断言"，那不是新增能力，是把原语拆了重装。
     *   所以：**原语不动，另开一条"要动画的"入口**。
     *
     * ★ 实现**必须**把每一帧的位移仍然交给 `scrollBy()`（`ScrollMotion` 只算
     *   "这一帧该到哪"）⇒ 夹取 / 换窗 / 锚点 / 提交编辑全部复用既有那一条路。
     *
     * ★ 轴问错 ⇒ 什么都不做（与 `scrollByAxis()` 同口径）。
     */
    public function glideByAxis(Axis $axis, float $delta): void;

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
