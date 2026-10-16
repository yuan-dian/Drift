<?php
declare(strict_types=1);

namespace App\Users\Widgets;

use Drift\Commands\CommandArg;
use Drift\Input\CursorShape;
use Drift\Widget\Button;
use Drift\Widget\BuildContext;
use Drift\Widget\Drag\Draggable;
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
        // 应用内拖放：把这一行拖到「回收站」上（§12.4，M3，零平台端口）
        ->draggable(Draggable::make($u->id));
    }
}
