<?php
declare(strict_types=1);

namespace Drift\Input;

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
 * ★ **符号约定**：`$delta` 正 = 内容上移（"往下看"），负 = 内容下移（"往上看"）。
 *   与 `ScrollViewState::scrollBy()` 完全一致（滚轮向前 = 看下面 = 正）。
 *   平台侧的 `WM_MOUSEWHEEL` 正值（向前滚）在这里就是正。
 *
 * ★ **单位是"格"不是像素**：平台只做量化（`WHEEL_DELTA/120`，§6.1）；
 *   "一格值多少 px" 是框架策略（`Application::WHEEL_NOTCH_PX`），
 *   在 `dispatchInput()` 换算成像素后才进 `scrollBy()`。
 *
 * @api
 */
final readonly class ScrollEvent implements InputEvent
{
    /**
     * @param float $x     光标 x（逻辑单位，视口坐标）
     * @param float $y     光标 y（逻辑单位，视口坐标）
     * @param float $delta 滚动量（"格"，WHEEL_DELTA=120 为一格；正 = 往下看）
     */
    private function __construct(
        public float $x,
        public float $y,
        public float $delta,
    ) {
    }

    public static function make(float $x, float $y, float $delta): self
    {
        return new self($x, $y, $delta);
    }
}
