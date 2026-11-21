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

use Drift\App\Frame;
use Drift\Dev\Support\CheckItem;
use Drift\Dev\Support\CheckReport;
use Drift\Dev\Support\SceneSet;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\DashPattern;
use Drift\Rendering\Gradient;
use Drift\Rendering\Paint;
use Drift\Rendering\Path;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RenderSurface;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\CustomPaint;
use Drift\Widget\CustomPainter;
use Drift\Widget\Page;
use Drift\Dev\Golden\PageScenes;
use Drift\Dev\Inspect\Snapshot;
use Drift\Dev\Smoke\App\FakeDialog;
use Drift\Dev\Smoke\Golden\GoldenGaugePainter;
use Drift\Dev\Smoke\Golden\GoldenSparkPainter;
use App\Showcase\Stores\ButtonsStore;
use Drift\Async\Future;
use Drift\Widget\Column;
use Drift\Widget\Modal;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Snackbar;
use Drift\Widget\Text;
use Drift\Widget\Widget;

$W = 900;
$H = 640;

/**
 * RGBA 原始串 → PNG（color type 2 = RGB，filter 0 逐行；白底不透明 ⇒ 丢 alpha）。
 * ★ 与 `Drift\Dev\Preview\SvgSurface::toPng()` 同口径（2026-11-12 合并前的
 *   另一份实现已随 S4 收敛删除）；
 *   这里固化成基线落盘与失败 diff 图共用的一份。
 */
function goldenPng(string $rgba, int $w, int $h): string
{
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

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 6))
        . $chunk('IEND', '');
}

/**
 * 单类 Page 由 `host()` 进树；走 reducer 壳的例外页本身已是 Widget，原样返回。
 * 量具与真实应用共用这一个判定，避免"golden 绿了但应用建不出页"。
 */
function mountablePage(object $page): Widget
{
    return $page instanceof Page ? $page->host() : $page;
}

// ─────────────────── S2：像素差异区域 → 节点反查（DevTools 方向的"快照说谁"）───────────────────
//
// 背景：golden 失败消息现在能说"哪一块像素变了"（包围盒 + 横带），但那一步之后
// 还是要人眼打开 actual.png 找"是哪一个控件"。这一步把"像素告诉你哪里"接上
// "快照告诉你谁"——同样一份整页快照（`Snapshot`，走真管线、与 golden 同口径的
// 900×640 mount + Frame::run ⇒ 坐标零对齐），按差异框反查出"落在那一带的节点"。
//
// ★ 这是**提示**不是**判据**：差异框大时（如背景色变）几乎每个节点都命中，于是
//   排序优先"中心落在差异框内"再按"重叠面积"排，把最可能病灶的节点顶到前面。
// ★ 只给 page/ 场景用：canvas 原语场景没有元素树，反查无意义（会直接报"无节点"）。
// ★ 顺手把快照 JSON 落盘（`inspect.json`）——S3 的 HTML 报告直接读它做可折叠树。
/**
 * @param array{0:int,1:int,2:int,3:int} $bbox [minX,minY,maxX,maxY]（像素差异包围盒）
 * @param array<string, callable(): array> $pageWiring 构造器要参数的页的无头接线（见页面层）
 * @return string 多行文本：节点路径 + 控件类 + 盒类 + 绝对矩形（按相关度排序）
 */
