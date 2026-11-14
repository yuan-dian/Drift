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
    public const float BASE_DPI = 96.0;

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

    /**
     * 逻辑矩形 → 设备矩形：**先加偏移，再一次量化**。
     *
     * ★★ 顺序是契约，不是实现细节：`round((x + dx) * dpr)` 与
     *   `round(x * dpr) + round(dx * dpr)` 在 `dpr ≠ 1` 或偏移带半像素时**差整格**。
     *
     * ★★★ 为什么住在 `Scale` 里（2026-11-01）：这段 6 行此前被**两个后端各自
     *   手写一遍**（`SkiaCanvas::toDevice()` / `RasterCanvas::toDevice()`，逐字相同），
     *   另有三处更分散的拷贝（`drawLine` 四个标量 / `packPath` 六个标量 /
     *   渐变端点）。同一个公式写六遍 = 漂移的合法来源，而"逻辑→物理只在这一个
     *   文件里发生"这句话当时是**假的**（偏移加法在文件之外）。收进来之后它才是真的。
     *
     * ★ 判据在 `tests/Feature/Rendering/BackendDeviceSpaceTest.php`：那里用**定义**
     *   重算 `round((x+dx)*dpr)`（不调本方法），所以改这里的语义会红 ——
     *   否则就是"测试和实现共用一份错"。
     */
    public function deviceRect(Rect $rect, float $dx = 0.0, float $dy = 0.0): Rect
    {
        if ($dx === 0.0 && $dy === 0.0) {
            return $this->quantizeRect($rect);
        }

        return $this->quantizeRect(new Rect(
            $rect->left + $dx,
            $rect->top + $dy,
            $rect->width,
            $rect->height,
        ));
    }

    /**
     * 逻辑横坐标 → 设备横坐标（**不取整**）。
     *
     * 需要整数像素的调用方自己 `round()`（`drawLine` / `packPath` 的老口径：
     * 先叠加偏移、乘 dpr，最后**一次**取整）；渐变端点要连续 ⇒ 调用方不取整。
     */
    public function deviceX(float $x, float $dx = 0.0): float
    {
        return ($x + $dx) * $this->dpr;
    }

    /** 见 `deviceX()` */
    public function deviceY(float $y, float $dy = 0.0): float
    {
        return ($y + $dy) * $this->dpr;
    }

    public function equals(Scale $other): bool
    {
        return $this->dpr === $other->dpr;
    }
}
