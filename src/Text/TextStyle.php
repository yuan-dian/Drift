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

    public function equals(TextStyle $other): bool
    {
        return $this->cacheKey() === $other->cacheKey();
    }

    /** @param array<string, mixed> $changes */
    private function copy(array $changes): self
    {
        return new self(
            $changes['family'] ?? $this->family,
            $changes['size'] ?? $this->size,
            $changes['weight'] ?? $this->weight,
            $changes['slant'] ?? $this->slant,
            $changes['lineHeight'] ?? $this->lineHeight,
            $changes['letterSpacing'] ?? $this->letterSpacing,
            $changes['align'] ?? $this->align,
            $changes['maxLines'] ?? $this->maxLines,
            $changes['ellipsize'] ?? $this->ellipsize,
        );
    }
}
