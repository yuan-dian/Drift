<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 虚线图案（19 op 的 `DASH`，Extended 层，P3 实现后端）。
 *
 * 挂在 `Paint` 上（描边属性）：`on` / `off` 是"画多少 / 空多少"的重复周期，
 * `phase` 是周期内的起始偏移（让相邻段可以错开半个周期，接缝不齐时用）。
 *
 * ★ 逻辑单位，与 `strokeWidth` 同口径（不乘 dpr）—— 两个后端的描边宽度
 *   口径已经统一过（`SkiaCanvas::drawRect` 注释），虚线不能再开一个口径。
 */
final readonly class DashPattern
{
    public function __construct(
        public float $on,
        public float $off,
        public float $phase = 0.0,
    ) {
        if ($on <= 0.0) {
            Errors::report('DashPattern：实线段长度必须为正，收到 ' . (string) $on);
        }
        if ($off <= 0.0) {
            Errors::report('DashPattern：空段长度必须为正，收到 ' . (string) $off);
        }
    }

    /** 周期长度 */
    public function period(): float
    {
        return $this->on + $this->off;
    }
}
