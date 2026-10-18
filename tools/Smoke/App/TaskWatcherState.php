<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\Dev\Smoke\LeafProbe;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：`TaskWatcher` 的 State —— 读数见那个类的注释。 */
final class TaskWatcherState extends State
{
    public int $builds = 0;

    /** 最近一次 `build()` 读到的 `status()` */
    public int $seen = -1;

    public function initState(): void
    {
        /** @var TaskWatcher $widget */
        $widget = $this->widget();

        // ★ 订阅写在 initState（不是 build）—— §A.4 的守卫要求：build 里订阅会
        //   每重建一次多订一份。
        $this->watch($widget->store(), function (): void {
            $this->setState(function (): void {
            });
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        /** @var TaskWatcher $widget */
        $widget = $this->widget();

        $this->builds++;
        $this->seen = $widget->store()->status();

        return new LeafProbe((float) $this->seen);
    }
}
