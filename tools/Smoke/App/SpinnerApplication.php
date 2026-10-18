<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Spinner;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：根就是一个 `Spinner`（**没有任何异步任务**）。
 *
 * 存在的理由（§17.4.10）：它把"帧时钟该不该上膛"逼到最窄 —— 没有任务、没有 IO、
 * 没有分片，`wantsFrame()` 唯一可能为真的来源就是**动画**。用 `EchoApplication`
 * 验不了这条：它的 `wantsFrame()` 本来就该是 false。
 */
final class SpinnerApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Spinner::make()->diameter(40.0);
    }

    public function title(): string
    {
        return '转圈';
    }
}
