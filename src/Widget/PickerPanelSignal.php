<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\State\Subject;

/**
 * 选择器面板的广播端 —— `DatePicker` / `DateTimePicker` **共用**。
 *
 * ★ 只服务控件私有态（翻到哪个月）。受控值那条走框架的 `overlaySignature()`，
 *   别往这里塞 —— 一个信号两种职责，下一个人就分不清"这条为什么没生效"。
 * ★ 与 `SelectPanelSignal` 分开而不是共用：两者的属主生命周期不同（`Select`
 *   的 State 常驻在页面上，选择器的 State 挂在浮层锚点上），合用一个会让
 *   "谁在广播"变得不可判。
 * ★ **独立成文件**（不复用 `Select.php` 那种"顺手定义在同文件里"的写法）：
 *   autoload 是"类名 → 同名文件"，同文件的第二个类只有在第一个先被加载时才存在。
 *   测试夹具里先 new 信号就会 `Class not found`（2026-10-29 实测踩到）。
 *
 * @internal
 */
final class PickerPanelSignal extends Subject
{
}
