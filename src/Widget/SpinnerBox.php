<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderSpinner;
use Drift\Rendering\ColorToken;

/**
 * `Spinner` 的盒（§13.2 三段形态：`Spinner → SpinnerState → SpinnerBox → RenderSpinner`）。
 *
 * ★ **为什么存在**：相位在 `State` 里，而 `StatefulWidget` 自己不产盒
 *   （`Element::syncBox()` 只认 `RenderBoxWidget`）⇒ 有态的控件与有盒的控件
 *   必须是两个 Widget。
 *
 * ★ **它只做一件事**：把主题 token 解析成值（§15.7 纪律 3 的合并点）——
 *   于是 `Drift\Layout` 不必也不许碰 Theme。
 *
 * @internal
 */
final class SpinnerBox extends RenderBoxWidget
{
    private function __construct(
        private readonly float $diameter,
        private readonly int $step,
    ) {
        parent::__construct();
    }

    /** @internal 由 `SpinnerState::build()` 调 */
    public static function make(float $diameter, int $step): self
    {
        return new self($diameter, $step);
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderSpinner(
            $this->diameter,
            $this->step,
            $context->color(ColorToken::Accent),
        );
    }
}
