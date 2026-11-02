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
        /**
         * 边框**虚线**图案（`null` = 实线）。
         *
         * ★ 只影响 `borderColor` + `borderWidth` 那一次描边；没有边框时它**什么也
         *   不做**（虚线是描边的属性，不是独立的一层）。
         * ★ 与 `borderWidth` 同口径（逻辑像素，不乘 dpr）—— `DashPattern` 的类头
         *   已经把这条写死了，这里不重复开一个口径。
         * ★ 为什么是 `?DashPattern` 而不是 `float $dashOn/Off`：图案本身有 `phase`
         *   （接缝错开半周期），三个字段散在这儿会重新制造"同一维度的两份状态"。
         * ★★ **后端差异（2026-11-02 实测）**：真机（Skia）画**真虚线**；软光栅
         *   （`RasterCanvas`，单测 / golden 走它）**降级为实线**（R2 的既有取舍，
         *   见 `RasterCanvas::drawRect()`）。⇒ 虚线的效果**只能靠真机看图验**，
         *   golden 抓不到它。
         */
        public ?DashPattern $borderDash = null,
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
        return new self($color, $this->borderColor, $this->borderWidth, $this->radius, $this->backgroundGradient, $this->borderDash);
    }

    /** 底色换成渐变（`Gradient::vertical()` 这类盒内比例的形态） */
    public function withBackgroundGradient(Gradient $gradient): self
    {
        return new self($this->background, $this->borderColor, $this->borderWidth, $this->radius, $gradient, $this->borderDash);
    }

    /** 清掉渐变（若有实色底，它重新生效） */
    public function withoutBackgroundGradient(): self
    {
        if ($this->backgroundGradient === null) {
            return $this;
        }

        return new self($this->background, $this->borderColor, $this->borderWidth, $this->radius, null, $this->borderDash);
    }

    public function withBorder(Color $color, float $width): self
    {
        return new self($this->background, $color, $width, $this->radius, $this->backgroundGradient, $this->borderDash);
    }

    public function withRadius(float $radius): self
    {
        return new self($this->background, $this->borderColor, $this->borderWidth, $radius, $this->backgroundGradient, $this->borderDash);
    }

    /**
     * 边框改虚线（`null` = 回到实线）。
     *
     * ★ 只改图案、**不动颜色与宽度** —— 调用方通常写成
     *   `->border(色, 2)->borderDash(DashPattern::dashed(6, 4))`，
     *   两个方法各自只负责一维。
     */
    public function withBorderDash(?DashPattern $dash): self
    {
        return new self($this->background, $this->borderColor, $this->borderWidth, $this->radius, $this->backgroundGradient, $dash);
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
     * **一个样式字段都没设过** —— 与 `isNone()` 是两件事，别互换。
     *
     * ★ 差别只在"设了但不画"的组合：只设了圆角（无底无边）、或只设了边框色而宽度
     *   为 0。那些情况 `isNone()` 为真（画面确实一样），但**声明确实发生过** ——
     *   `Modifier::isDefault()` 与 `withoutBoxVisual()` 要的正是"发生过没有"：
     *   前者决定"声明了修饰却无处可去"要不要响亮失败，后者决定要不要剥。
     *   拿 `isNone()` 当这个判据 ⇒ `->radius(4)` 会被当成"没声明"，静默丢掉。
     */
    public function isBlank(): bool
    {
        return $this->background === null
            && $this->backgroundGradient === null
            && $this->borderColor === null
            && $this->radius === 0.0
            // ★ 只设了虚线也算"设过"：它确实会改变画面（一旦有边框），
            //   漏这一项 ⇒ `->borderDash(...)` 被当成"没声明"、静默丢掉。
            && $this->borderDash === null;
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
            && $this->backgroundGradient == $other->backgroundGradient
            // 虚线同理（`DashPattern` 没有 `equals()`，三个 float 字段用 `==` 足够）
            && $this->borderDash == $other->borderDash;
    }

    private function sameColor(?Color $a, ?Color $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return $a->equals($b);
    }
}
