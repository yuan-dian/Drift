<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * 溢出策略（§13.1 C5）：容器装不下时**必须选一种舍弃方式**。
 *
 * ★ `Clip` 是默认值，但**默认值不许替会超的容器决定**。判据一句话：
 *   "这个容器在约束不足时，是否必须选择一种舍弃方式？" 是 ⇒ 显式声明。
 *   会超的是 `Row` / `AppBar` / `StatusBar` / `Dock` 槽 / 自绘容器；
 *   `Text` / `Icon` / `Padding` 这类尺寸由内容或子决定的，用默认值即可。
 *
 * ★ `Report` 不是"不处理"——它响亮报错（C6 同款：违反 ⇒ Fatal）。
 *
 * ★★ **`Scroll` 是行为声明，不是调度开关**（2026-10-25 澄清）：全仓**没有**
 *   "按 `overflow()` 的值分支"的读者 —— `RenderScrollView::overflow()` 返回它，
 *   但真正的裁剪是它自己在 `paintContent()` 里 `pushClip()` 做的，**不走**
 *   `clipsOverflow()`。`overflow()`（声明策略）与 `clipsOverflow()`（裁剪开关，
 *   默认 `false` 且**故意与这里的默认 `Clip` 相反**）是两件事。
 *   ⇒ 看到"返回 `Scroll` 却没覆写 `clipsOverflow()`"**不是**缺陷，别急着"修"。
 *   ★ `RenderFlex` / `RenderDock` 上**不支持** `Scroll`（会响亮失败并指路
 *   `ScrollView`）：容器自己滚与 `ScrollView` 职责重叠，`04` §1058 已否决。
 *
 * ★★★ **`Wrap` 已删（2026-10-25，I4）** —— 它是**类别错误**，不是"还没做"：
 *   本枚举的语义是**舍弃方式**（装不下就丢掉一部分），而 `Wrap` 是**重排**
 *   （换行继续画，一个像素都不丢）。硬塞进来的后果是 `RenderFlex` / `RenderDock`
 *   各躺一个 `=> Errors::report('尚未实现')` 的死分支 —— 那正是
 *   "未被消费的分支 = 未实现"要清掉的东西。
 *   ★ 推翻条件（`04` §4.4 已写死）：出现"**子项数运行时才知道 + 必须自动换行**"
 *   的真实需求。届时它是一个**独立的布局原语**（需要两遍布局：先量全部子的固有宽
 *   才知道一行放几个），不是本枚举的一个值 —— `RenderFlex` 是单遍的。
 *
 * @api
 */
enum Overflow
{
    case Clip;
    case Scroll;
    case Report;
}
