<?php
declare(strict_types=1);

namespace App;

use Drift\App\Application;
use Drift\Commands\CommandHandler;
use Drift\Widget\Button;
use Drift\Widget\Widget;

/**
 * 夹具：`dead-command` 的已知错误（§16.6.3 #5）。
 *
 * - `'ui.save'` 注册了、也被发出  ⇒ **不该响**
 * - `'ui.gone'` 只发出、没人注册  ⇒ 死命令（error）
 * - `'ui.never'` 只注册、没人发出  ⇒ 死注册（error）
 */
final class CommandFixture extends Application
{
    public function buildRoot(): Widget
    {
        return Button::primary('保存')->command('ui.save')
            ->pressed(Button::primary('走开')->command('ui.gone'));
    }

    public function commands(): array
    {
        $router = CommandHandler::router();
        $router->on('ui.save', CommandHandler::noop());
        $router->on('ui.never', CommandHandler::noop());

        return [];
    }
}
