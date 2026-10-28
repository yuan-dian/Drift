<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 盒样式：只管"画成什么样"，不管几何（§15.7 / §13.1）。
 *
 * 三个面的分工（拆面表见 §15.7）：语义颜色走主题角色、**盒绘制样式走这里**、
 * 文本样式走文本族自己的字段、宽高间距走几何面。`null` 字段 = 该项不绘制。
 *
 * @api
 */
final readonly class BoxStyle
{
    public function __construct(
        public ?Color $background = null,
        public ?Color $borderColor = null,
        public float $borderWidth = 0.0,   // 0 = 无边框
        public float $radius = 0.0,        // 0 = 直角
        /**
         * 底色渐变（坐标是**盒内比例**，画的时候调 `resolvedTo()`）。
         *
         * ★ 与 `background` 并存是合法的：**渐变赢**（`hasBackground()` 判两者任一）。
         *   为什么不合并成一个"paint source"字段：`?Color|?Gradient` 的联合类型在
         *   tpc 下会退化成 `php::Var`（与 `Modifier` 那条账相同），而拆成两个可空
         *   字段在 AOT 上是安全的 —— 这里两个字段都是"同一维度的两种填法"，
         *   语义由 `hasBackground()` 统一表达，不构成两份状态。
         */
        public ?Gradient $backgroundGradient = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * ★ 逐字段 with-改写。
     *
     * 不做带默认值的 `copyWith` —— 那需要哨兵去区分"没传"与"传 null"，
     * 正好撞 §15.2 的哨兵定则。
     */
    public function withBackground(Color $color): self
    {
        return new self($color, $this->borderColor, $this->borderWidth, $this->radius, $this->backgroundGradient);
    }

    /** 底色换成渐变（`Gradient::vertical()` 这类盒内比例的形态） */
    public function withBackgroundGradient(Gradient $gradient): self
    {
        return new self($this->background, $this->borderColor, $this->borderWidth, $this->radius, $gradient);
    }

    /** 清掉渐变（若有实色底，它重新生效） */
    public function withoutBackgroundGradient(): self
    {
        if ($this->backgroundGradient === null) {
            return $this;
        }

        return new self($this->background, $this->borderColor, $this->borderWidth, $this->radius);
    }

    public function withBorder(Color $color, float $width): self
    {
        return new self($this->background, $color, $width, $this->radius, $this->backgroundGradient);
    }

    public function withRadius(float $radius): self
    {
        return new self($this->background, $this->borderColor, $this->borderWidth, $radius, $this->backgroundGradient);
    }

    /** 有底（实色或渐变）—— 判据统一在这里，画的地方不用各写一遍 */
    public function hasBackground(): bool
    {
        return $this->background !== null || $this->backgroundGradient !== null;
    }

    public function hasBorder(): bool
    {
        return $this->borderColor !== null && $this->borderWidth > 0.0;
    }

    /** 什么也不画 ⇒ 渲染层可以整个跳过（零成本路径） */
    public function isNone(): bool
    {
        return !$this->hasBackground() && !$this->hasBorder();
    }

    /**
     * 底色的**画笔**（渐变的比例坐标在这里按盒尺寸落成绝对像素）。
     *
     * ## 为什么收口成一个方法（A1a，2026-10-28）
     *
     * 三个自绘 chrome 的渲染盒（`RenderButton` / `RenderAppBar` / `RenderPopupPanel`）
     * 各自写着"有底就 `Paint::fill`"，加渐变时就要各改一遍 —— 漏一处不报错，只是
     * 那个控件的渐变底**静默不生效**。收在这里之后，"底色怎么画"只有一个答案，
     * 以后再加 paint source（图案 / 图片底）也只改这一个方法。
     *
     * ★ `null` = 没有底（不发画笔，调用方跳过）。
     * ★ 只有渐变没有实色时，base color 取**第一个色标** —— 后端在渐变分支里不用
     *   它调色，但它的 alpha 参与"能不能走不透明快路"，取色标才不会把半透明渐变
     *   错当成不透明。
     */
    public function backgroundPaint(float $width, float $height): ?Paint
    {
        if ($this->backgroundGradient !== null) {
            $base = $this->background ?? $this->backgroundGradient->colors[0];

            return Paint::fill($base)->withGradient($this->backgroundGradient->resolvedTo($width, $height));
        }

        if ($this->background !== null) {
            return Paint::fill($this->background);
        }

        return null;
    }

    public function equals(BoxStyle $other): bool
    {
        return $this->sameColor($this->background, $other->background)
            && $this->sameColor($this->borderColor, $other->borderColor)
            && $this->borderWidth === $other->borderWidth
            && $this->radius === $other->radius
            // 渐变是值对象 ⇒ `==`（同构不同实例仍是"同一样式"）
            && $this->backgroundGradient == $other->backgroundGradient;
    }

    private function sameColor(?Color $a, ?Color $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return $a->equals($b);
    }
}
