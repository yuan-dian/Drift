<?php
declare(strict_types=1);

namespace App\Showcase\Stores;

use Drift\State\Cell;

/**
 * 按钮示例页的应用级状态。
 *
 * ★ 状态与动作住这里，页面只做绑定：读点仍落在读它的 `Computed` / `Text` 上，
 *   Store 本身不是 State，不参与重建。
 * ★ 实例由组合根创建一次（`ShowcaseApplication::__construct`），经页面工厂注入，
 *   跨页共享 —— 切走再切回，计数还在。
 */
final class ButtonsStore
{
    public readonly Cell $enters;

    public readonly Cell $leaves;

    public readonly Cell $lastPressed;

    public function __construct()
    {
        $this->enters = Cell::of(0)->named('enters');
        $this->leaves = Cell::of(0)->named('leaves');
        $this->lastPressed = Cell::of('（还没按过）')->named('lastPressed');
    }

    public function hover(bool $entered): void
    {
        if ($entered) {
            $this->enters->set((int) $this->enters->peek() + 1);

            return;
        }
        $this->leaves->set((int) $this->leaves->peek() + 1);
    }

    public function press(): void
    {
        $this->lastPressed->set('点击我（此前 enters=' . $this->enters->peek() . '）');
    }

    public function hoverText(): string
    {
        return 'enters=' . $this->enters->get() . '  leaves=' . $this->leaves->get();
    }
}