function regionNodes(string $pageClass, int $W, int $H, array $bbox, string $sceneDir, array $pageWiring = []): string
{
    if (!class_exists($pageClass)) {
        return "（找不到页面类 {$pageClass}，跳过节点反查）\n";
    }

    // ★ 与主渲染**同一套接线**：要参数的页在这里同样要能构造，否则"红了"的时候
    //   归因路径自己先 Fatal —— 把"红了能指方向"变成"红了直接崩"（2026-11-06 变异抓到的）。
    $pos = strrpos($pageClass, '\\');
    $shortName = $pos === false ? $pageClass : substr($pageClass, $pos + 1);
    $wired = $pageWiring[$shortName] ?? null;
    $pageCtor = (new ReflectionClass($pageClass))->getConstructor();
    $required = $pageCtor === null ? 0 : $pageCtor->getNumberOfRequiredParameters();

    if ($required > 0 && $wired === null) {
        return "（{$shortName} 要构造参数且无接线，跳过节点反查）\n";
    }

    $page = mountablePage($required > 0 ? new $pageClass(...$wired()) : new $pageClass());
    $snapshot = Snapshot::ofPage($page, $W, $H);

    // 落盘：给 S3 HTML 报告消费（可折叠树 + 点节点看属性）。
    if ($sceneDir !== '') {
        $inspectDir = $sceneDir . '/inspect';
        if (!is_dir($inspectDir)) {
            mkdir($inspectDir, 0777, true);
        }
        file_put_contents(
            $inspectDir . '/actual.json',
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n",
        );
    }

    $elements = $snapshot['trees']['elements'] ?? [];
    if (!is_array($elements) || $elements === []) {
        return "（快照无元素树，跳过节点反查）\n";
    }

    [$bx0, $by0, $bx1, $by1] = array_map('floatval', $bbox);
    $hits = [];
    foreach ($elements as $node) {
        if (!is_array($node)) {
            continue;
        }
        $r = $node['r'] ?? [null, null, null, null];
        if ($r[0] === null) {
            continue; // 无盒节点（StatelessWidget 之类）不参与几何命中
        }
        $ax = (float) $r[0];
        $ay = (float) $r[1];
        $w = (float) $r[2];
        $h = (float) $r[3];

        // 与差异框求交
        $nx0 = max($ax, $bx0);
        $ny0 = max($ay, $by0);
        $nx1 = min($ax + $w, $bx1);
        $ny1 = min($ay + $h, $by1);
        if ($nx1 <= $nx0 || $ny1 <= $ny0) {
            continue;
        }
        $overlap = ($nx1 - $nx0) * ($ny1 - $ny0);
        // 中心是否落在差异框内：比单纯重叠面积更能指向"画偏/多画"的病灶节点
        $cx = $ax + $w / 2.0;
        $cy = $ay + $h / 2.0;
        $centerIn = ($cx >= $bx0 && $cx <= $bx1 && $cy >= $by0 && $cy <= $by1) ? 1 : 0;
        $hits[] = [$centerIn, $overlap, $node['p'], $node['w'], $node['b'], $ax, $ay, $w, $h];
    }

    if ($hits === []) {
        return "（差异区域无节点几何覆盖——可能是裁剪/阴影/无盒绘制，需看 inspect 树）\n";
    }

    // 排序：中心在框内者优先，其次重叠面积大者优先
    usort($hits, static function (array $a, array $b): int {
        if ($b[0] !== $a[0]) {
            return $b[0] <=> $a[0];
        }
        return $b[1] <=> $a[1];
    });

    $lines = ["（差异区域相关节点，最多 8 个；坐标=绝对像素，与像素图零对齐）\n"];
    foreach (array_slice($hits, 0, 8) as $hit) {
        $lines[] = sprintf(
            '    · %s %s → %s @(%.0f,%.0f %.0f×%.0f)',
            $hit[2], $hit[3], $hit[4], $hit[5], $hit[6], $hit[7], $hit[8],
        );
    }

    return implode('', array_map(static fn ($l) => $l . "\n", $lines));
}

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

