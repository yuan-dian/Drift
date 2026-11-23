<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * 溢出策略：容器装不下时必须选一种舍弃方式。
 *
 * `Clip` 是默认值，但会超的容器（Row / AppBar / StatusBar / Dock 槽 / 自绘容器）
 * 必须显式声明；`Report` 违反即 Fatal。
 *
 * `Scroll` 是行为声明，不是调度开关：真正裁剪由 `RenderScrollView::paintContent()`
 * 自己做 `pushClip()`，不走 `clipsOverflow()`（默认 false，故意与本枚举相反）。
 * `RenderFlex` / `RenderDock` 不支持 Scroll，会响亮失败并指路 `ScrollView`。
 *
 * `Wrap` 是重排而非舍弃，不是本枚举的值；真有"子项数运行时才知道 + 自动换行"
 * 的需求时，应做独立的两遍布局原语。
 *
 * @api
 */
enum Overflow
{
    case Clip;
    case Scroll;
    case Report;
}
