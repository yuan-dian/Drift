<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Widget\State;

/**
 * 加载指示器 —— §11.5 三态的 `Loading` 分支（§A.2 样板 3 处引用的目标形态）。
 *
 * ```php
 * LoadStatus::Loading => Spinner::make(),
 * ```
 *
 * ★ **为什么是 `StatefulWidget`**：动画相位是纯 UI 态（§11.5）且必须跨 build 活
 *   ⇒ 住 `State`；Widget 只持直径（§13.2：渲染盒只读 Widget 字段，态经
 *   `SpinnerBox` 递进去 —— 与 `TextField → TextFieldState → TextFieldBox` 同形态）。
 *
 * ★ **动画驱动力今天不在框架里**（诚实边界，不是待办）：`FrameClock` 属 M4
 *   （§19）⇒ `SpinnerState::tick()` 当前由宿主 / 测试调；M4 帧时钟到位后自动转，
 *   控件形状不动。★ 连带边界：§11.5 已记 —— M1–M3 同步 IO 下 `loading` 态本身
 *   画不出来（同栈无帧可渲染），本控件是异步后端（M4）就位后的可见形态。
 *
 * @api
 */
final class Spinner extends StatefulWidget
{
    private float $diameter = 24.0;

    public static function make(): self
    {
        return new self();
    }

    /** 逻辑直径（DIP）。约束比它小 ⇒ 按实际盒尺寸缩小绘制，不报 C6 */
    public function diameter(float $diameter): static
    {
        $this->mutate();
        $this->diameter = $diameter;

        return $this;
    }

    /** @internal `SpinnerState::build()` 读它 */
    public function diameterValue(): float
    {
        return $this->diameter;
    }

    public function createState(): State
    {
        return new SpinnerState();
    }
}
