<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\BuildContext;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：高频 UI 态（§11.3.1 的 HoverCard 形态）。 */
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
 * `builds` 计数是**外部可观测量**：`build()` 自己不读那个值，所以"设了同值
 * 不重建"这条断言不会自证 —— 若调用点漏了相等门控，`builds` 会每次 +1。
 *
 * ★ 这个夹具同时是"设同值不重建"的**标准写法**样本（原先是 `Cell::setIfChanged`，
 *   2026-11-02 随 D7 删掉 `Cell` 之后改成显式 `if`）。
 */
final class HoverState extends State
{
    public int $builds = 0;
    public int $inits = 0;

    private int $hovered = -1;

    public function initState(): void
    {
        $this->inits++;
    }

    public function hovered(): int
    {
        return $this->hovered;
    }

    public function onRowHover(int $index): void
    {
        if ($this->hovered === $index) {
            return;   // 同值 ⇒ 不标脏，一次 `build()` 都不该发生
        }

        $this->setState(function () use ($index): void {
            $this->hovered = $index;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        $this->builds++;

        return new LeafProbe($ctx->width);
    }
}
