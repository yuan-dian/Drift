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
 * @api
 */
enum Overflow
{
    case Clip;
    case Scroll;
    case Wrap;
    case Report;
}
