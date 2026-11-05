<?php
declare(strict_types=1);

namespace Drift\Dev\Preview;

use Drift\Input\KeyChord;
use Drift\Widget\AppBar;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Menu\ContextMenu;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Menu\MenuBar;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\Padding;
use Drift\Widget\PopupPanel;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 菜单 / 浮层面板的**形体**预览（§20 纪律 4：每项能力必须在示例中出现）。
 *
 * ★ 这里画的是**面板本身**（`PopupPanel` + `MenuItem` 的底 / 边框 / 项 / 分隔线 /
 *   快捷键提示）——"打开后浮在哪儿"是运行期几何，归 `Application::showMenu()` +
 *   `Frame` 的浮层分支，由 smoke 断言覆盖（本页只演示形体）。
 */
final class MenuPage extends StatelessWidget
{
    public function build(BuildContext $context): Widget
    {
        return Column::make()->children([
            AppBar::make()->title('用户管理')->children([
                MenuBar::make()->menus([
                    Menu::make('文件')->items([
                        MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('users.refresh'),
                        MenuItem::make('新建')->chord(KeyChord::ctrl('N'))->command('nav.signup'),
                    ]),
                    Menu::make('编辑')->items([
                        MenuItem::make('删除')->command('user.del'),
                    ]),
                ]),
            ]),

            // ★ 浮层面板的形体（菜单打开后浮在主内容之上的那个块）
            Padding::make(
                PopupPanel::make(Column::make()->children([
                    MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('users.refresh'),
                    MenuItem::separator(),
                    MenuItem::make('退出')->command('app.quit'),
                ]))->minWidth(160.0),
            )->all(12.0),

            Padding::make(
                Text::make('↑ 浮层面板形体（菜单打开后浮在主内容之上）')->caption(),
            )->all(12.0),

            Padding::make(
                Text::make('应用内拖放：把一行拖到「回收站」上（Draggable / DragTarget，零平台端口）')
                    ->caption(),
            )->all(12.0),

            Padding::make(
                Text::make('右键任意一行 ⇒ ContextMenu（右键最小集，不依赖 GestureArena）')
                    ->caption(),
            )->all(12.0),
        ])->contextMenu(menuPageDemoContextMenu());
    }
}

/** @internal 上下文菜单的声明位（挂在整页上 —— 文案说的"任意一行"必须真的成立） */
function menuPageDemoContextMenu(): ContextMenu
{
    return ContextMenu::make()->items([
        MenuItem::make('删除该行')->command('user.del'),
        MenuItem::make('复制邮箱')->onPressed(static function (): void {
        }),
    ]);
}