// W1/L3-3：层不透明度（alpha 乘数栈）。四个子图分别钉住
// ① 单层 0.5 的矩形/路径/文字 ② 嵌套 0.5×0.5 = 0.25 ③ 层与路径裁剪叠加
// ④ 重叠子近似（重叠处更透 —— 与 Skia 真离屏的唯一差异，05 §5.6）
$scenes['opacity'] = static function () use ($W, $H): string {
    $c = new RasterCanvas($W, $H, Color::white(), null);

    // ① 单层 0.5：黑矩形 + 实心路径 + 描边路径都吃同一个乘数
    $c->pushLayer(0.5);
    $c->drawRect(new Rect(40.0, 40.0, 100.0, 80.0), Paint::fill(Color::black()));
    $c->drawPath(
        Path::create()->moveTo(200.0, 40.0)->lineTo(300.0, 40.0)->lineTo(250.0, 120.0)->close(),
        Paint::fill(Color::fromRgb(30, 90, 200))
    );
    $c->drawPath(
        Path::create()->moveTo(360.0, 40.0)->lineTo(460.0, 40.0)->lineTo(460.0, 120.0)->close(),
        Paint::stroke(Color::fromRgb(200, 90, 30), 10.0)
    );
    $c->popLayer();

    // ② 嵌套 0.5 × 0.5 = 0.25：比单层更淡（乘法语义）
    $c->pushLayer(0.5);
    $c->pushLayer(0.5);
    $c->drawRect(new Rect(40.0, 200.0, 100.0, 80.0), Paint::fill(Color::black()));
    $c->popLayer();
    $c->popLayer();

    // ③ 层 + 路径裁剪叠加：乘数在颜色层、掩码在像素层，两层独立
    $c->pushLayer(0.5);
    $ring = Path::create();
    for ($i = 0; $i < 64; $i++) {
        $ang = $i * 2.0 * M_PI / 64.0;
        $x = 300.0 + 60.0 * cos($ang);
        $y = 240.0 + 60.0 * sin($ang);
        if ($i === 0) {
            $ring->moveTo($x, $y);
        } else {
            $ring->lineTo($x, $y);
        }
    }
    $ring->close();
    $c->pushClipPath($ring);
    $c->drawRect(new Rect(240.0, 200.0, 120.0, 80.0), Paint::fill(Color::fromRgb(30, 160, 90)));
    $c->popClip();
    $c->popLayer();

    // ④ 重叠子近似：层内两个重叠矩形 ⇒ 重叠处双乘更透
    $c->pushLayer(0.5);
    $c->drawRect(new Rect(480.0, 200.0, 100.0, 80.0), Paint::fill(Color::black()));
    $c->drawRect(new Rect(540.0, 240.0, 100.0, 80.0), Paint::fill(Color::black()));
    $c->popLayer();

    // ⑤ 半透明色 × 层乘数叠加：颜色自己 128 alpha，再乘 0.5
    $c->pushLayer(0.5);
    $c->drawRect(
        new Rect(680.0, 200.0, 100.0, 80.0),
        Paint::fill(new Color(30, 90, 200, 128))
    );
    $c->popLayer();

    return $c->toRgba();
};

// ────────────── 页面场景：showcase 整页（自动发现） ──────────────
//
// 为什么要有这一层：真机截图验证慢、且靠人眼判"画得对不对"。但整页在
// `Frame → DisplayList → RasterCanvas` 这条路上是**纯 PHP 且确定性**的
// （实测 20 页同进程连渲两次 md5 全同）⇒ 真机那张图可以先在无头下逐字节比。
// 于是 T2 只剩"平台特有"（窗口 / DPI / 输入 / 模态循环），不再需要人眼看像素。
//
// ★ 噪声与改动面成正比：改了公共控件（Card 圆角之类）会一次破很多页基线。
//   这是 golden 的正常形态，但 **`--update` 之前必须看 diff 图** —— 无脑更新
//   等于把网撤了（这是 golden 唯一能失效的方式）。
// ★ 收录判据曾经是"构造器无必填参数" —— 那条判据等于"简单页收、复杂页不收"，
//   已于 2026-11-06 换成接线表（见下）。
// ── 金样接线表：构造器要参数的页 ──
//
// ★★ 为什么需要这张表（2026-11-06）：收录判据曾是"构造器无必填参数"，它实际筛出的
//   是"能零接线渲染的页"= **简单页**。于是 27 页有网、7 页没网，而没网的恰恰是
//   最复杂、最容易坏的（弹窗 / 下拉 / 提示条 / 异步加载 / 并发取数 / 错误边界）——
//   **保护强度与风险成反比**。而 gate 只报"27 场景 OK"，看起来是全覆盖。
//
// ★ 这些闭包全都**只在用户点按钮时才被调用**，首帧画不出它们 —— 页面能构造就能渲染。
//   所以"要 app 接线"不是不收录的理由，只是**没接线**：这里给无头假接线。
//   ★ 异步三页一律 `Future::pending()`（首帧稳定画 loading）：喂已结算的数据要求
//     "行数据的形状恰好对"，那是把页面的私有细节抄进量具 ⇒ 数据一改就假红。
// ★ 只有**需要依赖的 8 页**在表里；其余 26 页无参构造，自动免接线。
$pageWiring = [
    'AsyncTablePage' => static fn(): array => [
        static fn(int $page): Future => Future::pending(),
    ],
    'ButtonsPage' => static fn(): array => [new ButtonsStore()],
    'ComboBoxPage' => static fn(): array => [
        static fn(int $milliseconds, \Closure $callback): int => 0,
    ],
    'ConcurrentFetchPage' => static fn(): array => [
        static fn(int $page): Future => Future::pending(),
    ],
    'DialogPage' => static fn(): array => [
        new FakeDialog(),
        static function (bool $enabled): void {
        },
    ],
    'FeedbackPage' => static fn(): array => [
        static function (Modal $modal): void {
        },
    ],
    'LoadMorePage' => static fn(): array => [
        static fn(int $page): Future => Future::pending(),
    ],
    'SnackbarPage' => static fn(): array => [
        static function (Snackbar $snackbar): void {
        },
    ],
];

