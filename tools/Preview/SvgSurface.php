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
 * @internal 把 `Frame` 的输出变成 **SVG 位图 runs** 或 **PNG 位图**。
 *
 * ★ 为什么是"位图 runs"而不是"把绘制命令翻成 SVG 元素"：命令里嵌着字形位图
 *   （§14.2 的第三个必须钉死的输入），逐命令翻过去迟早要发明一套"SVG 字体"。
 *   先真画到软光栅、再把像素合并成 runs —— 走的是**同一条渲染路径**，于是
 *   golden 比对的是生产会画的东西，不是另一套近似（§14.1"渲染是纯函数"的落点）。
 *
 * ★ runs 逐行合并：同一行里连续同色的像素并成一条 `<rect>`。对点阵字与实色块
 *   压缩得很好（几百条而不是几万条），且**无损** —— 像素与软光栅逐字节一致。
 *
 * ★ 2026-11-12：PNG 也在这里编码了。原先 `tools/probe-raster-page.php` 自己
 *   new `RasterCanvas` 再手写一份 PNG，与这里是两条"看起来一样"的渲染路径；
 *   现在两种格式都从**同一帧的同一份 raster** 出来，只是编码不同。
 *   `capturePng` 默认关 ⇒ 既有 SVG / golden 路径零额外开销、输出逐字节不变。
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

    /** @var list<string> 与 `$frames` 一一对应的 PNG（只在 `capturePng` 打开时填） */
    private array $pngFrames = [];

    public function __construct(private readonly bool $capturePng = false)
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
        if ($this->capturePng) {
            $this->pngFrames[] = $this->toPng($raster);
        }
    }

    public function svg(int $index = -1): string
    {
        if ($this->frames === []) {
            return '';
        }

        return $this->frames[$index < 0 ? count($this->frames) + $index : $index];
    }

    /** @internal 与 `svg()` 同索引；没开 `capturePng` 或没跑过帧 ⇒ 空串 */
    public function png(int $index = -1): string
    {
        if ($this->pngFrames === []) {
            return '';
        }

        return $this->pngFrames[$index < 0 ? count($this->pngFrames) + $index : $index];
    }

    public function frameCount(): int
    {
        return count($this->frames);
    }

    /**
     * RGBA 原始串 → PNG（color type 2 = RGB，filter 0 逐行；白底不透明 ⇒ 丢 alpha）。
     *
     * ★ 与 `tools/golden-raster.php::goldenPng()` 同口径 —— 原先
     *   `probe-raster-page.php` 里那份是临时副本，合并后这里是唯一一份预览编码器。
     * ★ 手写而不走 GD：本机 PHP 没装 GD，且预览要保持"零扩展依赖"。
     */
    private function toPng(RasterCanvas $raster): string
    {
        $rgba = $raster->toRgba();
        $raw = '';
        for ($y = 0; $y < $this->height; $y++) {
            $raw .= "\x00"; // filter 0
            $base = $y * $this->width * 4;
            for ($x = 0; $x < $this->width; $x++) {
                $o = $base + $x * 4;
                $raw .= $rgba[$o] . $rgba[$o + 1] . $rgba[$o + 2];
            }
        }

        $chunk = static function (string $type, string $body): string {
            return pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
        };

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $this->width, $this->height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw, 6))
            . $chunk('IEND', '');
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
