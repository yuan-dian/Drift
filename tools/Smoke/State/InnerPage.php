<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：内层页面（动手标脏祖先的那一个） */
final class InnerPage extends StatefulWidget
{
    public static function make(ChainProbe $probe): self
    {
        return new self($probe);
    }

    public function __construct(private ChainProbe $probe)
    {
        // ★★★ 同 `ChainPage`：`Widget::$mods` 无默认值，必须由基类构造器初始化，
        //   否则 `syncBox()` 里读它就是 255（未初始化属性），而不是"收敛"。
        parent::__construct();
    }

    public function createState(): State
    {
        return new InnerState($this->probe);
    }
}

/** @internal 冒烟夹具 */
final class InnerState extends State
{
    public function __construct(private ChainProbe $probe)
    {
    }

    public function build(BuildContext $context): Widget
    {
        $this->probe->innerBuilds++;

        if ($this->probe->markOuterOnInnerBuild) {
            $this->probe->markOuterOnInnerBuild = false;   // 只做一次，别真成死循环
            $this->probe->outerState?->markDirty();        // ★ 后代 → 祖先
        }

        return Text::make('inner');
    }
}
