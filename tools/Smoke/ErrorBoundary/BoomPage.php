<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\ErrorBoundary;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：**第二次 build 抛**的页面。
 *
 * 第一次必须成功挂载（`mount` 里就跑首次 build）—— 要测的是"帧末重建时抛"
 * 这条路径（O-12 的 build 期），不是"一挂载就炸"（那是构造期）。
 */
final class BoomPage extends StatefulWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new BoomState();
    }
}

/** @internal 冒烟夹具 */
final class BoomState extends State
{
    private int $ticks = 0;

    public function build(BuildContext $context): Widget
    {
        $this->ticks++;

        if ($this->ticks >= 2) {
            throw new \RuntimeException('build boom');
        }

        return Text::make('好的');
    }

    /** 标脏 ⇒ 下一帧 `settle()` 会重建 ⇒ 第二次 build 抛 */
    public function boom(): void
    {
        $this->markDirty();
    }
}
