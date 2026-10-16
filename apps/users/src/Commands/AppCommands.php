<?php
declare(strict_types=1);

namespace App\Users\Commands;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Theme\SystemTheme;
use App\Users\Store\AppStore;

/**
 * 应用命令（§A.2.10 目录树）：退出 / 主题。
 *
 * ★ **真退出归平台**（`Platform::pump()` 的循环在组合根，§5.2 裁决 1）⇒ 应用层
 *   只置状态（状态栏说"再见"），窗口什么时候关是组合根的事。
 */
final class AppCommands implements CommandHandler
{
    public function __construct(private AppStore $app)
    {
    }

    public function handle(string $name, CommandArg $arg): bool
    {
        return match ($name) {
            'app.quit' => $this->quit(),
            'app.theme' => $this->theme($arg),
            default => false,
        };
    }

    private function quit(): bool
    {
        $this->app->say('再见');

        return true;
    }

    /** `SettingsPage` 的两个按钮：`CommandArg::key('light'|'dark')` */
    private function theme(CommandArg $arg): bool
    {
        $this->app->setSystemTheme($arg->s === 'dark' ? SystemTheme::Dark : SystemTheme::Light);

        return true;
    }
}