// ── 未接线的页必须在 $pageWiring 里，或在 $pageExempt 里**登记理由** ──
// ★ 这就是"不许静默丢"的落实：新增一页要参数却两处都没有 ⇒ 报错退出，而不是
//   一行 stderr 混在过去（"少了一张网"与"这张网本来就是空的"必须能分辨）。
$pageExempt = [];

$pageScenes = [];
$pageClasses = [];
$skippedPages = [];
/** @var list<string> 要接线却没接、也没登记理由的页 —— 见文件末尾的硬失败 */
$unwiredPages = [];
/** @var list<string> 冻结清单里的页在磁盘上找不到 —— 见文件末尾的硬失败 */
$missingPages = [];

// ★ 场景集合来自 L1 冻结清单，不再由 glob(apps/) 隐式决定（2026-11-13 S1）。
//   删一个 Page 文件 ⇒ 清单还在 ⇒ 硬失败，而不是场景数静默 −1、旧基线成孤儿。
foreach (PageScenes::names() as $pageName) {
    $pageFile = dirname(__DIR__) . '/apps/showcase/src/Pages/' . $pageName . '.php';
    $pageClass = 'App\\Showcase\\Pages\\' . $pageName;
    if (!is_file($pageFile) || !class_exists($pageClass)) {
        $missingPages[] = $pageName . (is_file($pageFile) ? '（文件在、类没找到）' : '（文件不在）');
        continue;
    }
    $pageCtor = (new ReflectionClass($pageClass))->getConstructor();
    $pageRequired = $pageCtor === null ? 0 : $pageCtor->getNumberOfRequiredParameters();

    $pageArgs = [];
    if ($pageRequired > 0) {
        $wired = $pageWiring[$pageName] ?? null;
        if ($wired === null) {
            // 要接线却没接、也没登记理由 ⇒ 收集起来最后报错退出（不静默丢）
            if (!isset($pageExempt[$pageName])) {
                $unwiredPages[] = $pageName;
            } else {
                $skippedPages[$pageName] = $pageExempt[$pageName];
            }
            continue;
        }
        $pageArgs = $wired();
    }

    $pageScenes['page/' . $pageName] = static function () use ($pageClass, $W, $H, $pageArgs): string {
        $context = new BuildContext(
            (float) $W,
            (float) $H,
            ThemeData::light(),
            new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ),
        );

        // 匿名类（不是命名类）：入口脚本里出现命名类会被 `namespace-mismatch`
        // 当成 PSR-4 类文件管 ⇒ 误报。
        $surface = new class($W, $H) implements RenderSurface {
            public ?RasterCanvas $last = null;
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

        /** @var Widget $page */
        $page = mountablePage(new $pageClass(...$pageArgs));
        $element = $page->createElement();
        $element->mount(null, $context);
        (new Frame($surface))->run(
            $element,
            BoxConstraints::tight(new Size((float) $W, (float) $H)),
            0.0,
        );

        return $surface->last === null ? '' : $surface->last->toRgba();
    };
    // 失败时要按场景名找回页面类（把像素差异翻译成节点，见 regionNodes()）
    $pageClasses['page/' . $pageName] = $pageClass;
}

