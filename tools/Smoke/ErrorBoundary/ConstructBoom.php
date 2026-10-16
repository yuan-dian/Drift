<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\ErrorBoundary;

use Drift\Layout\RenderBox;
use Drift\Widget\BuildContext;
use Drift\Widget\RenderBoxWidget;

/**
 * @internal 冒烟夹具：`createRenderObject` 抛（构造期异常）。
 *
 * 挂载路径 `mount → syncBox → createRenderObject` 在 `updateChildren` 里调
 * ⇒ 异常从 `ErrorBoundaryElement::rebuild()` 的 try 冒出（O-12 的构造期）。
 */
final class ConstructBoom extends RenderBoxWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        throw new \RuntimeException('construct boom');
    }
}
