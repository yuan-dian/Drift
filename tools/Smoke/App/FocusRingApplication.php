<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Geometry\EdgeInsets;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：一个**位置确定**的可聚焦控件（焦点环断言要知道控件在哪）。
 *
 * ★ `Column` + `padding(20)` ⇒ `Button` 的盒原点是 `(20,20)`；焦点环是**外扩 1px**
 *   的 2px 描边 ⇒ `(19,19)` 必定落在描边上。不依赖字体度量（Button 的尺寸由内容
 *   决定，但原点固定）。
 */
final class FocusRingApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->padding(EdgeInsets::all(20.0))->children([
            Button::make('focus me'),
        ]);
    }

    public function title(): string
    {
        return '焦点环应用';
    }
}
