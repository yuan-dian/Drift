<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\BuildContext;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：拿 `Cell` 当高频 UI 态的控件（§11.3.1 的 HoverCard 形态）。 */
final class HoverWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new HoverState();
    }
}

/**
 * @internal 冒烟夹具。
 *
 * `builds` 计数是**外部可观测量**：`build()` 自己不读 `Cell` 的值，所以
 * "设了同值不重建"这条断言不会自证 —— 若 `setIfChanged` 漏了 `===` 比较，
 * `builds` 会每次 +1，断言立刻红。
 */
final class HoverState extends State
{
    public int $builds = 0;
    public int $inits = 0;

    private \Drift\State\Cell $hovered;

    public function initState(): void
    {
        $this->inits++;
        // §11.3.1：Cell 只能由 State::cell() 建 —— 属性初始化器拿不到 $this
        $this->hovered = $this->cell(-1);
    }

    public function hover(): \Drift\State\Cell
    {
        return $this->hovered;
    }

    public function onRowHover(int $index): void
    {
        $this->hovered->setIfChanged($index); // 内部：=== 不等 ⇒ 标脏
    }

    public function build(BuildContext $ctx): Widget
    {
        $this->builds++;

        return new LeafProbe($ctx->width);
    }
}
