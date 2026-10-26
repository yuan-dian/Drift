<?php
declare(strict_types=1);

namespace App\Users;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Platform\WindowHost;

/**
 * 窗口控制命令（P1-Stage 1b 无边框窗的三个自绘按钮）。
 *
 * ```text
 * window.minimize  最小化
 * window.maximize  最大化 ⇄ 还原
 * window.close     关闭（= 唯一的正常退出路径，§5.1）
 * ```
 *
 * ★ **为什么住在组合根目录**：它要 `WindowHost` 与句柄，而 §5.1 纪律 1 规定
 *   只有组合根能提平台实现。放进 `src/` 或共享代码会把 HWND 概念泄漏到应用层。
 * ★ **为什么是具名类而不是闭包**：`CommandHandler` 的硬约束（§11.4.3）——
 *   闭包不可枚举、不可单测、静态不可达。
 * ★ 命令名是**标准名**（不是 `users.window.minimize`）：`AppBar::windowButtons()`
 *   里写死了这三个名字，换应用不用改顶条。
 *
 * @internal 组合根件
 */
final class WindowCommands implements CommandHandler
{
    public function __construct(
        private readonly WindowHost $window,
        private readonly int $handle,
    ) {
    }

    public function handle(string $name, CommandArg $arg): bool
    {
        return match ($name) {
            'window.minimize' => $this->minimize(),
            'window.maximize' => $this->maximize(),
            'window.close' => $this->close(),
            default => false,
        };
    }

    private function minimize(): bool
    {
        $this->window->minimize($this->handle);

        return true;
    }

    private function maximize(): bool
    {
        $this->window->maximizeToggle($this->handle);

        return true;
    }

    private function close(): bool
    {
        $this->window->close($this->handle);

        return true;
    }
}
