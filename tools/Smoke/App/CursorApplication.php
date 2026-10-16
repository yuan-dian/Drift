<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Input\CursorShape;
use Drift\Widget\Center;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：根上**声明一个光标形状**（§9.1「一次命中，三方消费」第 ③ 方的应用面）。
 *
 * ★ 为什么要单开一个夹具而不是复用 `EchoApplication`：光标形状的断言要一个
 *   **确定会被命中**的根盒，而 `EchoApplication` 的根是 `Column`（松约束下高度
 *   取决于内容，命中点要算）。`Center` 在 tight 约束下铺满整个视口 ⇒ 命中点
 *   取视口中心即可，不依赖字体度量。
 */
final class CursorApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Center::make()->cursor(CursorShape::PointingHand)->child(Text::make('hit me'));
    }

    public function title(): string
    {
        return '光标应用';
    }
}
