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
use Drift\Rendering\DashPattern;
use Drift\Rendering\Gradient;
use Drift\Rendering\Paint;
use Drift\Rendering\Path;
use Drift\Rendering\RasterCanvas;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\CustomPaint;
use Drift\Widget\CustomPainter;
use Drift\Dev\Smoke\Golden\GoldenGaugePainter;
use Drift\Dev\Smoke\Golden\GoldenSparkPainter;
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

// ── 场景 N（P1 新增）：19 op 的 Core 层图元（椭圆填充/描边/线段）──
// ★ golden 只用 Raster（确定性）；Skia 的抗锯齿边缘不同，不跨后端比像素。
$scenes['ellipse-line'] = static function () use ($W, $H): string {
    $c = new RasterCanvas($W, $H, Color::white(), null);

    // 椭圆填充（不透明 / 半透明各一）
    $c->drawEllipse(new Rect(40.0, 40.0, 200.0, 120.0), Paint::fill(new Color(30, 90, 200, 255)));
    $c->drawEllipse(new Rect(300.0, 40.0, 200.0, 120.0), Paint::fill(new Color(200, 40, 40, 128)));

    // 椭圆描边（居中半宽口径）
    $c->drawEllipse(new Rect(560.0, 40.0, 200.0, 120.0), Paint::stroke(new Color(20, 20, 20, 255), 4.0));

    // 线段：水平 / 垂直 / 斜线（胶囊体谓词，各种宽度）
    $c->drawLine(40.0, 220.0, 240.0, 220.0, Paint::stroke(new Color(0, 0, 0, 255), 2.0));
    $c->drawLine(40.0, 260.0, 40.0, 360.0, Paint::stroke(new Color(0, 0, 0, 255), 6.0));
    $c->drawLine(300.0, 220.0, 460.0, 340.0, Paint::stroke(new Color(0, 120, 80, 255), 5.0));

    // 裁剪内的椭圆（clip 与椭圆求交）
    $c->save();
    $c->pushClip(new Rect(560.0, 220.0, 200.0, 140.0));
    $c->drawEllipse(new Rect(520.0, 200.0, 280.0, 180.0), Paint::fill(new Color(90, 60, 180, 220)));
    $c->popClip();
    $c->restore();

    // 变换后的线段（offset 叠加一次量化）
    $c->save();
    $c->translate(30.0, 420.0);
    $c->drawLine(0.0, 0.0, 160.0, 80.0, Paint::stroke(new Color(240, 160, 20, 255), 3.0));
    $c->restore();

    return $c->toRgba();
};
// ── 场景 N+1（P2 新增）：CustomPaint 逃生舱（画家直绘 Core 层图元）──
// ★ 与 showcase 的「自绘」页同构（同两只 painters，只差命名空间）：仪表盘 =
//   椭圆表盘 + 11 根刻度线段拼弧（无 arc op）+ 指针；走势 = 网格 + 折线 + 端点。
$scenes['custompaint'] = static function () use ($W, $H): string {
    $theme = ThemeData::light();
    $metrics = new DeterministicMetricsProvider(
        DeterministicFontManager::standard()->collection(),
        new DeterministicShaper(),
    );
    $context = new BuildContext((float) $W, (float) $H, $theme, $metrics);

    $root = Column::make()->spacing(10)->children([
        Text::make('自绘：仪表盘（椭圆表盘 + 刻度线 + 指针）')->title(),
        new CustomPaint(new GoldenGaugePainter(0.62), 360.0, 200.0),
        Text::make('自绘：走势折线（网格线 + 折线 + 端点标记）')->title(),
        new CustomPaint(new GoldenSparkPainter(), 360.0, 160.0),
    ]);

    $element = $root->createElement();
    $element->mount(null, $context);
    $element->settle();
    $element->effectiveBox()->layout(BoxConstraints::tight(new Size((float) $W, (float) $H)));

    $canvas = new RasterCanvas($W, $H, Color::white(), null);
    $element->effectiveBox()->paint($canvas);

    return $canvas->toRgba();
};