if ($skippedPages !== []) {
    $lines = [];
    foreach ($skippedPages as $name => $reason) {
        $lines[] = $name . '（' . $reason . '）';
    }
    fwrite(STDERR, '（登记不收 ' . count($skippedPages) . ' 页：' . implode('、', $lines) . "）\n");
}

// ★★ 硬失败（2026-11-06）：要构造参数、没进接线表、也没登记理由 ⇒ 退出码 2。
//   "少了一张网"与"这张网本来就是空的"必须能分辨 —— 一行 stderr 混在输出里不算
//   分辨（它不进 gate 判定，等于静默）。新增一页带参数的页面就会撞上这一条。
if ($unwiredPages !== []) {
    fwrite(STDERR, '✗ 这些页要构造参数，但既没进 $pageWiring 也没进 $pageExempt：'
        . implode('/', $unwiredPages) . "\n"
        . "  ⇒ 它们**没有整页金样**。要么在接线表里给无头假接线，要么登记不收的理由。\n");
    exit(2);
}

// ★ S1：清单里的页找不到 ⇒ 硬失败。判据资产被删不许表现成「少跑一页」（照绿）。
if ($missingPages !== []) {
    fwrite(STDERR, '✗ 冻结清单里的页在磁盘上找不到：' . implode('、', $missingPages) . "\n"
        . "  ⇒ 要么补回页面，要么显式改 tools/Golden/PageScenes.php 并同步基线。\n");
    exit(2);
}

// ─────────────────── 模式：默认 md5 文本 / --update / --check / --png ───────────────────
//
// 默认（旧行为）：输出 `name: len md5`，人手动前后 diff。
// --update：把每个场景的 RGBA 基线写进 golden/<scene>/（expected.rgba.gz 供比对、
//           expected.png 供人看）。
// --check ：渲染并与基线**逐字节**比；不一致 ⇒ 写 actual/ 与 diff/（可看的 PNG），
//           退出码 1。RasterCanvas 是确定性的（纯 PHP、无抗锯齿抖动）⇒ 逐字节比成立。
// --png   ：从 `.rgba.gz` **还原** `expected.png`（不渲染、不比对）。
//
// ★ 为什么 `.png` **不入库**、只在本地生成：
//   它是 `.rgba.gz` 的**纯派生物**（`goldenPng()` 确定性，任何时候都能还原），
//   唯一用途是人眼看。入库等于把编译产物入库，还引入"两个文件不同步"的隐患
//   （没有任何机制能发现有人只重生成了一个）。2026-10-27 实测：png 748KB +
//   gz 720KB ⇒ 只留 gz 省一半，且 git 对 png 无法 delta（每次合法 UI 改动重刷
//   20 页 = 历史再压一份 1.5MB，删不掉）。
//   代价是"看基线图"要多跑一条 `--png`，换来基线目录体积减半 + 单一真相。
$mode = 'text';
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--update') {
        $mode = 'update';
    } elseif ($a === '--check') {
        $mode = 'check';
    } elseif ($a === '--png') {
        $mode = 'png';
    }
}
/** `--json`：`--check` 的判据结果吐成机器可读文档（stdout 上只有它） */
$json = \in_array('--json', $argv, true);

// 场景选择：默认两层都跑（canvas 原语 + 整页）；`--canvas` / `--pages` 只跑一层。
// ★ 两层分开的理由是**代价差一个量级**（canvas 1.6s / 整页 ~4s）：改注释时
//   不该等整页；而"改了公共控件"时必须两层都跑。
$onlyPages = in_array('--pages', $argv, true);
$onlyCanvas = in_array('--canvas', $argv, true);
if ($onlyPages && !$onlyCanvas) {
    $scenes = $pageScenes;
} elseif (!$onlyPages && !$onlyCanvas) {
    $scenes = $scenes + $pageScenes;
}

$dir = __DIR__ . '/../golden';

