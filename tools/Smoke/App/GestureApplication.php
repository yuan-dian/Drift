<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Widget;

/**
 * 手势消歧夹具应用（§9.1 GestureArena，2026-10-18）。
 *
 * 两个按钮：① 既可拖又可点（`onPressed` 计数 + `Draggable`）② 只可点（`onPressed` 计数）。
 * 用来分辨"拖可拖控件 / 点可拖控件 / 点普通按钮"三种结局：
 * - 拖 ① 过阈值 ⇒ Drag claim ⇒ **不激活**（修"拖可拖控件时命令在 down 先响"）；
 * - 点 ① 不过阈值 ⇒ Tap 胜 ⇒ 激活；
 * - 点 ② ⇒ down 即激活（无竞争者，防回归）。
 *
 * ★ 命中坐标由 `effectiveBox()` 动态取（不赌字模，同指针四相位夹具）。
 *
 * @internal 测试夹具
 */
final class GestureApplication extends Application
{
    /** 可拖按钮被激活次数（只在 Tap 胜时 +1；拖动过阈值不应触发） */
    public int $dragPresses = 0;

    /** 普通按钮被激活次数（down 即激活，防回归判据） */
    public int $plainPresses = 0;

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            // ① 可拖 + 可点击
            Button::make('拖我')
                ->onPressed(function (): void {
                    $this->dragPresses++;
                })
                ->draggable(Draggable::make('g-1')),
            // ② 普通按钮（无 Draggable 声明）
            Button::make('点我')
                ->onPressed(function (): void {
                    $this->plainPresses++;
                }),
        ]);
    }
}