// P3 渐变/虚线：直接打在确定性后端上（不绕控件树 —— 量的是 canvas 这条路径本身）。
// 覆盖四条：线性渐变矩形（含 3 色标带显式位置）/ 径向渐变椭圆 / 线段虚线 /
// 描边矩形的虚线降级（R2 ⇒ 实线，不该出现空洞）。
$scenes['gradient-dash'] = static function () use ($W, $H): string {
    $c = new RasterCanvas($W, $H, Color::white(), null);

    $c->drawRect(new Rect(20.0, 20.0, 160.0, 60.0), Paint::fill(Color::black())->withGradient(
        Gradient::linear(20.0, 20.0, 180.0, 20.0, [
            Color::fromRgb(255, 0, 0),
            Color::fromRgb(255, 255, 0),
            Color::fromRgb(0, 0, 255),
        ], [0.0, 0.5, 1.0])
    ));

    $c->drawEllipse(new Rect(220.0, 20.0, 120.0, 60.0), Paint::fill(Color::black())->withGradient(
        Gradient::radial(280.0, 50.0, 60.0, [
            Color::fromRgb(255, 255, 255),
            Color::fromRgb(30, 90, 200),
        ])
    ));

    $c->drawLine(20.0, 110.0, 340.0, 110.0, Paint::stroke(Color::black(), 4.0)->withDash(new DashPattern(10.0, 8.0, 3.0)));
    $c->drawLine(20.0, 130.0, 340.0, 130.0, Paint::stroke(Color::black(), 2.0)->withDash(new DashPattern(6.0, 6.0)));

    // 描边矩形：虚线降级为实线（R2）
    $c->drawRect(new Rect(20.0, 150.0, 160.0, 50.0), Paint::stroke(Color::black(), 3.0)->withDash(new DashPattern(6.0, 6.0)));
    // 描边椭圆：同样降级
    $c->drawEllipse(new Rect(220.0, 150.0, 120.0, 50.0), Paint::stroke(Color::black(), 3.0)->withDash(new DashPattern(6.0, 6.0)));

    return $c->toRgba();
};

// ── 场景 7：路径（W1/L3-1；覆盖 fill / even-odd / 开放描边 / 闭合描边 / 贝塞尔）──
// ★ 这条基线是**新增**的：L3-1 之前 Raster 的 drawPath 是响亮失败，没有像素可钉。
// ★ 只走 Raster：无抗锯齿 ⇒ 边缘与 Skia 不同，像素级 golden 本来就不跨后端比。
// ★ 它同时是"translate() 偏移被吃进去了"的回归网 —— 那个坑的形态是真机完全
//   无墨而解释器全过，只有像素基线能抓住（`05` §1.3）。
$scenes['path'] = static function () use ($W, $H): string {
    $c = new RasterCanvas($W, $H, Color::white(), null);

    // ① 实心三角形
    $c->drawPath(
        Path::create()->moveTo(40.0, 40.0)->lineTo(200.0, 40.0)->lineTo(120.0, 160.0)->close(),
        Paint::fill(Color::fromRgb(30, 90, 200))
    );

    // ② 奇偶：两个错开的矩形，交集区留白（挖洞的唯一手段）
    $c->drawPath(
        Path::create()
            ->addRect(new Rect(240.0, 40.0, 120.0, 120.0))
            ->addRect(new Rect(300.0, 100.0, 120.0, 120.0)),
        Paint::fill(Color::fromRgb(200, 90, 30))
    );

    // ③ 二次贝塞尔穹顶（固定 16 段扁平化）
    $c->drawPath(
        Path::create()->moveTo(440.0, 160.0)->quadTo(520.0, 40.0, 600.0, 160.0)->close(),
        Paint::fill(Color::fromRgb(30, 160, 90))
    );

    // ④ 开放折线描边（Miter join + Butt cap）
    $c->drawPath(
        Path::create()->moveTo(40.0, 300.0)->lineTo(140.0, 220.0)->lineTo(240.0, 300.0),
        Paint::stroke(Color::black(), 12.0)
    );

    // ⑤ 闭合描边：环（外扩半宽 − 内收半宽）
    $c->drawPath(
        Path::create()->addRect(new Rect(300.0, 220.0, 200.0, 120.0)),
        Paint::stroke(Color::fromRgb(120, 30, 160), 14.0)
    );

    // ⑥ 半透明填充（source-over，与 drawRect 同一混合口径）
    $c->drawPath(
        Path::create()->moveTo(560.0, 380.0)->lineTo(760.0, 380.0)->lineTo(660.0, 520.0)->close(),
        Paint::fill(new Color(30, 90, 200, 128))
    );

    // ⑦ save/translate 之后再画同一个三角形（`05` §1.3 的回归）
    $c->save();
    $c->translate(60.0, 40.0);
    $c->drawPath(
        Path::create()->moveTo(40.0, 560.0)->lineTo(200.0, 560.0)->lineTo(120.0, 480.0)->close(),
        Paint::fill(Color::fromRgb(90, 90, 90))
    );
    $c->restore();

    return $c->toRgba();
};

