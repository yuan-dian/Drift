<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 渐变（19 op 的 `LINEAR` / `RADIAL`，Extended 层，P3 实现后端）。
 *
 * **为什么渐变进 `Paint` 而不是新的 draw 方法**：design-v6 的 op 表里
 * `SOLID` / `LINEAR` / `RADIAL` / `NONE` 是**同一层的 paint source** ——
 * "用什么填充"与"画什么形状"是两个正交维度。于是渐变是画笔属性：
 * `drawRect($rect, $paint)` / `drawEllipse(...)` 原样成立，不需要
 * `drawGradientRect` 这种组合爆炸的兄弟方法。
 *
 * ★ **色标必须等长且升序到 [0,1]**：不等长的色标表在光栅化时逐段插值会
 *   静默错位（两个后端的表现还不一样）⇒ 构造期就响亮失败，不留到像素期。
 * ★ 空 stops = 均匀分布（首色到末色线性铺开）—— 最常见用例不该被仪式税拖累。
 */
final readonly class Gradient
{
    /** @var list<Color> */
    public array $colors;

    /** @var list<float> */
    public array $stops;

    /**
     * @param list<Color> $colors
     * @param list<float> $stops
     */
    private function __construct(
        public bool $radial,
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
        array $colors,
        array $stops,
    ) {
        if ($colors === []) {
            Errors::report('Gradient：颜色表不能为空，至少给一个起止色');
        }
        $count = count($colors);
        if ($count < 2) {
            Errors::report('Gradient：颜色表至少要有两个色标，收到 ' . $count . ' 个');
        }
        if ($stops !== [] && count($stops) !== $count) {
            Errors::report(sprintf(
                'Gradient：色标位置与颜色数量不一致，%d 个颜色对 %d 个位置',
                $count,
                count($stops),
            ));
        }
        foreach ($stops as $i => $stop) {
            if ($stop < 0.0 || $stop > 1.0) {
                Errors::report(sprintf(
                    'Gradient：色标位置必须在 0..1 之间，第 %d 个是 %s',
                    $i,
                    (string) $stop,
                ));
            }
        }
        $this->colors = array_values($colors);
        $this->stops = $stops === [] ? [] : array_values($stops);
    }

    /**
     * 线性渐变：从 (x1,y1) 到 (x2,y2)。
     *
     * @param list<Color> $colors
     * @param list<float> $stops
     */
    public static function linear(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        array $colors,
        array $stops = [],
    ): self {
        return new self(false, $x1, $y1, $x2, $y2, $colors, $stops);
    }

    /**
     * 径向渐变：圆心 (cx,cy)（`x1/y1` 承载）半径 `radius`（`x2` 承载，`y2` 闲置）。
     *
     * @param list<Color> $colors
     * @param list<float> $stops
     */
    public static function radial(
        float $cx,
        float $cy,
        float $radius,
        array $colors,
        array $stops = [],
    ): self {
        if ($radius <= 0.0) {
            Errors::report('Gradient：径向半径必须为正，收到 ' . (string) $radius);
        }
        return new self(true, $cx, $cy, $radius, 0.0, $colors, $stops);
    }

    public function isRadial(): bool
    {
        return $this->radial;
    }
}
