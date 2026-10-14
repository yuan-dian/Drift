<?php
declare(strict_types=1);

namespace Drift\Geometry;

/**
 * 显示缩放（§6.1）：`dpr = dpi / 96.0`。
 *
 * ★ 全框架**只有这一个量化函数**。逻辑单位与物理像素之间那条边界，
 *   就在 `quantize()` 里，别处一律不许取整。
 *
 * @api
 */
final readonly class Scale
{
    public const BASE_DPI = 96.0;

    public function __construct(public float $dpr = 1.0)
    {
    }

    public static function identity(): self
    {
        return new self();
    }

    public static function of(float $dpr): self
    {
        return new self($dpr);
    }

    public static function fromDpi(float $dpi): self
    {
        return new self($dpi / self::BASE_DPI);
    }

    public function isIdentity(): bool
    {
        return $this->dpr === 1.0;
    }

    /** 唯一的量化函数：`physical = round(logical * dpr)` */
    public function quantize(float $logical): float
    {
        return round($logical * $this->dpr);
    }

    public function toLogical(float $physical): float
    {
        return $physical / $this->dpr;
    }

    /**
     * 矩形量化：**两条边各自取整**，不是"左边取整 + 宽度取整"。
     *
     * 后者会让相邻盒错开半像素（各自的宽度独立舍入）—— 这正是"矩形不错开
     * 半像素"这条规则要消灭的形态。返回的矩形在**物理像素**空间。
     */
    public function quantizeRect(Rect $rect): Rect
    {
        $left = round($rect->left * $this->dpr);
        $top = round($rect->top * $this->dpr);
        $right = round($rect->right() * $this->dpr);
        $bottom = round($rect->bottom() * $this->dpr);

        return Rect::fromLtrb($left, $top, $right, $bottom);
    }

    public function equals(Scale $other): bool
    {
        return $this->dpr === $other->dpr;
    }
}