// W1/L3-2：路径裁剪（每行 x 区间掩码）。三个子图分别钉住
// ① 单个圆裁剪（四角留白）② 嵌套求交（透镜）③ 裁剪内的文字（与 Skia 同口径）
$scenes['clipcircle'] = static function () use ($W, $H): string {
    $c = new RasterCanvas($W, $H, Color::white(), null);

    // ① 64 边形近似圆 r=60 居中；裁剪内画一个渐变横条 ⇒ 只有圆内有色
    $ring = Path::create();
    for ($i = 0; $i < 64; $i++) {
        $ang = $i * 2.0 * M_PI / 64.0;
        $x = 160.0 + 60.0 * cos($ang);
        $y = 120.0 + 60.0 * sin($ang);
        if ($i === 0) {
            $ring->moveTo($x, $y);
        } else {
            $ring->lineTo($x, $y);
        }
    }
    $ring->close();
    $c->pushClipPath($ring);
    $c->drawRect(
        new Rect(100.0, 100.0, 120.0, 40.0),
        Paint::fill(Color::fromRgb(30, 90, 200))
    );
    $c->popClip();

    // ② 嵌套：两个 r=60、圆心距 60 的圆求交 ⇒ 透镜；裁剪内画实心矩形
    $nestA = Path::create();
    $nestB = Path::create();
    for ($i = 0; $i < 64; $i++) {
        $ang = $i * 2.0 * M_PI / 64.0;
        if ($i === 0) {
            $nestA->moveTo(400.0 + 60.0 * cos($ang), 120.0 + 60.0 * sin($ang));
            $nestB->moveTo(460.0 + 60.0 * cos($ang), 120.0 + 60.0 * sin($ang));
        } else {
            $nestA->lineTo(400.0 + 60.0 * cos($ang), 120.0 + 60.0 * sin($ang));
            $nestB->lineTo(460.0 + 60.0 * cos($ang), 120.0 + 60.0 * sin($ang));
        }
    }
    $nestA->close();
    $nestB->close();
    $c->pushClipPath($nestA);
    $c->pushClipPath($nestB);
    $c->drawRect(
        new Rect(370.0, 90.0, 120.0, 60.0),
        Paint::fill(Color::fromRgb(200, 90, 30))
    );
    $c->popClip();
    $c->popClip();

    // ③ 裁剪 + save/translate 叠加：**裁剪路径按压入时刻的偏移量化**（与 Skia
    //    的 clipPath 同口径），**绘制按当前偏移** ⇒ 两侧分别吃 `05` §1.3 的偏移，
    //    再求交。压入时 offset=0、绘制时 offset=(40,40) ⇒ 交集是右下 20×20。
    $c->pushClipPath(
        Path::create()->addRect(new Rect(600.0, 90.0, 120.0, 60.0))
    );
    $c->save();
    $c->translate(40.0, 40.0);
    $c->drawRect(
        new Rect(600.0, 90.0, 120.0, 60.0),
        Paint::fill(Color::fromRgb(30, 160, 90))
    );
    $c->restore();
    $c->popClip();

    return $c->toRgba();
};

$out = [];foreach ($scenes as $name => $make) {
    $rgba = $make();
    $out[] = sprintf('%s: len=%d md5=%s', $name, strlen($rgba), md5($rgba));
}

file_put_contents(
    $argv[1] ?? 'php://stdout',
    implode("\n", $out) . "\n"
);
echo implode("\n", $out), "\n";