// ── 模式：从基线还原 PNG（不渲染、不比对）──
// ★ 走场景表而不是 glob 目录：场景表知道每页的 `$W`/`$H`，而 `.rgba.gz` 里
//   只有像素、没有尺寸。基线长度对不上就跳过并报出来（尺寸变了 = 基线该重生成）。
if ($mode === 'png') {
    $restored = 0;
    $skipped = 0;
    foreach ($scenes as $name => $make) {
        $sceneDir = $dir . '/' . $name;
        $expFile = $sceneDir . '/expected.rgba.gz';
        if (!is_file($expFile)) {
            echo "skip {$name}：无基线\n";
            $skipped++;
            continue;
        }
        $rgba = gzdecode((string) file_get_contents($expFile));
        if ($rgba === false || strlen($rgba) !== $W * $H * 4) {
            echo "skip {$name}：基线尺寸不是 {$W}×{$H}\n";
            $skipped++;
            continue;
        }
        file_put_contents($sceneDir . '/expected.png', goldenPng($rgba, $W, $H));
        $restored++;
    }
    echo "PNG 还原 {$restored} 张" . ($skipped > 0 ? "（跳过 {$skipped}）" : '') . "\n";
    exit(0);
}

if ($mode === 'update') {
    foreach ($scenes as $name => $make) {
        $rgba = $make();
        $sceneDir = $dir . '/' . $name;
        if (!is_dir($sceneDir) && !mkdir($sceneDir, 0777, true) && !is_dir($sceneDir)) {
            fwrite(STDERR, "建不了 {$sceneDir}\n");
            exit(2);
        }
        file_put_contents($sceneDir . '/expected.rgba.gz', gzencode($rgba, 9));
        file_put_contents($sceneDir . '/expected.png', goldenPng($rgba, $W, $H));
        echo "updated {$name}\n";
    }
    exit(0);
}

