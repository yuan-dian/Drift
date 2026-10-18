<?php
declare(strict_types=1);

// 软光栅 canvas 层的 golden 回归网（tools/golden-raster.php）：
// 渲染一批覆盖**所有绘制路径**的场景，输出各场景 md5。
// 用法：改动 `RasterCanvas` / 字形 / Widget 绘制链前后各跑一次，逐行 diff ——
//   php tools/golden-raster.php > _build/golden-before.txt
//   （改动）
//   php tools/golden-raster.php > _build/golden-after.txt
//   diff _build/golden-before.txt _build/golden-after.txt   # 必须无输出
// ★ 这不是测试套件是**量具**：改 canvas 的人自己跑，md5 一个字节都不许变。
// 覆盖路径：opaque/半透明/圆角/描边-hole/裁剪/save-translate/段落字形。
require __DIR__ . '/../tools/autoload.php';

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Rendering\RasterCanvas;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;

$W = 900;
$H = 640;

$scenes = [];

// ── 场景 1：canvas 图元直绘（覆盖 rasterize 各分支）──
$scenes['prims'] = static function () use ($W, $H): string {
    $c = new RasterCanvas($W, $H, Color::white(), null);
    $r = new Rect(20.0, 20.0, 860.0, 80.0);

    $c->drawRect($r, Paint::fill(new Color(30, 90, 200, 255)));                       // opaque 直角
    $c->drawRect(new Rect(20.0, 120.0, 860.0, 80.0), Paint::fill(new Color(30, 90, 200, 255)), 12.0);   // opaque 圆角
    $c->drawRect(new Rect(20.0, 220.0, 860.0, 80.0), Paint::fill(new Color(0, 0, 0, 60)), 12.0);        // 半透明圆角
    $c->drawRect(new Rect(20.0, 320.0, 860.0, 80.0), Paint::stroke(new Color(200, 40, 40, 255), 3.0), 12.0); // 描边（hole）
    $c->drawRect(new Rect(20.0, 420.0, 860.0, 80.0), Paint::fill(new Color(0, 120, 80, 128)), 0.0);     // 半透明直角
    $c->drawRect(new Rect(20.0, 520.0, 860.0, 80.0), Paint::fill(new Color(90, 60, 180, 200)), 6.0);    // 半透明圆角2

    // 裁剪 + save/translate：圆角 clip 内画一个不透明矩形
    $c->save();
    $c->pushClip(new Rect(100.0, 100.0, 300.0, 200.0));
    $c->translate(30.0, 40.0);
    $c->drawRect(new Rect(0.0, 0.0, 500.0, 400.0), Paint::fill(new Color(240, 200, 60, 255)), 0.0);
    $c->popClip();
    $c->restore();

    // 三层阴影（RenderCard 的实际形态）
    foreach ([3 => 0.35, 2 => 0.60, 1 => 1.00] as $grow => $weight) {
        $c->drawRect(
            new Rect(20.0 - $grow, 620.0 - $grow, 860.0 + 2 * $grow, 12.0 + 2 * $grow),
            Paint::fill(new Color(0, 0, 0, max(1, (int) round(90 * $weight)))),
            8.0 + $grow,
        );
    }

    return $c->toRgba();
};

// ── 场景 2：文本（段落 + 字形混合，含中英文与不同字号）──
$scenes['text'] = static function () use ($W, $H): string {
    $c = new RasterCanvas($W, $H, Color::white(), null);
    $shape = static function (string $s, float $size, Color $color) use ($c): void {
        // 走 Paragraph 太依赖内部 shaping，直接用已造好的 GlyphBitmap 路径更稳：
        // 这里退化为"手动 blend 一个字"不合适 —— 改走公开 drawParagraph 需要 Paragraph 构造器。
        // 于是场景 2 交给场景 3 的 widget 文本覆盖（同一套 drawParagraph → blendBitmap 路径）。
    };
    unset($shape);

    return $c->toRgba();
};

// ── 场景 3：widget 端到端（Card 三层阴影 + Row + 中英文本 + 滚动裁剪）──
$scenes['widget'] = static function () use ($W, $H): string {
    $theme = ThemeData::light();
    $metrics = new DeterministicMetricsProvider(
        DeterministicFontManager::standard()->collection(),
        new DeterministicShaper(),
    );
    $context = new BuildContext((float) $W, (float) $H, $theme, $metrics);

    $root = ScrollView::make(
        Column::make()->children([
            Card::make(
                Row::make()->spacing(12)->children([
                    Text::make('用户 0123456789'),
                    Text::make('user0123456789@example.com')->caption(),
                ])
            ),
            Card::make(Row::make()->spacing(8)->children([
                Text::make('第二行 abc ABC 你好世界')->title(),
            ])),
            Card::make(Text::make('第三行 body 样式，中英混排 mixed 123。')),
            Card::make(Text::make('第四行 caption 小字 small text')->caption()),
        ])
    );

    $element = $root->createElement();
    $element->mount(null, $context);
    $element->settle();
    $element->effectiveBox()->layout(BoxConstraints::tight(new Size((float) $W, (float) $H)));

    $canvas = new RasterCanvas($W, $H, Color::white(), null);
    $element->effectiveBox()->paint($canvas);

    return $canvas->toRgba();
};

$out = [];
foreach ($scenes as $name => $make) {
    $rgba = $make();
    $out[] = sprintf('%s: len=%d md5=%s', $name, strlen($rgba), md5($rgba));
}

file_put_contents(
    $argv[1] ?? 'php://stdout',
    implode("\n", $out) . "\n"
);
echo implode("\n", $out), "\n";
