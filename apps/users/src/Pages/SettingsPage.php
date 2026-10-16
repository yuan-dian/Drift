<?php
declare(strict_types=1);

namespace App\Users\Pages;

use Drift\Commands\CommandArg;
use Drift\Geometry\EdgeInsets;
use Drift\Input\CursorShape;
use Drift\Widget\AppBar;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

use App\Users\Store\AppStore;

/**
 * 设置页（§A.2.1 目录树：主题 / 光标 / 拖放 / 焦点序演示）。
 *
 * ★ 它是 **§A.3 能力集的样板落点**：每项能力在这里以一个最小形态出现
 *   （§20 纪律 4：能力必须在示例中露面）。
 */
final class SettingsPage extends StatelessWidget
{
    public function __construct(private readonly AppStore $store)
    {
        parent::__construct();
    }

    public static function make(AppStore $store): self
    {
        return new self($store);
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(12)->padding(EdgeInsets::all(24.0))->children([
            Text::make('设置')->title(),

            // ── ① 主题（§A.3.3）：命令带 arg，store 记，ThemeScope 在 Root 消费 ──
            Text::make('主题（切换由 ThemeScope 在 Root 一层兑现）')->caption(),
            Row::make()->spacing(8)->children([
                Button::make('浅色')->command('app.theme')->arg(CommandArg::key('light')),
                Button::make('深色')->command('app.theme')->arg(CommandArg::key('dark')),
            ]),

            // ── ② 光标形状（§A.3.2）：控件声明，命中链取最深声明者 ──
            Text::make('光标形状（悬停看形状，命中一次三方消费）')->caption(),
            Row::make()->spacing(12)->children([
                Text::make('可选中文本')->cursor(CursorShape::IBeam),
                Text::make('可点')->cursor(CursorShape::PointingHand),
                Text::make('忙碌')->cursor(CursorShape::Wait),
            ]),

            // ── ③ 焦点序（§A.3.4）：工具按钮组整组不进 Tab 序（显式） ──
            Text::make('焦点序（下面这组 skipFocus：Tab 时整组跳过）')->caption(),
            AppBar::make()->skipFocus()->children([
                Button::make('工具一'),
                Button::make('工具二'),
            ]),

            // ── ④ 应用内拖放（§A.3.5 / §12.4）：声明 + 状态机已落地，
            //    真机指针序列等 M4（PointerEvent::move/up） ──
            Text::make('拖放：把列表行拖到「回收站」上（零平台端口）')->caption(),

            Spacer::make(),

            Text::make('加速键：Ctrl+R 刷新 · Ctrl+S 提交 · F5 刷新')->caption(),
        ]);
    }
}
