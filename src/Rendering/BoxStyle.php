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
        return new self($color, $this->borderColor, $this->borderWidth, $this->radius);
    }

    public function withBorder(Color $color, float $width): self
    {
        return new self($this->background, $color, $width, $this->radius);
    }

    public function withRadius(float $radius): self
    {
        return new self($this->background, $this->borderColor, $this->borderWidth, $radius);
    }

    public function hasBackground(): bool
    {
        return $this->background !== null;
    }

    public function hasBorder(): bool
    {
        return $this->borderColor !== null && $this->borderWidth > 0.0;
    }

    /** 什么也不画 ⇒ 渲染层可以整个跳过（零成本路径） */
    public function isNone(): bool
    {
        return $this->background === null && !$this->hasBorder();
    }

    public function equals(BoxStyle $other): bool
    {
        return $this->sameColor($this->background, $other->background)
            && $this->sameColor($this->borderColor, $other->borderColor)
            && $this->borderWidth === $other->borderWidth
            && $this->radius === $other->radius;
    }

    private function sameColor(?Color $a, ?Color $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return $a->equals($b);
    }
}
