<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 文本样式（§4.2 的 `Drift\Text`）。
 *
 * ★ 这是**文本栈**的样式（字体族 / 字重 / 排版参数），与 widget 层的
 *   `->size()` / `->bold()` **不是同一个东西**（§15.7 明确否决了在 widget 层
 *   再造一个 `TextStyle`）。widget 层的角色方法经主题解析后落成这里的值。
 *
 * ★ 对齐 / 行数上限 / 省略号也在这里，而不是 `TextMetricsProvider::layout()` 的
 *   参数 —— 因为 §8.1 把排版结果的缓存键钉死为 `(text, style, maxWidth, scale)`：
 *   凡是影响排版的输入都必须在 `style` 里，否则**同键不同结果**（缓存会撒谎）。
 *
 * 尺寸是**逻辑单位**（DIP，§6.1）—— 塑形在设备分辨率发生，但那由 `Scale` 负责，
 * 不在这里。
 *
 * @api
 */
final readonly class TextStyle
{
    public readonly FontFamily $family;

    public function __construct(
        ?FontFamily $family = null,
        public float $size = 14.0,
        public FontWeight $weight = FontWeight::Regular,
        public FontSlant $slant = FontSlant::Normal,
        public float $lineHeight = 1.2,
        public float $letterSpacing = 0.0,
        public TextAlign $align = TextAlign::Start,
        /** 0 = 不限行数 */
        public int $maxLines = 0,
        public bool $ellipsize = false,
    ) {
        $this->family = $family ?? FontFamily::sans();
    }

    public static function of(float $size): self
    {
        return new self(null, $size);
    }

    public function withFamily(FontFamily $family): self
    {
        return $this->copy(['family' => $family]);
    }

    public function withSize(float $size): self
    {
        return $this->copy(['size' => $size]);
    }

    public function withWeight(FontWeight $weight): self
    {
        return $this->copy(['weight' => $weight]);
    }

    public function withSlant(FontSlant $slant): self
    {
        return $this->copy(['slant' => $slant]);
    }

    public function withLineHeight(float $lineHeight): self
    {
        return $this->copy(['lineHeight' => $lineHeight]);
    }

    public function withLetterSpacing(float $letterSpacing): self
    {
        return $this->copy(['letterSpacing' => $letterSpacing]);
    }

    public function withAlign(TextAlign $align): self
    {
        return $this->copy(['align' => $align]);
    }

    public function withMaxLines(int $maxLines): self
    {
        return $this->copy(['maxLines' => $maxLines]);
    }

    public function withEllipsize(bool $ellipsize): self
    {
        return $this->copy(['ellipsize' => $ellipsize]);
    }

    /** 排版结果的缓存键分量（§8.1：键 = (text, style, maxWidth, scale)） */
    public function cacheKey(): string
    {
        return implode('|', [
            $this->family->name,
            $this->family->logical ? 'L' : 'N',
            (string) $this->size,
            (string) $this->weight->value,
            $this->slant->value,
            (string) $this->lineHeight,
            (string) $this->letterSpacing,
            $this->align->value,
            (string) $this->maxLines,
            $this->ellipsize ? 'E' : '-',
        ]);
    }

    /**
     * 逐字段比较。
     *
     * ★ 不走 `cacheKey()`：那是**排版缓存的键**，要拼一个字符串（10 次转换 + concat）；
     *   逐字段比是它的十分之一。两者语义等价，但 `equals()` 在每帧每文本的热路径上。
     */
    public function equals(TextStyle $other): bool
    {
        return $this->size === $other->size
            && $this->family->equals($other->family)
            && $this->weight === $other->weight
            && $this->slant === $other->slant
            && $this->lineHeight === $other->lineHeight
            && $this->letterSpacing === $other->letterSpacing
            && $this->align === $other->align
            && $this->maxLines === $other->maxLines
            && $this->ellipsize === $other->ellipsize;
    }

    /**
     * 派生新样式；**什么都没变就返回 `$this`**。
     *
     * ★ `Text::resolveStyle()` 每帧都做 `withMaxLines(...)->withEllipsize(...)`，参数通常
     *   与默认值相同 ⇒ 不短路的话每次重建都白造两个对象，还会让"同一样式"失去身份可比性
     *   （`RenderText::matchesConfiguration()` 因此只能走逐字段比较）。
     *
     * @param array<string, mixed> $changes
     */
    private function copy(array $changes): self
    {
        $family = $changes['family'] ?? $this->family;
        $size = $changes['size'] ?? $this->size;
        $weight = $changes['weight'] ?? $this->weight;
        $slant = $changes['slant'] ?? $this->slant;
        $lineHeight = $changes['lineHeight'] ?? $this->lineHeight;
        $letterSpacing = $changes['letterSpacing'] ?? $this->letterSpacing;
        $align = $changes['align'] ?? $this->align;
        $maxLines = $changes['maxLines'] ?? $this->maxLines;
        $ellipsize = $changes['ellipsize'] ?? $this->ellipsize;

        if ($family === $this->family
            && $size === $this->size
            && $weight === $this->weight
            && $slant === $this->slant
            && $lineHeight === $this->lineHeight
            && $letterSpacing === $this->letterSpacing
            && $align === $this->align
            && $maxLines === $this->maxLines
            && $ellipsize === $this->ellipsize
        ) {
            return $this;
        }

        return new self($family, $size, $weight, $slant, $lineHeight, $letterSpacing, $align, $maxLines, $ellipsize);
    }
}
