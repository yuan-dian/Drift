<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：`build()` 里标脏**自己** ⇒ 每一遍都重建 ⇒ 永不收敛。
 *
 * 多轮收敛循环的防御上限（16 轮）就是为它上的：跑满还不停 ⇒ **当帧** Fatal
 * （E-FLUSH-CYCLE），而不是"每帧默默重建一次、CPU 上去没人知道"。
 */
final class LoopPage extends StatefulWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new LoopState();
    }
}

/** @internal 冒烟夹具 */
final class LoopState extends State
{
    public int $builds = 0;

    public function build(BuildContext $context): Widget
    {
        $this->builds++;
        $this->markDirty();     // ★ 每遍都标脏自己 ⇒ 收敛不了

        return Text::make('loop');
    }
}
