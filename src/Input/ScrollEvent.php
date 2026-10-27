<?php
declare(strict_types=1);

namespace Drift\Input;

use Drift\Geometry\Axis;

/**
 * 一次滚轮事件（§9.1 输入侧的滚动通道，2026-10-17 补）。
 *
 * ★ 它是 `PointerEvent` 的**兄弟事件**，不是指针的变体：滚轮没有按下/抬起相位、
 *   不参与焦点与命中光标（`dispatchInput` 的指针分支）—— 它只回答一件事：
 *   "光标下的可滚动容器该滚多少"。把 delta 塞进 `PointerEvent` 会让"一次命中、
 *   三方消费"的口径多一个相位分支（A15：不预先发明没消费者的相位）。
 *
 * ★ 坐标是**逻辑单位**、相对视口左上角（§6.1），与 `PointerEvent` 同坐标系 ——
 *   于是 `dispatchInput` 复用同一次 `hitTest` 找到光标下的容器。
 *
 * ★ **单位是"格"不是像素**：平台只做量化（`WHEEL_DELTA/120`，§6.1）；
 *   "一格值多少 px" 是框架策略（`InputRouter::WHEEL_NOTCH_PX`），
 *   在 `InputRouter::dispatch()` 换算成像素后才进 `scrollBy*()`。
 *
 * ★ **轴是"用户要哪根"而不是"容器有哪根"**（2026-10-27 阶段 5b）：触控板横滑
 *   与 Shift+竖轮都是"我要横向"，与落点容器支不支持无关。容器支不支持由
 *   `Scrollable::scrollByAxis()` 回答（问错轴 ⇒ 什么都不做）。
 *   修饰键在**壳里**就翻译成轴了 —— 否则每个消费方都要自己再判一次 Shift。
 *
 * ★ **符号**：`$delta` 正 = 沿该轴正向滚（纵向"往下看" / 横向"往右看"）。
 *   纵向的取反在壳里（用户要的手感与系统默认相反），横向不取反（与系统默认一致）。
 *
 * @api
 */
final readonly class ScrollEvent implements InputEvent
{
    /**
     * @param float $x     光标 x（逻辑单位，视口坐标）
     * @param float $y     光标 y（逻辑单位，视口坐标）
     * @param float $delta 滚动量（"格"，WHEEL_DELTA=120 为一格；正 = 往正方向看）
     * @param Axis  $axis  要滚哪根轴（缺省纵向 —— 绝大多数滚轮都是纵向）
     */
    private function __construct(
        public float $x,
        public float $y,
        public float $delta,
        public Axis $axis,
    ) {
    }

    /**
     * @param null|Axis $axis `null` = 纵向。**不用 `Axis::Vertical` 当默认值**：
     *   enum 常量作默认参数在 AOT 侧没有先例，`?Axis + ??` 是既有的稳写法。
     */
    public static function make(float $x, float $y, float $delta, ?Axis $axis = null): self
    {
        return new self($x, $y, $delta, $axis ?? Axis::Vertical);
    }
}
