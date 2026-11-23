<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Checkbox;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Radio;
use Drift\Widget\Toggle;
use Drift\Widget\Widget;

/**
 * 开关类控件夹具应用：五个控件竖排，回调把 `(控件, 新值)` 记进 `$events`。
 *
 * ★ 存在理由：`Application::activateFocusedWidget()` 是键盘 Space 与指针按下的
 *   **唯一**激活入口，而它按 `instanceof` 分支（`Menu` → `ToggleBase` → `Button`）。
 *   只测 `ToggleBase::activate()` 测不到这条链 —— 分支写错（漏 `use`、顺序放错）
 *   会让"点了没反应"，且编译期不报错（本项目咬过三次的形态）。
 *
 * ★ 顺序即 Tab 序：同意 → 小 → 中 → 通知 → 禁用项。
 *   「中」初始就是选中态（组值 = m）、「通知」初始开、「禁用项」不可激活 ——
 *   三个反例各占一格。
 *
 * @internal 测试夹具
 */
final class ToggleApplication extends Application
{
    /** @var list<array{0: string, 1: string}> 每次回调记 `[控件, 新值]` */
    public array $events = [];

    /** @internal 夹具用：拿根 Element（盒级断言不走 Application） */
    public function rootElement(): ?Element
    {
        return $this->root();
    }

    public function buildRoot(): Widget
    {
        return Column::make()->paddingAll(16.0)->spacing(8.0)->children([
            Checkbox::make('同意')
                ->checked(false)
                ->onChanged(function (bool $on): void {
                    $this->events[] = ['checkbox', $on ? 'on' : 'off'];
                }),
            Radio::make('小')
                ->value('s')
                ->group('m')
                ->onChanged(function (string $v): void {
                    $this->events[] = ['radio', $v];
                }),
            Radio::make('中')
                ->value('m')
                ->group('m')
                ->onChanged(function (string $v): void {
                    $this->events[] = ['radio', $v];
                }),
            Toggle::make('通知')
                ->checked(true)
                ->onChanged(function (bool $on): void {
                    $this->events[] = ['switch', $on ? 'on' : 'off'];
                }),
            Checkbox::make('禁用项')
                ->enabled(false)
                ->onChanged(function (bool $on): void {
                    $this->events[] = ['disabled', $on ? 'on' : 'off'];
                }),
        ]);
    }
}
