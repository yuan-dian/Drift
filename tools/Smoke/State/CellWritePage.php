<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\State\Cell;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 冒烟夹具：build 期写自己读过的格子。
 *
 * 首帧没有订阅，写无害；syncReactiveDeps() 之后第二帧再写 ⇒ 订阅回调里的
 * self-markDirty 走 E-FLUSH-CYCLE。用来钉住 "Cell 的守卫在 State，不在 Tracker"。
 *
 * @internal
 */
final class CellWritePage extends StatefulWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public function createState(): State
    {
        return new CellWriteState();
    }
}

/** @internal 冒烟夹具 */
final class CellWriteState extends State
{
    private Cell $cell;

    public function initState(): void
    {
        $this->cell = Cell::of(0)->named('buildWrite');
    }

    public function build(BuildContext $context): Widget
    {
        $this->cell->set($this->cell->peek() + 1);

        return Text::make('n=' . $this->cell->get());
    }
}
