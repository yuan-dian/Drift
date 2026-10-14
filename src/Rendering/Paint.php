<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 画笔：一次绘制调用的参数集（不可变值对象）。
 *
 * ★ 只管"怎么画"，不管"画成什么样" —— 背景 / 边框 / 圆角是 `BoxStyle`（§15.7）
 *   的事，`Paint` 是渲染层的原语。两者的转换发生在 RenderObject（§13.2）。
 *
 * @api
 */
final readonly class Paint
{
    public function __construct(
        public Color $color,
        public PaintStyle $style = PaintStyle::Fill,
        public float $strokeWidth = 1.0,
    ) {
        if ($style === PaintStyle::Stroke && $strokeWidth <= 0.0) {
            Errors::report('Paint：描边宽度必须为正，收到 ' . (string) $strokeWidth);
        }
    }

    public static function fill(Color $color): self
    {
        return new self($color, PaintStyle::Fill);
    }

    public static function stroke(Color $color, float $width = 1.0): self
    {
        return new self($color, PaintStyle::Stroke, $width);
    }

    public function withColor(Color $color): self
    {
        return new self($color, $this->style, $this->strokeWidth);
    }

    public function withAlpha(int $alpha): self
    {
        return new self($this->color->withAlpha($alpha), $this->style, $this->strokeWidth);
    }

    public function isStroke(): bool
    {
        return $this->style === PaintStyle::Stroke;
    }

    public function equals(Paint $other): bool
    {
        return $this->color->equals($other->color)
            && $this->style === $other->style
            && $this->strokeWidth === $other->strokeWidth;
    }
}
