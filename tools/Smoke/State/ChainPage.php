<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：外层页面（被后代标脏的那一个） */
final class ChainPage extends StatefulWidget
{
    public static function make(ChainProbe $probe): self
    {
        return new self($probe);
    }

    public function __construct(private ChainProbe $probe)
    {
        // ★★★ 必须调：`Widget::$mods` 是**无默认值的类型化属性**，由基类构造器初始化。
        //   漏了这一句 ⇒ `Element::syncBox()` 里 `widget->mods()` 抛
        //   "Typed property must not be accessed before initialization"（退出码 255）。
        //   2026-10-28：这个夹具从写下起就一直崩，而 smoke 的判据是 `$code !== 3`
        //   （"不 Fatal 即可"）⇒ 崩溃也算通过，⑦d 那条**从未真正验过收敛**。
        parent::__construct();
    }

    public function createState(): State
    {
        return new ChainState($this->probe);
    }
}

/** @internal 冒烟夹具 */
final class ChainState extends State
{
    public function __construct(private ChainProbe $probe)
    {
    }

    public function build(BuildContext $context): Widget
    {
        $this->probe->outerBuilds++;
        $this->probe->outerState = $this;

        return InnerPage::make($this->probe);
    }
}
