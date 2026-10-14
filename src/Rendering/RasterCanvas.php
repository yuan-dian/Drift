<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Rect;

/**
 * 软光栅画布：无头预览、CI、测试（§7.2 裁决 1）。
 *
 * ★ **无 OS 依赖**是它的全部价值 —— §14 确定性的物质基础：布局层零 OS 依赖
 *   ⇒ 可在 `php.exe` 里直接跑完整渲染，不编译、不开窗口。
 *
 * ★ 光栅顺序 = 录制顺序，且只依赖（命令流、裁剪、当前颜色）⇒ 同一份
 *   `DisplayList` 重放必然得到同一张位图（G3）。
 *
 * 覆盖能力：轴对齐矩形（填充 / 描边 / 圆角）、平移、矩形裁剪、source-over 混合。
 * 文本由 `Drift\Text` 的段落光栅补齐（见后续提交）。
 *
 * @api
 */
final class RasterCanvas implements Canvas
{
    private string $pixels;
    private float $offsetX = 0.0;
    private float $offsetY = 0.0;
    private Rect $clip;

    /** @var list<array{float, float, Rect}> 状态栈：偏移 + 裁剪 */
    private array $stack = [];

    public function __construct(
        private readonly int $width,
        private readonly int $height,
        ?Color $background = null,
    ) {
        $this->pixels = str_repeat("\x00", $width * $height * 4);
        $this->clip = new Rect(0.0, 0.0, (float) $width, (float) $height);

        if ($background !== null) {
            $this->clear($background);
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /** 原始 RGBA 字节流（行优先，每像素 4 字节） */
    public function toRgba(): string
    {
        return $this->pixels;
    }

    public function pixelAt(int $x, int $y): Color
    {
        $i = ($y * $this->width + $x) * 4;
        return new Color(
            ord($this->pixels[$i]),
            ord($this->pixels[$i + 1]),
            ord($this->pixels[$i + 2]),
            ord($this->pixels[$i + 3])
        );
    }

    public function clear(Color $color): void
    {
        $this->rasterize(
            new Rect(0.0, 0.0, (float) $this->width, (float) $this->height),
            0.0,
            $color,
            null,
            0.0
        );
    }

    public function save(): void
    {
        $this->stack[] = [$this->offsetX, $this->offsetY, $this->clip];
    }

    public function restore(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return; // 未配对的 restore 由 W8 在录制侧拦截，光栅侧静默忽略即可
        }
        [$this->offsetX, $this->offsetY, $this->clip] = $frame;
    }

    public function translate(float $dx, float $dy): void
    {
        $this->offsetX += $dx;
        $this->offsetY += $dy;
    }

    public function pushClip(Rect $rect): void
    {
        $device = $this->toDevice($rect);
        $this->clip = $this->clip->intersect($device);
    }

    public function popClip(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return;
        }
        [$this->offsetX, $this->offsetY, $this->clip] = $frame;
    }

    public function drawRect(Rect $rect, Paint $paint, float $radius = 0.0): void
    {
        $device = $this->toDevice($rect);

        if (!$paint->isStroke()) {
            $this->rasterize($device, $radius, $paint->color, null, 0.0);
            return;
        }

        // 描边 = 外圈减去内圈：一个循环搞定，圆角自然跟随
        $w = $paint->strokeWidth;
        $hole = new Rect(
            $device->left + $w,
            $device->top + $w,
            $device->width - 2.0 * $w,
            $device->height - 2.0 * $w
        );
        $this->rasterize($device, $radius, $paint->color, $hole, max(0.0, $radius - $w));
    }

    private function toDevice(Rect $rect): Rect
    {
        return new Rect($rect->left + $this->offsetX, $rect->top + $this->offsetY, $rect->width, $rect->height);
    }

    private function rasterize(Rect $rect, float $radius, Color $color, ?Rect $hole, float $holeRadius): void
    {
        if ($color->a === 0) {
            return;
        }

        $x0 = max((int) floor($rect->left), 0);
        $y0 = max((int) floor($rect->top), 0);
        $x1 = min((int) ceil($rect->right()), $this->width);
        $y1 = min((int) ceil($rect->bottom()), $this->height);
        $clipRight = $this->clip->right();
        $clipBottom = $this->clip->bottom();

        for ($y = $y0; $y < $y1; $y++) {
            $py = $y + 0.5;
            if ($py < $this->clip->top || $py >= $clipBottom) {
                continue;
            }
            for ($x = $x0; $x < $x1; $x++) {
                $px = $x + 0.5;
                if ($px < $this->clip->left || $px >= $clipRight) {
                    continue;
                }
                if ($radius > 0.0 && !$this->insideRoundRect($px, $py, $rect, $radius)) {
                    continue;
                }
                if ($hole !== null && $this->insideRoundRect($px, $py, $hole, $holeRadius)) {
                    continue;
                }
                $this->blend($x, $y, $color);
            }
        }
    }

    private function insideRoundRect(float $px, float $py, Rect $rect, float $radius): bool
    {
        if ($px < $rect->left || $px > $rect->right() || $py < $rect->top || $py > $rect->bottom()) {
            return false;
        }
        if ($radius <= 0.0) {
            return true;
        }

        $radius = min($radius, min($rect->width, $rect->height) / 2.0);
        $cx = min(max($px, $rect->left + $radius), $rect->right() - $radius);
        $cy = min(max($py, $rect->top + $radius), $rect->bottom() - $radius);
        $dx = $px - $cx;
        $dy = $py - $cy;

        return $dx * $dx + $dy * $dy <= $radius * $radius;
    }

    /** source-over 混合 */
    private function blend(int $x, int $y, Color $color): void
    {
        $i = ($y * $this->width + $x) * 4;
        $alpha = $color->a;

        if ($alpha === 255) {
            $this->pixels[$i] = chr($color->r);
            $this->pixels[$i + 1] = chr($color->g);
            $this->pixels[$i + 2] = chr($color->b);
            $this->pixels[$i + 3] = "\xFF";
            return;
        }

        $inverse = 255 - $alpha;
        $this->pixels[$i] = chr((int) round(($color->r * $alpha + ord($this->pixels[$i]) * $inverse) / 255));
        $this->pixels[$i + 1] = chr((int) round(($color->g * $alpha + ord($this->pixels[$i + 1]) * $inverse) / 255));
        $this->pixels[$i + 2] = chr((int) round(($color->b * $alpha + ord($this->pixels[$i + 2]) * $inverse) / 255));
        $this->pixels[$i + 3] = chr(min(255, $alpha + (int) round(ord($this->pixels[$i + 3]) * $inverse / 255)));
    }
}
