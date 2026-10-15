<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\BuildContext;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：**在 `build()` 里订阅** —— A4 点名要守住的失败模式。 */
final class BuildWatchWidget extends StatefulWidget
{
    private FakeStore $store;

    public function __construct(FakeStore $store)
    {
        parent::__construct();
        $this->store = $store;
    }

    public function store(): FakeStore
    {
        return $this->store;
    }

    public function createState(): State
    {
        return new BuildWatchState();
    }
}

/** @internal 冒烟夹具：订阅写在 build 体内 ⇒ 每重建一次多订一份。 */
final class BuildWatchState extends State
{
    public int $builds = 0;

    public function build(BuildContext $ctx): Widget
    {
        $this->builds++;

        /** @var BuildWatchWidget $widget */
        $widget = $this->widget();
        // ★ 故意写在 build 里：订阅数随重建次数增长，A4 的运行期守卫必须响
        $this->watch($widget->store(), function (): void {
        });

        return new LeafProbe($ctx->width);
    }
}
