<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Commands\CommandArg;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Menu\ContextMenu;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：`Shift+F10` / `Apps` 的三种落点。
 *
 * 三个**可聚焦**按钮竖排（Tab 一次一格，序即树序）：
 *   ① `行`   —— 带 `contextMenu()` 声明（正常路径）
 *   ② `空白` —— 没有声明（应无操作、不崩）
 *   ③ `认领` —— 自己 `keyboard()` 吃掉 `Shift+F10`（应优先于框架）
 *
 * ★ 行必须**可聚焦**才有键盘菜单：`Shift+F10` 的起点是焦点元素，沿父链找声明者 ——
 *   不可聚焦的行在成熟框架里同样没有键盘入口（WPF / Qt 一致），不是本仓的缺口。
 */
final class ContextMenuKeyApplication extends Application
{
    /** @var list<string> 收到过的命令名 */
    public array $seen = [];

    /** ③ 是否收到了 `Shift+F10`（控件抢在框架之前） */
    public bool $claimed = false;

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Button::make('行')
                ->contextMenu(ContextMenu::make()->items([
                    MenuItem::make('删除该行')->command('row.del'),
                ])),
            Button::make('空白'),
            Button::make('认领')->keyboard(function (KeyEvent $event, Element $self): bool {
                if ($event->chord->is(KeyChord::shift('F10'))) {
                    $this->claimed = true;

                    return true;
                }

                return false;
            }),
            // 前三项都进不了 Tab 序（禁用 / 显式跳过）⇒ 焦点该落第四项
            Button::make('跳首')->contextMenu(ContextMenu::make()->items([
                MenuItem::make('禁用项')->command('row.del')->disabled(),
                MenuItem::make('跳过项')->command('row.del')->skipFocus(),
                MenuItem::make('落点项')->command('row.del'),
            ])),
        ]);
    }

    public function commands(): iterable
    {
        $handler = new RecordingHandler(function (string $name, CommandArg $arg): void {
            $this->seen[] = $name;
        });

        return ['row.del' => $handler];
    }
}
