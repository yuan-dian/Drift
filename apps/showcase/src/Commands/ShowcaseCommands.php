<?php
declare(strict_types=1);

namespace App\Showcase\Commands;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Widget\Snackbar;

/**
 * 示例台自己的命令（§11.4.3）：顶条右侧"刷新"图标按钮的落点。
 *
 * ★ **为什么是具名类而不是闭包**：`CommandHandler` 2026-10-14 裁决要求具名实例
 *   （可枚举 / 可单测 / 静态可达）。这是示例台**第一个**真命令 —— 此前它刻意
 *   不注册命令（页切换是纯 UI 态，§12.3），本次为了给 `AppBar::actions()` 里的
 *   图标按钮一条真实的命令链，才补上这一个。
 *
 * ★ `$notify` 由组合根递进来（§4.3 禁 `Widget → App`，同 `ShowcaseRoot` 的构造）：
 *   handler 住在应用侧，它当然可以持有 `Application` 的能力闭包。
 */
final class ShowcaseCommands implements CommandHandler
{
    /** @param \Closure(Snackbar): void $notify 「弹一条底部通知」 */
    public function __construct(private \Closure $notify)
    {
    }

    public function handle(string $name, CommandArg $arg): bool
    {
        if ($name !== 'showcase.refresh') {
            return false; // 不是我管的 ⇒ 继续冒泡
        }

        ($this->notify)(Snackbar::make('已刷新'));

        return true;
    }
}