if ($mode === 'check') {
    // ★ S3 manifest：测量层与呈现层之间的契约。
    //   呈现层 `golden-report.php` 读它 + 现场产物生成自包含 HTML；S4 也可复用。
    //
    // ★ 2026-11-03：`$items` 是**唯一账本**，manifest 从它派生（原来是"echo 一遍 +
    //   另行 push 一个数组"，两处各说一次"这条过没过"）。manifest 的字段形状不变
    //   （`name/kind/pass` + 失败详情），因为呈现层按它消费。
    /** @var list<CheckItem> $items */
    $items = [];
    $buildDir = dirname(__DIR__) . '/_build';

    // ★ R1 反向扫描：只判「场景有没有基线」时，删一页 = 场景数 −1、旧基线成孤儿、
    //   gate 静默全绿。这里补「基线有、场景无」，与 `SceneSet::compare()` 双向闭合。
    //   `--canvas` 的基线不是一级目录一一对应，故只扫整页层。
    if (!$onlyCanvas) {
        $declaredPages = [];
        foreach (array_keys($pageScenes) as $sceneName) {
            $declaredPages[] = substr($sceneName, strlen('page/'));
        }
        $baselinePages = [];
        foreach (glob($dir . '/page/*', GLOB_ONLYDIR) ?: [] as $baselineDir) {
            $baselinePages[] = basename(str_replace('\\', '/', $baselineDir));
        }
        foreach (SceneSet::compare($declaredPages, $baselinePages)['orphan'] as $orphan) {
            $items[] = $item = new CheckItem(
                'page/' . $orphan,
                false,
                '孤儿基线：golden/page/' . $orphan . ' 有基线但没有场景（删页或改名遗留）',
                ['kind' => 'page', 'reason' => 'orphan-baseline'],
            );
            CheckReport::emitLine($item, $json);
        }
    }

    foreach ($scenes as $name => $make) {
        $kind = str_starts_with($name, 'page/') ? 'page' : 'canvas';
        $rgba = $make();
        $sceneDir = $dir . '/' . $name;
        $expFile = $sceneDir . '/expected.rgba.gz';
        if (!is_file($expFile)) {
            $items[] = $item = new CheckItem($name, false, '无基线（先跑 php tools/golden-raster.php --update）', ['kind' => $kind, 'reason' => 'no-baseline']);
            CheckReport::emitLine($item, $json);
            continue;
        }

        $expected = gzdecode((string) file_get_contents($expFile));
        if ($expected === $rgba) {
            $items[] = $item = new CheckItem($name, true, '', ['kind' => $kind]);
            CheckReport::emitLine($item, $json);
            continue;
        }

        $diffPixels = 0;
        $maxDelta = 0;
        // ★ 变化区域的包围盒 + 逐行命中数：FAIL 行直接说"哪一块变了"。
        //   省掉"打开 actual.png 用眼睛找"那一步 —— 那一步正是 golden 最烦的地方
        //   （也最容易判错：人眼对"少了一条 1px 线"几乎无能为力，对坐标却很敏感）。
        $minX = $W;
        $minY = $H;
        $maxX = -1;
        $maxY = -1;
        $rowHits = array_fill(0, $H, 0);
        $len = min(strlen($expected), strlen($rgba));
        for ($i = 0; $i + 3 < $len; $i += 4) {
            $d = 0;
            for ($k = 0; $k < 4; $k++) {
                $delta = abs(ord($expected[$i + $k]) - ord($rgba[$i + $k]));
                if ($delta > $d) {
                    $d = $delta;
                }
            }
            if ($d === 0) {
                continue;
            }
            $diffPixels++;
            if ($d > $maxDelta) {
                $maxDelta = $d;
            }
            $pixel = intdiv($i, 4);
            $x = $pixel % $W;
            $y = intdiv($pixel, $W);
            if ($x < $minX) {
                $minX = $x;
            }
            if ($x > $maxX) {
                $maxX = $x;
            }
            if ($y < $minY) {
                $minY = $y;
            }
            if ($y > $maxY) {
                $maxY = $y;
            }
            $rowHits[$y]++;
        }

        if ($maxX < 0) {
            // 逐像素全同但整体不等 ⇒ 只可能是长度不同（尺寸变了）
            $items[] = $item = new CheckItem(
                $name,
                false,
                '长度不同（基线 ' . strlen($expected) . ' / 实际 ' . strlen($rgba) . '）',
                ['kind' => $kind, 'reason' => 'size'],
            );
            CheckReport::emitLine($item, $json);
            continue;
        }

        // 行命中的连续段，按命中像素数取前三 —— "变在哪几条横带"比包围盒更指向病灶
        $bands = [];
        $bandStart = -1;
        $bandCount = 0;
        for ($y = 0; $y <= $H; $y++) {
            if ($y < $H && $rowHits[$y] > 0) {
                if ($bandStart < 0) {
                    $bandStart = $y;
                    $bandCount = 0;
                }
                $bandCount += $rowHits[$y];
            } elseif ($bandStart >= 0) {
                $bands[] = [$bandStart, $y - 1, $bandCount];
                $bandStart = -1;
            }
        }
        usort($bands, static function (array $a, array $b): int {
            return $b[2] <=> $a[2];
        });
        $bandText = '';
        foreach (array_slice($bands, 0, 3) as $band) {
            $bandText .= " y={$band[0]}..{$band[1]}({$band[2]}px)";
        }

        $pct = $W * $H > 0 ? 100.0 * $diffPixels / ($W * $H) : 0.0;

        $actualDir = $sceneDir . '/actual';
        $diffDir = $sceneDir . '/diff';
        if (!is_dir($actualDir)) {
            mkdir($actualDir, 0777, true);
        }
        if (!is_dir($diffDir)) {
            mkdir($diffDir, 0777, true);
        }
        file_put_contents($actualDir . '/actual.rgba.gz', gzencode($rgba, 9));
        file_put_contents($actualDir . '/actual.png', goldenPng($rgba, $W, $H));

        // diff 图：不同像素标红、相同标灰（一眼看出哪里变了）
        $diff = '';
        for ($y = 0; $y < $H; $y++) {
            $base = $y * $W * 4;
            for ($x = 0; $x < $W; $x++) {
                $o = $base + $x * 4;
                $same = substr($expected, $o, 4) === substr($rgba, $o, 4);
                $diff .= $same ? "\x20\x20\x20\xFF" : "\xFF\x00\x00\xFF";
            }
        }
        file_put_contents($diffDir . '/diff.png', goldenPng($diff, $W, $H));

        $msg = "FAIL {$name}：{$diffPixels} 像素差（max Δ={$maxDelta}，占 "
            . number_format($pct, 2) . '%），区域 x=' . $minX . '..' . $maxX
            . ' y=' . $minY . '..' . $maxY . '；条带' . $bandText
            . "；见 {$sceneDir}/actual/ 与 diff/";

        // S2：整页场景失败时，把"像素条带"翻译成"这一带是哪些节点"（像素说哪里、快照说谁）
        if (str_starts_with($name, 'page/') && isset($pageClasses[$name])) {
            $msg .= "\n" . regionNodes($pageClasses[$name], $W, $H, [$minX, $minY, $maxX, $maxY], $sceneDir, $pageWiring);
        }

        // ★ 记录 S3 现场产物路径（相对 golden 根），呈现层据此拼 HTML。
        $artifacts = [
            'expectedGz' => $name . '/expected.rgba.gz',
            'actualPng' => $name . '/actual/actual.png',
            'diffPng' => $name . '/diff/diff.png',
        ];
        if (str_starts_with($name, 'page/') && isset($pageClasses[$name])) {
            $artifacts['inspectJson'] = $name . '/inspect/actual.json';
        }

        $items[] = $item = new CheckItem($name, false, $msg, [
            'kind' => $kind,
            'diffPixels' => $diffPixels,
            'maxDelta' => $maxDelta,
            'pct' => $pct,
            'bbox' => [$minX, $minY, $maxX, $maxY],
            'bands' => array_slice($bands, 0, 3),
            'artifacts' => $artifacts,
        ]);
        CheckReport::emitLine($item, $json);
    }

    // 落 manifest（测量层 → 呈现层的契约）。失败与否都写，便于"全绿时也有状态表"。
    // ★ 从 `$items` 派生：`name`/`pass` 由 CheckItem 供，其余全是 `data` 里的字段。
    $manifest = [];
    foreach ($items as $item) {
        $manifest[] = ['name' => $item->id, 'kind' => $item->data['kind'] ?? null, 'pass' => $item->ok] + $item->data;
    }

    if (!is_dir($buildDir) && !mkdir($buildDir, 0777, true) && !is_dir($buildDir)) {
        fwrite(STDERR, "建不了 {$buildDir}\n");
    } else {
        $manifestMeta = [
            'schema' => 1,
            'w' => $W,
            'h' => $H,
            'goldenRoot' => $dir,
            'scenes' => $manifest,
        ];
        file_put_contents($buildDir . '/golden-manifest.json', json_encode($manifestMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    }

    // ★ `$echoText = false`：上面每判一条已经即时印过了（60 场景跑 6s，等跑完才印
    //   等于 6 秒无输出）。这里只补收尾那一行，格式仍出自 `CheckReport`。
    $tool = $onlyPages ? 'golden --pages' : ($onlyCanvas ? 'golden --canvas' : 'golden');
    (new CheckReport($tool, $items, sprintf('GOLDEN OK（%d 场景）', count($items))))->emit($json, false);
}

// 默认：旧行为（md5 文本，人手动前后 diff）
$out = [];
foreach ($scenes as $name => $make) {
    $rgba = $make();
    $out[] = sprintf('%s: len=%d md5=%s', $name, strlen($rgba), md5($rgba));
}
// ★ 只把**不以 `-` 开头**的参数当输出路径：否则打错的选项（`--help`、`--chek`）
//   会静默生成一个同名垃圾文件 —— 命令不报错、目录里多个 `--help`，
//   而"看起来成功"比报错更难查。（2026-11-04 实测踩到）
$outPath = (isset($argv[1]) && $argv[1] !== '' && \strncmp($argv[1], '-', 1) !== 0)
    ? $argv[1]
    : 'php://stdout';

// ★ 二选一，不再"写文件 + 同时 echo"：`php://stdout` 本身就会被 file_put_contents 写一遍，
//   再 echo 一遍 ⇒ 每条场景在终端出现两次（2026-11-06 发现）。人肉眼 diff 时行数翻倍、
//   看着像"两套场景"，而命令不报错 —— 又是"看起来成功"。
if ($outPath === 'php://stdout') {
    echo implode("\n", $out), "\n";
} else {
    file_put_contents($outPath, implode("\n", $out) . "\n");
    echo '（' . count($out) . " 场景写入 {$outPath}）\n";
}
