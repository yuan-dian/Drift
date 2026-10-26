<?php
declare(strict_types=1);

namespace Drift\Dev\Preview;

use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RenderSurface;

/**
 * @internal 把 `Frame` 的输出变成 **SVG 位图 runs**。
 *
 * ★ 为什么是"位图 runs"而不是"把绘制命令翻成 SVG 元素"：命令里嵌着字形位图
 *   （§14.2 的第三个必须钉死的输入），逐命令翻过去迟早要发明一套"SVG 字体"。
 *   先真画到软光栅、再把像素合并成 runs —— 走的是**同一条渲染路径**，于是
 *   golden 比对的是生产会画的东西，不是另一套近似（§14.1"渲染是纯函数"的落点）。
 *
 * ★ runs 逐行合并：同一行里连续同色的像素并成一条 `<rect>`。对点阵字与实色块
 *   压缩得很好（几百条而不是几万条），且**无损** —— 像素与软光栅逐字节一致。
 */
final class SvgSurface implements RenderSurface
{
    private int $width = 0;

    private int $height = 0;

    private ?RasterCanvas $raster = null;

    /** 清屏色（`setBackground` 改它）：既当光栅底色，也当 SVG 里"不输出"的底色 */
    private Color $background;

    /** @var list<string> 已产出的帧（多帧只在 devtools 场景用） */
    private array $frames = [];

    public function __construct()
    {
        $this->background = Color::white();
    }

    public function resize(int $width, int $height, float $dpr): void
    {
        $size = Scale::of($dpr)->quantizeRect(new Rect(0.0, 0.0, (float) $width, (float) $height));
        $this->width = (int) $size->width;
        $this->height = (int) $size->height;
    }

    public function setBackground(Color $color): void
    {
        $this->background = $color;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        $this->raster = new RasterCanvas($this->width, $this->height, $this->background, Scale::of(1.0));

        return $this->raster;
    }

    public function endFrame(): void
    {
        $raster = $this->raster;
        $this->raster = null;
        if ($raster === null) {
            return;
        }

        $this->frames[] = $this->toSvg($raster);
    }

    public function svg(int $index = -1): string
    {
        if ($this->frames === []) {
            return '';
        }

        return $this->frames[$index < 0 ? count($this->frames) + $index : $index];
    }

    public function frameCount(): int
    {
        return count($this->frames);
    }

    private function toSvg(RasterCanvas $raster): string
    {
        $out = [];
        $out[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">',
            $this->width,
            $this->height,
            $this->width,
            $this->height,
        );
        $out[] = sprintf('<rect width="%d" height="%d" fill="%s"/>', $this->width, $this->height, 'rgb(255,255,255)');
        // 底色当"透明"处理：不输出成 rect（默认白 ⇒ 与旧行为逐字节相同）
        $bgArgb = $this->background->toArgb();

        for ($y = 0; $y < $this->height; $y++) {
            $x = 0;
            while ($x < $this->width) {
                $start = $x;
                $color = $raster->pixelAt($x, $y);
                while ($x < $this->width && $raster->pixelAt($x, $y)->toArgb() === $color->toArgb()) {
                    $x++;
                }
                if ($color->toArgb() === $bgArgb) {
                    continue; // 背景不输出
                }
                $out[] = sprintf(
                    '<rect x="%d" y="%d" width="%d" height="1" fill="rgb(%d,%d,%d)"/>',
                    $start,
                    $y,
                    $x - $start,
                    $color->r,
                    $color->g,
                    $color->b,
                );
            }
        }

        $out[] = '</svg>';

        return implode("\n", $out);
    }
}
