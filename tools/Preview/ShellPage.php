<?php
declare(strict_types=1);

namespace Drift\Dev\Preview;

use Drift\Input\CursorShape;
use Drift\Layout\Overflow;
use Drift\Widget\AppBar;
use Drift\Widget\BuildContext;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Dock;
use Drift\Widget\NavButton;
use Drift\Widget\Padding;
use Drift\Widget\Spinner;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 外壳示例页 —— §12.1 / §12.2 的多页组合（Dock 五槽 + AppBar + NavButton）。
 *
 * ```bash
 * php tools/drift-preview.php --page=tools/Preview/ShellPage.php --width=420 --height=300
 * ```
 *
 * ★ 它演示的是 §A.2.6 `UsersRoot` 的无 store 版本：`PageHost` 要 Subject，
 *   预览页用静态内容占 center 槽；外壳三件 + 焦点/激活的形状一致。
 */
final class ShellPage extends StatelessPage
{
    public function build(BuildContext $context): Widget
    {
        return Dock::make()
            ->top(AppBar::make()->title('用户管理')->children([
                NavButton::make('列表')->command('nav.users'),
                NavButton::make('注册')->command('nav.signup'),
                NavButton::make('设置')->command('nav.settings'),
            ]))
            ->left(
                Padding::make(
                    Column::make()->spacing(8.0)->children([
                        Text::make('侧轨')->caption(),
                        Text::make('（left 槽）')->caption(),
                    ]),
                )->all(8.0),
            )
            ->center(
                Padding::make(
                    Column::make()->spacing(12.0)->children([
                        Text::make('内容槽（center）')->title(),
                        Text::make('tight 填满剩余矩形；页面自己决定滚动（M5）')->body(),
                        Center::make()->child(
                            Column::make()->spacing(8.0)->children([
                                Spinner::make(),
                                Text::make('加载中…')->caption(),
                            ]),
                        )->cursor(CursorShape::PointingHand),
                    ]),
                )->all(16.0),
            )
            ->bottom(Padding::make(Text::make('状态栏槽（bottom）')->caption())->symmetric(12.0, 6.0))
            ->overflow(Overflow::Clip);
    }
}
