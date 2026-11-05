<?php
declare(strict_types=1);

namespace App\Users\Widgets;

use Drift\Commands\CommandArg;
use Drift\Input\CursorShape;
use Drift\Widget\Button;
use Drift\Widget\BuildContext;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Menu\ContextMenu;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

use App\Users\Store\User;

/**
 * 列表行（§A.2.9）：**纯展示，不吃 store**（§11.3.2 纪律 2）。
 *
 * 订阅点在页面层（`UsersPage` 的 `SubjectBuilder`），行内只吃值 ⇒ 列表重排时
 * 不会每行各触发一次广播。
 */
final class UserRow extends StatelessWidget
{
    public function __construct(private readonly User $user)
    {
        parent::__construct();
    }

    public static function make(User $user): self
    {
        return new self($user);
    }

    public function build(BuildContext $ctx): Widget
    {
        $u = $this->user;

        return Row::make()->spacing(12)->cursor(CursorShape::PointingHand)->children([
            Text::make($u->name)->body(),
            Text::make($u->email)->caption(),
            Spacer::make(),
            Button::danger('删除')->command('user.del')->arg(CommandArg::id($u->id)),
        ])
        // 应用内右键（§60）：弹在光标处。命令复用已有的两个 —— 行级"删除"与列表级"刷新"，
        // 不为了菜单发明新命令。键盘入口（`Shift+F10` / `Apps`）落在同一个声明上。
        ->contextMenu(ContextMenu::make()->items([
            MenuItem::make('删除该用户')->command('user.del')->arg(CommandArg::id($u->id)),
            MenuItem::separator(),
            MenuItem::make('刷新列表')->command('users.refresh'),
        ]))
        // 应用内拖放：把这一行拖到「回收站」上（§12.4，M3，零平台端口）
        ->draggable(Draggable::make($u->id));
    }
}
