<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Input\CursorShape;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 「顶条当标题栏」的夹具（P1-Stage 1b 无边框窗，2026-10-26）。
 *
 * 四个区域，各验一件事：
 * | 区域 | 声明 | 判据 |
 * |---|---|---|
 * | 标题 | 顶条 `windowDrag()` + 里面一个 `Text` | 点文字 ⇒ **拖窗**（标题栏语义）|
 * | 空白 | 顶条 `windowDrag()` 自己的内边距 | 点空白 ⇒ **拖窗** |
 * | 按钮 | 顶条里的 `Button` | 点它 ⇒ **不拖窗**，命令照发 |
 * | 自绘 | 顶条里的 `Column->pointer()` | 点它 ⇒ **不拖窗**（`pointer()` 声明者让位）|
 *
 * ★ **为什么要四个而不是一个**：`windowDrag` 的失败形态是**静默吞掉点击**
 *   （按钮点了没反应），不是崩溃。只有"该拖的不拖"和"不该拖的拖了"两边都钉住，
 *   判据才能证伪 —— 单验"点空白能拖"无法发现按钮被吞。
 * ★ 坐标一律 `effectiveBox()` + `absoluteOrigin()` 现取（不赌字模高度）。
 *
 * @internal 测试夹具
 */
final class TitleBarLabApplication extends Application
{
    /** 窗口拖动闭包被调次数（每个 down 最多一次） */
    public int $dragStarts = 0;

    /** 顶条里「动作」按钮被激活的次数 */
    public int $actionPresses = 0;

    /** 顶条里「自绘」区（`pointer()` 声明者）收到的相位序列 */
    public array $paintedLog = [];

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Column::make()
                ->windowDrag(function (): void {
                    $this->dragStarts++;
                })
                ->children([
                    Text::make('用户管理'),

                    Button::make('动作')->onPressed(function (): void {
                        $this->actionPresses++;
                    }),

                    // `pointer()` 声明者 ⇒ 让位（自绘控件自己处理按下）
                    Column::make()
                        ->pointer(function (PointerEvent $event, Element $self): ?CursorShape {
                            $this->paintedLog[] = $event->isDown() ? 'down' : 'other';

                            return null;
                        }, ownsPress: true)
                        ->children([
                            Text::make('自绘'),
                        ]),
                ]),

            // 顶条**外面**的内容（反例面：点它不该拖窗 —— 声明只在顶条子树内生效）
            Text::make('内容'),
        ]);
    }

    /** 当前焦点 Element（`$focus` 是 protected ⇒ 夹具开一个只读口子） */
    public function focusedElement(): ?Element
    {
        return $this->focus?->focused();
    }
}
