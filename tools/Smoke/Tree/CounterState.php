<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Tree;

use Drift\Widget\BuildContext;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Widget\State;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：追踪"闭包跑没跑、重建几次、dispose 有没有被调"。
 *
 * 这些字段不被 `build()` 读 —— 所以它们不会自己触发重建，断言不会自证。
 */
final class CounterState extends State
{
    public int $bumps = 0;
    public int $builds = 0;
    public bool $disposed = false;
    public float $seenWidth = -1.0;

    public function bump(): void
    {
        $this->setState(function (): void {
            $this->bumps++;
        });
    }

    public function dispose(): void
    {
        $this->disposed = true;
    }

    public function build(BuildContext $ctx): Widget
    {
        $this->builds++;
        $this->seenWidth = $ctx->width;

        return new LeafProbe($ctx->width);
    }
}
