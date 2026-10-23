<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * `Spinner` 的动画相位（纯 UI 态，§11.5 / §12.3）。
 *
 * ★ **相位只自增、取模在绘制时做**：`tick()` 调多少次都不回收、不溢出，
 *   渲染盒是 (直径, 相位, 颜色) 的纯函数 ⇒ 同输入必然同墨（§14.1 / G3）。
 *
 * ★ **没有 `build()`**（2026-10-23，`02` §6.2）：`Spinner` 走叶子路径
 *   （`createRenderObjectFromState()` 直接产盒）⇒ `build()` 永不被调用，
 *   基类的默认实现返回零尺寸就够。以前这里有一份
 *   `return SpinnerBox::make(...)`，随 `SpinnerBox` 一起删掉。
 *
 * @internal
 */
final class SpinnerState extends State
{
    private int $step = 0;

    /** 前进一步（`tickFrame()` 由帧时钟驱动；测试也可直接调） */
    public function tick(): void
    {
        $this->setState(function (): void {
            $this->step++;
        });
    }

    /**
     * 帧时钟驱动（§17.4.9）：每帧推进一步，并声明"我还要下一帧"。
     *
     * ★ 相位只自增、不取模（取模在绘制时做）⇒ "同输入同墨"仍成立（G3）：相位是
     *   **状态**，渲染盒是相位的纯函数。
     * ★ 卸载后没人再调它（走的是树遍历）⇒ 帧时钟自动停摆，不需要在 `dispose()`
     *   里记得注销任何东西。
     */
    #[\Override]
    public function tickFrame(float $timestampMs): bool
    {
        $this->tick();

        return true;
    }

    public function step(): int
    {
        return $this->step;
    }
}
