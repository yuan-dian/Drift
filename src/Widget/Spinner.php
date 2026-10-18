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
 * ★ **动画驱动力已就位**（2026-10-18）：`SpinnerState::tickFrame()` 由
 *   `Application::frame()` 每帧遍历树调用（§17.4.9），相位随帧推进；它返回 true
 *   让 `wantsFrame()` 保持为真 ⇒ 帧时钟不停 ⇒ 自己转，控件卸载后自动停。
 *   ★ 连带边界：§11.5 已记 —— 同步 IO 后端下 `loading` 态本身画不出来（同栈无帧
 *   可渲染）；装上 `ProcessTaskHost` 之后才真的看得见（见 `apps/users`）。
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
