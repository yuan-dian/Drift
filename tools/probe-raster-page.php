<?php
declare(strict_types=1);

/**
 * 任意 showcase 页在 **Raster 后端**（无头、纯 PHP）下出一张位图 PNG。
 *
 * 用法：php tools/probe-raster-page.php <PageClass> [宽] [高] [out.png]
 *   例：php tools/probe-raster-page.php App\\Showcase\\Pages\\CustomPaintPage 900 640 _build/x.png
 *
 * ★ 与 drift-preview 同一条渲染路径（Frame → DisplayList → RasterCanvas），
 *   只是把 SvgSurface 换成直接收 RGBA ⇒ 看得见 Raster 侧真实的像素。
 *   preview 的 SVG 是位图的事后编码（每像素一个 rect），位图更直接。
 * ★ Frame 只认 RenderSurface 不认 Canvas ⇒ 包一个最小 surface（beginFrame
 *   交出白底 RasterCanvas）。
 * ★ PNG 手写（color type 2 + gzcompress）—— 本机 PHP 没装 GD。
 */
// 与 drift-preview 同一条渲染路径（Frame → DisplayList → RasterCanvas），
// 只是把 SvgSurface 换成直接收 RGBA ⇒ 看得见 Raster 侧真实的像素。
//
// 用法：php _build/w1-raster-png.php [宽] [高] [out.png]

require dirname(__DIR__) . '/tools/autoload.php';

use Drift\App\Frame;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RenderSurface;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Widget;

$pageClass = $argv[1] ?? 'App\Showcase\Pages\CustomPaintPage';
$w = (int) ($argv[2] ?? 900);
$h = (int) ($argv[3] ?? 640);
$out = $argv[4] ?? ('D:\TypePHP\Drift2\_build\raster-page.png');

// 最小 RenderSurface：beginFrame 交出一个白底 RasterCanvas。
// ★ 用**匿名类**而不是命名类：入口脚本里定义类会让 `namespace-mismatch`
//   把它当 PSR-4 类文件管（tools/ 前缀要求 Drift\Dev 命名空间）⇒ 误报。
//   匿名类不产生类型声明，脚本仍是"入口脚本"身份。
$surface = new class($w, $h) implements RenderSurface {
    public ?RasterCanvas $last = null;

    /** 清屏色（`setBackground` 改它） */
    public Color $background;

    public function __construct(public int $w, public int $h)
    {
        $this->background = Color::white();
    }

    public function resize(int $width, int $height, float $dpr): void
    {
    }

    public function setBackground(Color $color): void
    {
        $this->background = $color;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        $this->last = new RasterCanvas($this->w, $this->h, $this->background, Scale::of(1.0));

        return $this->last;
    }

    public function endFrame(): void
    {
    }
};

$context = new BuildContext(
    (float) $w,
    (float) $h,
    ThemeData::light(),
    new DeterministicMetricsProvider(
        DeterministicFontManager::standard()->collection(),
        new DeterministicShaper(),
    )
);

/** @var class-string<Widget> $pageClass */
$element = (new $pageClass())->createElement();
$element->mount(null, $context);

$frame = new Frame($surface);
$frame->run($element, BoxConstraints::tight(new Size((float) $w, (float) $h)), 0.0);

$canvas = $surface->last;
if ($canvas === null) {
    fwrite(STDERR, "no canvas —— Frame 没跑帧\n");
    exit(1);
}

// ── RGBA → PNG（color type 2，无 alpha 通道；filter 0 逐行） ──
$rgba = $canvas->toRgba();
$raw = '';
for ($y = 0; $y < $h; $y++) {
    $raw .= "\x00";
    $base = $y * $w * 4;
    for ($x = 0; $x < $w; $x++) {
        $o = $base + $x * 4;
        $raw .= $rgba[$o] . $rgba[$o + 1] . $rgba[$o + 2];
    }
}

$chunk = static function (string $type, string $body): string {
    return pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
};

$png = "\x89PNG\r\n\x1a\n"
    . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
    . $chunk('IDAT', gzcompress($raw, 6))
    . $chunk('IEND', '');

if (file_put_contents($out, $png) === false) {
    fwrite(STDERR, "write fail\n");
    exit(1);
}
fwrite(STDOUT, 'written ' . $out . ' ' . strlen($png) . " bytes\n");
