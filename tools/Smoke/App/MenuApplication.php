<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Commands\CommandArg;
use Drift\Input\KeyChord;
use Drift\Widget\Column;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Drag\DragTarget;
use Drift\Widget\Element;
use Drift\Widget\Menu\ContextMenu;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Menu\MenuBar;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\Widget;
use Drift\Dev\Smoke\Overlay\FlatBox;

/**
 * @internal 冒烟夹具：菜单 / 右键 / 拖放的宿主应用（§9.1 + §A.3.5）。
 *
 * 三行确定几何（`FlatBox` 固定尺寸）：菜单栏 · 可右键+可拖的行 · 放置目标行。
 */
final class MenuApplication extends Application
{
    /** @var list<string> 收到过的命令名 */
    public array $seen = [];

    /** @var list<mixed> 被 `DragTarget` 接收的负载 */
    public array $drop = [];

    /** @var list<bool> `onHover` 的调用序列（进入 / 离开各一次，不重复） */
    public array $hover = [];

    public bool $opened = false;

    public ?Element $anchor = null;

    /** 上次 `buildRoot()` 建出来的"文件"菜单（测试直接开它，绕开焦点状态） */
    private ?Menu $fileMenu = null;

    public function fileMenu(): ?Menu
    {
        return $this->fileMenu;
    }

    public function buildRoot(): Widget
    {
        $this->fileMenu = Menu::make('文件')
            ->items([
                MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('menu.refresh'),
                MenuItem::separator(),
                MenuItem::make('退出')->command('app.quit'),
            ])
            ->onOpen(function (Menu $menu, ?Element $anchor): void {
                $this->opened = true;
                $this->anchor = $anchor;
                $this->showMenu($menu->panel(), $anchor);
            });

        return Column::make()->children([
            MenuBar::make()->menus([$this->fileMenu]),
            FlatBox::make(320.0, 40.0)
                ->contextMenu(ContextMenu::make()->items([
                    MenuItem::make('删除该行')->command('row.del'),
                ]))
                ->draggable(Draggable::make(payload: 'user-7')),
            FlatBox::make(320.0, 40.0)
                ->dragTarget(DragTarget::make()
                    ->onAccept(function (mixed $payload): void {
                        $this->drop[] = $payload;
                    })
                    ->onHover(function (bool $hot): void {
                        $this->hover[] = $hot;
                    })),
        ]);
    }

    public function commands(): iterable
    {
        $handler = new RecordingHandler(function (string $name, CommandArg $arg): void {
            $this->seen[] = $name;
        });

        return [
            'menu.refresh' => $handler,
            'app.quit' => $handler,
            'row.del' => $handler,
        ];
    }
}
