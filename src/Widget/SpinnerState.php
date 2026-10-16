<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * `Spinner` 的动画相位（纯 UI 态，§11.5 / §12.3）。
 *
 * ★ **相位只自增、取模在绘制时做**：`tick()` 调多少次都不回收、不溢出，
 *   渲染盒是 (直径, 相位, 颜色) 的纯函数 ⇒ 同输入必然同墨（§14.1 / G3）。
 *
 * @internal
 */
final class SpinnerState extends State
{
    private int $step = 0;

    /** 前进一步（宿主 / 测试驱动；M4 起由 `FrameClock` 驱动） */
    public function tick(): void
    {
        $this->setState(function (): void {
            $this->step++;
        });
    }

    public function step(): int
    {
        return $this->step;
    }

    public function build(BuildContext $ctx): Widget
    {
        return SpinnerBox::make($this->spinner()->diameterValue(), $this->step);
    }

    private function spinner(): Spinner
    {
        $widget = $this->widget();
        if (!$widget instanceof Spinner) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('SpinnerState 只能配 Spinner（框架内部不变量被破坏）');
        }

        /** @var Spinner $widget */
        return $widget;
    }
}
