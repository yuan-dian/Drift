<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Slider;
use Drift\Widget\Widget;

/**
 * 滑杆夹具（W8-2）。
 *
 * 三根竖排：
 * | 栏 | 配置 | 验什么 |
 * |---|---|---|
 * | a | `range(0,100) value=50`（连续）| 位置 ↔ 值互逆、连续不量化 |
 * | b | `range(0,10) value=3 step=1` | 量化真的生效（不会得到 3.7）|
 * | c | `value=20 enabled(false)` | 禁用：不回调、不吃方向键 |
 *
 * ★ 坐标一律 `effectiveBox()` + `absoluteOrigin()` 现取（不赌字模）。
 *
 * @internal 测试夹具
 */
final class SliderApplication extends Application
{
    /** @var list<array{0:string,1:float}> 依次收到的 (栏名, 新值) */
    public array $events = [];

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Slider::make()
                ->range(0.0, 100.0)
                ->value(50.0)
                ->onChanged(function (float $v): void {
                    $this->events[] = ['a', $v];
                }),
            Slider::make()
                ->range(0.0, 10.0)
                ->step(1.0)
                ->value(3.0)
                ->onChanged(function (float $v): void {
                    $this->events[] = ['b', $v];
                }),
            Slider::make()
                ->range(0.0, 100.0)
                ->value(20.0)
                ->enabled(false)
                ->onChanged(function (float $v): void {
                    $this->events[] = ['c', $v];
                }),
        ]);
    }

    /** 当前焦点 Element（`$focus` 是 protected） */
    public function focusedElement(): ?Element
    {
        return $this->focus()?->focused();
    }
}
