<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 渐变（`Paint` 的填充源，Extended 层）。
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
 *
 * @api
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
        /**
         * 坐标空间是 `0..1`（**盒内比例**）还是绝对像素。
         *
         * ★ 为什么需要这个区分（A1a，2026-10-28）：`Gradient` 原先只有绝对坐标，
         *   那对"直接画在一块已知的矩形上"（golden 场景、`Canvas` 调用）是对的，
         *   但 `Card::make()->gradient(...)` 的人**不知道自己的盒多大**。
         *   于是盒内用法（`Modifier` / `BoxStyle`）走比例坐标，由消费方在拿到
         *   `Size` 之后调 `resolvedTo()` 换算 —— 换算只有一处，不会两处各写一遍。
         *
         * ★ **同一份 `Gradient` 在不同空间里的解释不同 ⇒ 必须显式标记**，不能靠
         *   "看起来像 0..1 就是比例"（`linear(0,0,1,0)` 是合法的绝对坐标渐变）。
         */
        public bool $relative = false,
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

    /**
     * **竖直**渐变（上 → 下），坐标是盒内比例。
     *
     * ```php
     * Card::make($body)->gradient(Gradient::vertical([Color::white(), $shade]))
     * ```
     *
     * 默认形式是"从上到下、铺满整个盒"—— 也就是界面里 99% 的用法。不需要知道盒多大。
     *
     * @param list<Color> $colors
     * @param list<float> $stops
     */
    public static function vertical(array $colors, array $stops = []): self
    {
        return new self(false, 0.0, 0.0, 0.0, 1.0, $colors, $stops, true);
    }

    /**
     * **水平**渐变（左 → 右），坐标是盒内比例。
     *
     * @param list<Color> $colors
     * @param list<float> $stops
     */
    public static function horizontal(array $colors, array $stops = []): self
    {
        return new self(false, 0.0, 0.0, 1.0, 0.0, $colors, $stops, true);
    }

    /**
     * **对角**渐变（左上 → 右下），坐标是盒内比例。
     *
     * @param list<Color> $colors
     * @param list<float> $stops
     */
    public static function diagonal(array $colors, array $stops = []): self
    {
        return new self(false, 0.0, 0.0, 1.0, 1.0, $colors, $stops, true);
    }

    /**
     * **盒内径向**渐变：圆心在盒中心、半径 = 短边的一半（内切圆），坐标是比例。
     *
     * 用途是"中心提亮"这类光晕（比竖直渐变更像"有光打在上面"）。
     *
     * @param list<Color> $colors
     * @param list<float> $stops
     */
    public static function radialBox(array $colors, array $stops = []): self
    {
        return new self(true, 0.5, 0.5, 0.5, 0.0, $colors, $stops, true);
    }

    /**
     * 把比例坐标换算成**绝对像素**，交给后端。
     *
     * 绝对坐标的渐变原样返回（同一个实例）—— 绝大多数"直接画在画布上"的调用
     * 走这条，不多一次分配。
     *
     * ★ **径向按短边缩放半径**（`radius × min(w,h)`）：等比才不会把圆画成椭圆。
     *   直线渐变两个方向各自按自己的轴缩放（那是"沿这条线插值"的本意）。
     */
    public function resolvedTo(float $width, float $height): self
    {
        if (!$this->relative) {
            return $this;
        }

        if ($this->radial) {
            $radius = $this->x2 * \min($width, $height);

            return new self(
                true,
                $this->x1 * $width,
                $this->y1 * $height,
                $radius,
                0.0,
                $this->colors,
                $this->stops,
            );
        }

        return new self(
            false,
            $this->x1 * $width,
            $this->y1 * $height,
            $this->x2 * $width,
            $this->y2 * $height,
            $this->colors,
            $this->stops,
        );
    }
}
