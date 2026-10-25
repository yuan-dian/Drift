<?php
declare(strict_types=1);

namespace App\Tutorial\Stages;

use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\PopupPanel;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * ③ 浮层 —— 用**声明式缝** `overlayPanel()`，**一行接线都不用**。
 *
 * ## 浮层有两条路，先学不接线的那条
 *
 * | 写法 | 谁开 | 需要组合根接线吗 |
 * |---|---|---|
 * | `Widget::overlayPanel($factory)` | 框架在**激活**那一刻开（点击 / Enter） | **否** |
 * | `Application::showModal()` / `showMenu()` / `showSnackbar()` | 应用自己调 | 是（控件层够不到 `Application`） |
 *
 * 本段走第一条：`Button` 一旦声明了 `overlayPanel`，激活它就等于"开 / 关面板"，
 * **不走 `onPressed`**（"浮一个面板"比"按一下"更具体 —— 框架的优先级裁决）。
 *
 * ## 工厂为什么收一个 `dismiss` 闭包
 *
 * 面板里的按钮点了要**关掉面板**，而面板内容拿不到 `Application`（§4.3 禁
 * `Widget → App`）⇒ 关不掉自己。所以 `dismiss` 只能由 `Application` 在
 * **开面板的那一刻**注入进来 —— 于是工厂的形参就是它。
 *
 * ★ **工厂只收一个形参是刻意的**：tpc 生成的 C++ 侧**闭包 arity 没有系统验证**
 *   过。要往工厂里多塞"锚点宽度"这类信息，宁可给 `OverlayEntry` 加字段，
 *   也不要加形参。
 */
final class OverlayStage extends StatefulWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    #[\Override]
    public function createState(): State
    {
        return new OverlayState();
    }
}

final class OverlayState extends State
{
    private string $picked = '（还没选）';

    #[\Override]
    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(8.0)->children([
            Text::make('③ 浮层（声明式缝，零接线）')->heading(),
            Text::make('Button 声明了 overlayPanel ⇒ 激活它 = 开/关面板，不走 onPressed。'
                . '面板里的按钮靠框架注入的 dismiss 关掉自己。')->caption(),

            Row::make()->spacing(8.0)->children([
                Button::make('选择城市')
                    ->variant(ButtonVariant::Primary)
                    ->overlayPanel($this->panel()),
                Spacer::make(),
                Text::make('已选：' . $this->picked)->body(),
            ]),
        ]);
    }

    /**
     * 面板工厂：形参 = "收掉这一层"的闭包，返回 = 面板内容。
     *
     * ★ 这里用 `PopupPanel` 包一层：它负责圆角 / 投影 / 内边距 / 限高，
     *   落位与锚点归 `Application`（`§5.1` 裁决 1：Application 管生命周期与落位，
     *   控件只管形体）。
     *
     * @return \Closure(\Closure): Widget
     */
    private function panel(): \Closure
    {
        return function (\Closure $dismiss): Widget {
            $items = [];
            foreach (['北京', '上海', '广州'] as $city) {
                $items[] = Button::make($city)->onPressed(function () use ($dismiss, $city): void {
                    $this->setState(function () use ($city): void {
                        $this->picked = $city;
                    });
                    $dismiss();
                });
            }

            return PopupPanel::make(Column::make()->spacing(4.0)->children($items));
        };
    }
}
