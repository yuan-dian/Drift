<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\SegmentedControl;
use Drift\Widget\Widget;

/**
 * 分段控件夹具应用：三组竖排，回调把 `[组, 新序号]` 记进 `$events`。
 *
 * ★ 存在理由（与 `ToggleApplication` 同）：`Application::activateFocusedWidget()`
 *   是键盘 Space 与指针按下的**唯一**激活入口，且它按 `instanceof` 分支。
 *   只测 `Segment::activate()` 测不到这条链 —— 分支漏写/顺序错会让"点了没
 *   反应"，且编译期不报错。
 *
 * ★ 三组各管一个反例：A 组**尾段**预选中（点已选段不回调）、B 组**中段**预选中、
 *   C 组整组禁用（段可聚焦、不可激活）。顺序即 Tab 序：日 → 周 → 月 → 小 →
 *   中 → 大 → 开 → 关。
 *
 * @internal 测试夹具
 */
final class SegmentedApplication extends Application
{
    /** @var list<array{0: string, 1: int}> 每次回调记 `[组, 新序号]` */
    public array $events = [];

    /** @internal 夹具用：拿根 Element（盒级断言不走 Application） */
    public function rootElement(): ?Element
    {
        return $this->root();
    }

    public function buildRoot(): Widget
    {
        return Column::make()->paddingAll(16.0)->spacing(12.0)->children([
            SegmentedControl::make(['日', '周', '月'])
                ->selected(2)
                ->onChanged(function (int $i): void {
                    $this->events[] = ['a', $i];
                }),
            SegmentedControl::make(['小', '中', '大'])
                ->selected(1)
                ->onChanged(function (int $i): void {
                    $this->events[] = ['b', $i];
                }),
            SegmentedControl::make(['开', '关'])
                ->selected(0)
                ->enabled(false)
                ->onChanged(function (int $i): void {
                    $this->events[] = ['c', $i];
                }),
        ]);
    }
}
