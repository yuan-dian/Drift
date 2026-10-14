<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke;

use Drift\Widget\Widget;

/**
 * 冒烟用探针控件：把 `mutate()` 暴露成一个可调用的链式方法，
 * 以便验证 Seal-on-Mount 的响亮失败（§15.2 / A11）。
 *
 * 放在工具链下 —— 它不是框架的一部分，只是量具。
 */
final class SealProbe extends Widget
{
    public function touch(): static
    {
        $this->mutate();
        return $this;
    }
}
