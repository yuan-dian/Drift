<?php
declare(strict_types=1);

/**
 * drift-smoke —— M0 的出口判据（§19）：骨架能装载、错误出口三级都真的生效，
 * 且**已交付的每一层**都有真断言（不是"能 new 出来"就算过）。
 *
 * 用法：
 *   php tools/smoke.php
 *
 * ★ 中止路径（Fatal / 封存后写入）会真的 `exit(3)`，所以它们跑在**子进程**里，
 *   断言的是"退出码 + 日志内容 + 错误框被调到" —— 而不是把 abort 改成可注入的
 *   测试钩子（那会给生产 API 添一个只为测试存在的开关，正是 A15）。
 */

use Drift\Dev\Smoke\BoxProbe;
use Drift\Dev\Smoke\SealProbe;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Paint;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Text\FontFamily;
use Drift\Text\FontSlant;
use Drift\Text\FontWeight;
use Drift\Text\TextAlign;
use Drift\Text\TextLayout;
use Drift\Text\TextStyle;
use Drift\Widget\Key;
use Drift\Widget\Widget;

require __DIR__ . '/autoload.php';

$root = dirname(__DIR__);
$log = $root . '/Errors.log';
$boxMarker = $root . '/tools/smoke-box.tmp';

// ── 子进程模式：跑一个"必然中止"的用例 ──
$caseArg = $argv[1] ?? '';
if (str_starts_with($caseArg, '--case=')) {
    $case = substr($caseArg, 7);
    Errors::setLogPath($log);
    Errors::setDebug(true);

    if ($case === 'fatal') {
        $GLOBALS['DRIFT_SMOKE_BOX'] = $argv[2];
        Errors::installFatalBox(static function (string $title, string $body): void {
            file_put_contents($GLOBALS['DRIFT_SMOKE_BOX'], $title . '|' . $body);
        });
        // 先压两条 Diagnostic —— 中止序列 ① 的"环形缓冲"段才测的是真路径
        Errors::report('冒烟：前置诊断 A', Errors::DIAGNOSTIC);
        Errors::report('冒烟：前置诊断 B', Errors::DIAGNOSTIC);
        Errors::report('冒烟：走 Fatal 路径');
    }

    if ($case === 'seal') {
        $widget = new SealProbe();
        $widget->seal();
        $widget->touch(); // 封存后写入 ⇒ 必须响亮失败
    }

    if ($case === 'key') {
        $widget = new SealProbe();
        $widget->seal();
        $widget->key(7); // 封存后给身份 ⇒ 必须响亮失败
    }

    if ($case === 'box') {
        $widget = new BoxProbe();
        $widget->seal();
        $widget->background(Color::white()); // 封存后改样式 ⇒ 必须响亮失败
    }

    if ($case === 'constraints') {
        new BoxConstraints(20.0, 10.0); // min > max ⇒ 必须响亮失败（§13.1 C6）
    }

    if ($case === 'color') {
        Color::fromRgb(300, 0, 0); // 分量越界 ⇒ 不静默截断
    }

    if ($case === 'w8') {
        $list = new DisplayList();
        $list->record(DisplayList::OP_SAVE); // 只有 save 没有 restore
        $list->assertBalanced();             // W8 ⇒ 必须响亮失败
    }

    if ($case === 'font') {
        // 显式家族名找不到任何 face ⇒ 连行高都拿不到 ⇒ 必须响亮失败（§8.3 血账 1）
        $manager = DeterministicFontManager::standard();
        (new DeterministicMetricsProvider($manager->collection(), new DeterministicShaper()))
            ->layout('x', TextStyle::of(14.0)->withFamily(FontFamily::named('NoSuchFont')), INF, Scale::of(1.0));
    }

    if ($case === 'fontfile') {
        // M0 只带内置点阵字模：解析字体文件这条路没实现 ⇒ 必须响亮失败而不是静默忽略
        DeterministicFontManager::standard()->registerFile('C:/nope.ttf');
    }

    exit(0); // 走到这里说明"该中止的没中止"
}

// ── 断言与工具 ──

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        printf("  ok    %s\n", $name);
        return;
    }
    $failures++;
    printf("  FAIL  %s%s\n", $name, $detail === '' ? '' : '  —— ' . $detail);
}

/** 清掉日志与状态，让每个用例互不污染 */
function fresh(string $log): void
{
    Errors::reset();
    Errors::setLogPath($log);
    if (is_file($log)) {
        unlink($log);
    }
}

/** @return array{0: int, 1: string} 退出码 + 输出 */
function runCase(string $script, string $case, ?string $marker): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --case=' . $case;
    if ($marker !== null) {
        $command .= ' ' . escapeshellarg($marker);
    }
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
}

function logText(string $log): string
{
    return is_file($log) ? (string) file_get_contents($log) : '';
}

/** 非背景像素数：用来断言"文字真的画上了 / 真的被挡住了" */
function inkCount(RasterCanvas $canvas, Color $background = null): int
{
    $background ??= Color::white();
    $ink = 0;
    for ($y = 0; $y < $canvas->height(); $y++) {
        for ($x = 0; $x < $canvas->width(); $x++) {
            if (!$canvas->pixelAt($x, $y)->equals($background)) {
                $ink++;
            }
        }
    }
    return $ink;
}

echo "M0 冒烟\n";

// ① PSR-4 装载：前缀表驱动，不生成 vendor/
check('autoload · Drift\\Errors', class_exists(Errors::class));
check('autoload · Drift\\Widget\\Widget', class_exists(Widget::class));
check('autoload · Drift\\Dev\\ 前缀优先于 Drift\\', class_exists(SealProbe::class));

// ② Widget 的封存状态
fresh($log);
$widget = new SealProbe();
check('Widget · 初始未封存', !$widget->isSealed());
$widget->seal();
check('Widget · seal() 后已封存', $widget->isSealed());
$widget->seal();
check('Widget · seal() 幂等', $widget->isSealed());

// ③ Diagnostic：进环形缓冲、超容量丢最旧、顺序保持
fresh($log);
for ($i = 0; $i < 105; $i++) {
    Errors::report('d' . $i, Errors::DIAGNOSTIC);
}
$ring = Errors::recentDiagnostics();
check('Errors · Diagnostic 环形缓冲容量 = 100', count($ring) === 100, '实际 ' . count($ring));
check(
    'Errors · 丢最旧、留最新、顺序正序',
    ($ring[0] ?? '') === 'd5' && ($ring[99] ?? '') === 'd104',
    ($ring[0] ?? '-') . ' … ' . ($ring[99] ?? '-')
);
check('Errors · Diagnostic 不落盘', !is_file($log));

// ④ Recoverable 在 release 下降级继续（不中止），但留痕
fresh($log);
Errors::setDebug(false);
Errors::report('降级：不该中止', Errors::RECOVERABLE);
check('Errors · Recoverable + release 不中止', true);
check('Errors · Recoverable + release 留痕', str_contains(logText($log), '降级：不该中止'));
Errors::setDebug(true);

// ⑤ Fatal：写日志（错误 + 栈 + 环形缓冲）→ 弹错误框 → exit(3)
fresh($log);
if (is_file($boxMarker)) {
    unlink($boxMarker);
}
[$code, $output] = runCase(__FILE__, 'fatal', $boxMarker);
check('Errors · Fatal 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
$text = logText($log);
check('Errors · Fatal 写了 Errors.log', str_contains($text, '冒烟：走 Fatal 路径'));
check('Errors · Fatal 日志带栈', str_contains($text, '#0'));
check('Errors · Fatal 日志带环形缓冲段', str_contains($text, '最近 2 条 Diagnostic'));
check('Errors · Fatal 环形缓冲含 Fatal 之前的现场', str_contains($text, '冒烟：前置诊断 B'));
check(
    'Errors · Fatal 调到了原生错误框',
    is_file($boxMarker) && str_starts_with((string) file_get_contents($boxMarker), 'Drift FATAL|')
);
if (is_file($boxMarker)) {
    unlink($boxMarker);
}

// ⑥ 封存后写入：Fatal（A11 的守卫）
fresh($log);
[$code, $output] = runCase(__FILE__, 'seal', null);
check('Widget · 封存后写入退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Widget · 封存后写入写了 Errors.log', str_contains(logText($log), 'Widget 已封存'));

// ⑦ Geometry：唯一的量化函数 + 无界用 INF 不用 0
fresh($log);
check('Geometry · dpr = dpi / 96', Scale::fromDpi(96.0)->isIdentity() && Scale::fromDpi(120.0)->dpr === 1.25);
check('Geometry · quantize = round(logical × dpr)', Scale::of(1.25)->quantize(10.0) === 13.0);

$scale = Scale::of(1.5);
$leftBox = $scale->quantizeRect(new Rect(0.0, 0.0, 3.0, 3.0));  // 0 .. 4.5
$rightBox = $scale->quantizeRect(new Rect(3.0, 0.0, 3.0, 3.0)); // 4.5 .. 9
check(
    'Geometry · 相邻矩形共享同一条像素边（不错开半像素）',
    $leftBox->right() === $rightBox->left,
    $leftBox->right() . ' vs ' . $rightBox->left
);

$unbounded = BoxConstraints::unbounded();
check('Geometry · 无界 = INF（不是 0）', $unbounded->maxWidth === INF && $unbounded->hasInfiniteWidth());
check('Geometry · 0 是合法的紧尺寸', BoxConstraints::tight(Size::zero())->isTight());

$bounded = new BoxConstraints(10.0, 20.0, 0.0, INF);
check('Geometry · constrain 夹进区间', $bounded->constrain(new Size(5.0, 100.0))->equals(new Size(10.0, 100.0)));
check('Geometry · tighten 收成紧约束', $bounded->tighten(new Size(50.0, 7.0))->equals(new BoxConstraints(20.0, 20.0, 7.0, 7.0)));
check('Geometry · loosen 放开下界保留上界', $bounded->loosen()->equals(new BoxConstraints(0.0, 20.0, 0.0, INF)));

check('Geometry · Rect 右边界现算不缓存', Rect::fromLtrb(1.0, 2.0, 4.0, 6.0)->right() === 4.0);
check('Geometry · EdgeInsets 归几何面', EdgeInsets::all(8.0)->horizontal() === 16.0 && EdgeInsets::symmetric(4.0, 2.0)->vertical() === 4.0);

// ⑧ C6：约束违反 ⇒ 响亮失败
fresh($log);
[$code, $output] = runCase(__FILE__, 'constraints', null);
check('Geometry · 约束违反退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Geometry · 约束违反写了 Errors.log', str_contains(logText($log), '违反约束'));

// ⑨ Rendering：录制 → 回放，同一份 DisplayList 必得同一张位图（G3）
fresh($log);
$red = Color::fromRgb(255, 0, 0);
$list = new DisplayList();
$recorder = new RecordingCanvas($list);
$recorder->save();
$recorder->translate(10.0, 20.0);
$recorder->pushClip(new Rect(0.0, 0.0, 5.0, 5.0));
$recorder->drawRect(new Rect(0.0, 0.0, 5.0, 5.0), Paint::fill($red));
$recorder->popClip();
$recorder->restore();

check('Rendering · 录制命令数', $list->count() === 6, (string) $list->count());
check('Rendering · W8 栈平衡（save/clip 都归零）', $list->saveDepth() === 0 && $list->clipDepth() === 0);

$raster = new RasterCanvas(32, 32, Color::white());
$list->replay($raster);
check('Rendering · 回放落在平移后的位置', $raster->pixelAt(12, 22)->equals($red));
check('Rendering · 平移外的区域未被污染', $raster->pixelAt(2, 2)->equals(Color::white()));

// 裁剪真的生效：画一个大矩形，只该出现裁剪区内那一块
$clipped = new RasterCanvas(32, 32, Color::white());
$clipList = new DisplayList();
$clipCanvas = new RecordingCanvas($clipList);
$clipCanvas->pushClip(new Rect(0.0, 0.0, 4.0, 4.0));
$clipCanvas->drawRect(new Rect(0.0, 0.0, 16.0, 16.0), Paint::fill($red));
$clipCanvas->popClip();
$clipList->replay($clipped);
check('Rendering · 裁剪区内被绘制', $clipped->pixelAt(1, 1)->equals($red));
check('Rendering · 裁剪区外被挡住', $clipped->pixelAt(6, 6)->equals(Color::white()));

// 圆角：角上那一个像素必须落在圆弧之外
$rounded = new RasterCanvas(32, 32, Color::white());
$roundList = new DisplayList();
(new RecordingCanvas($roundList))->drawRect(new Rect(0.0, 0.0, 16.0, 16.0), Paint::fill($red), 6.0);
$roundList->replay($rounded);
check('Rendering · 圆角外露背景', $rounded->pixelAt(0, 0)->equals(Color::white()));
check('Rendering · 圆角中心仍是实心', $rounded->pixelAt(8, 8)->equals($red));

// 描边：中间该是空的
$stroked = new RasterCanvas(32, 32, Color::white());
$strokeList = new DisplayList();
(new RecordingCanvas($strokeList))->drawRect(new Rect(2.0, 2.0, 16.0, 16.0), Paint::stroke($red, 2.0));
$strokeList->replay($stroked);
check('Rendering · 描边落在边上', $stroked->pixelAt(3, 3)->equals($red));
check('Rendering · 描边内部是空的', $stroked->pixelAt(10, 10)->equals(Color::white()));

// 缓冲复用：reset 之后槽位保留、可以重新录
$list->reset();
check('Rendering · reset 只归零长度', $list->count() === 0 && $list->saveDepth() === 0);
$list->record(DisplayList::OP_DRAW_RECT, new Rect(0.0, 0.0, 1.0, 1.0), Paint::fill($red), 0.0);
check('Rendering · reset 后可继续录制', $list->count() === 1);

// 值对象
check('Rendering · Color::fromHex 支持 #RGB', Color::fromHex('#f00')->equals($red));
check('Rendering · Color 往返 ARGB', Color::fromArgb(Color::fromRgba(1, 2, 3, 4)->toArgb())->equals(Color::fromRgba(1, 2, 3, 4)));
check('Rendering · BoxStyle::none 什么也不画', BoxStyle::none()->isNone());
check(
    'Rendering · BoxStyle 逐字段 with- 不改其它字段',
    BoxStyle::none()->withBackground($red)->withRadius(8.0)->equals(new BoxStyle($red, null, 0.0, 8.0))
);
check('Rendering · Paint 风格可判', Paint::stroke($red, 2.0)->isStroke() && !Paint::fill($red)->isStroke());

// ⑩ 响亮失败：颜色越界 / W8 未闭合
fresh($log);
[$code, $output] = runCase(__FILE__, 'color', null);
check('Rendering · Color 越界退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);

fresh($log);
[$code, $output] = runCase(__FILE__, 'w8', null);
check('Rendering · W8 未闭合退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Rendering · W8 未闭合写了 Errors.log', str_contains(logText($log), 'W8'));

// ⑪ Text：六层栈 + 确定性参考实现（§8 / §14.2）
fresh($log);
$fontManager = DeterministicFontManager::standard();
$registry = $fontManager->collection();
$textMetrics = new DeterministicMetricsProvider($registry, new DeterministicShaper());
$unit = Scale::of(1.0);
$body = TextStyle::of(14.0);
$latinFace = $fontManager->faces()[0];
$cjkFace = $fontManager->faces()[1];

check('Text · FontManager 在任何绘制前就绪（§8.3 血账 1）', $fontManager->isReady() && count($fontManager->faces()) === 2);
check(
    'Text · 两个 face 的归一化度量逐字段相同（行高不随字形漂移）',
    $latinFace->metrics()->equals($cjkFace->metrics())
);

// 回退链：按能力选 face，链上都不含才退兜底
check('Text · 逻辑家族的回退链 = 拉丁 + CJK（能力顺序）', count($registry->resolve(FontFamily::sans(), FontWeight::Regular, FontSlant::Normal)) === 2);
check('Text · 未注册的逻辑家族退默认链（落地名归平台配置）', count($registry->resolve(FontFamily::serif(), FontWeight::Regular, FontSlant::Normal)) === 2);
check('Text · 显式家族名找不到就是没有', $registry->resolve(FontFamily::named('NoSuch'), FontWeight::Regular, FontSlant::Normal) === []);
check('Text · 回退链按能力选 face：拉丁', $registry->faceFor(0x41, FontFamily::sans(), FontWeight::Regular, FontSlant::Normal) === $latinFace);
check('Text · 回退链按能力选 face：CJK', $registry->faceFor(0x4E2D, FontFamily::sans(), FontWeight::Regular, FontSlant::Normal) === $cjkFace);
$notdef = $registry->faceFor(0x1F600, FontFamily::sans(), FontWeight::Regular, FontSlant::Normal);
check('Text · 链上都不含 ⇒ 兜底 face（布局不塌、但不画方框）', !$notdef->hasGlyph(0x1F600) && $notdef->glyphAdvance(0x1F600) === 1.0 && $notdef->rasterize(0x1F600, 16.0) === null);

// 行高（§8.2 坑 3）
$hello = $textMetrics->layout('Hello', $body, INF, $unit);
check('Text · 行高 = (lineGap + (ascent+descent) × lineHeight) × size', abs($hello->lines[0]->height - 16.8) < 1e-9, (string) $hello->lines[0]->height);
check('Text · INF 不限宽 ⇒ 不折行', $hello->lineCount() === 1);

// 断行（§13.1 C5 的溢出契约）
$latinWrapped = $textMetrics->layout('Hello Drift', $body, 40.0, $unit);
check(
    'Text · 拉丁整词不被劈开（放不下就溢出，不静默折断）',
    $latinWrapped->lineCount() === 2
        && substr('Hello Drift', $latinWrapped->lines[0]->textStart, $latinWrapped->lines[0]->textLength) === 'Hello',
    (string) $latinWrapped->lineCount()
);
check('Text · 行尾空白被修剪（不参与宽度）', $latinWrapped->lines[0]->width === 55.0, (string) $latinWrapped->lines[0]->width);
check('Text · CJK 按字边界断行', $textMetrics->layout('中文断行测试', $body, 30.0, $unit)->lineCount() === 3);

// 禁则（§8.3 对策 ②）：闭标点不落行首
$rule = '中文，测试。';
$ruleWrapped = $textMetrics->layout($rule, $body, 34.0, $unit);
$lineHeads = [];
foreach ($ruleWrapped->lines as $line) {
    $lineHeads[] = substr($rule, $line->textStart, 3);
}
check(
    'Text · 禁则：闭标点不落行首',
    !in_array('，', $lineHeads, true) && !in_array('。', $lineHeads, true),
    implode(' / ', $lineHeads)
);

// 空文本 / 硬换行 / 省略号
$empty = $textMetrics->layout('', $body, INF, $unit);
check('Text · 空文本仍有一行高（不塌成 0）', $empty->lineCount() === 1 && $empty->height > 0.0);
check('Text · 硬换行按 \\n 断', $textMetrics->layout("a\nb\nc", $body, INF, $unit)->lineCount() === 3);

$clamped = $textMetrics->layout('Hello Drift Framework', $body->withMaxLines(2)->withEllipsize(true), 60.0, $unit);
$lastLine = $clamped->lines[$clamped->lineCount() - 1];
$lastRun = $lastLine->runs[count($lastLine->runs) - 1];
$lastGlyph = $lastRun->glyphs[count($lastRun->glyphs) - 1];
check('Text · 省略号：行数被 maxLines 夹住', $clamped->lineCount() === 2, (string) $clamped->lineCount());
check('Text · 省略号：末行以 U+2026 结尾', $lastGlyph->codepoint === TextLayout::ELLIPSIS);
check('Text · 省略号：末行宽度不超 maxWidth', $lastLine->width <= 60.0, (string) $lastLine->width);

// 缓存归实现内部，键 = (text, style, maxWidth, scale)（§7.1 / §8.1）
$probe = new DeterministicMetricsProvider($registry, new DeterministicShaper());
$probe->layout('Cache Probe', $body, INF, $unit);
$probe->layout('Cache Probe', $body, INF, $unit);
check('Text · 同键复用（不重复排版）', $probe->cachedCount() === 1, (string) $probe->cachedCount());
$probe->layout('Cache Probe', $body->withSize(15.0), INF, $unit);
$probe->layout('Cache Probe', $body, 100.0, $unit);
$probe->layout('Cache Probe', $body, INF, Scale::of(1.5));
$probe->layout('Cache Probe', $body->withAlign(TextAlign::Center), INF, $unit);
$probe->layout('Cache Probe Other', $body, INF, $unit);
check('Text · 键的四个分量都参与（text/style/maxWidth/scale）', $probe->cachedCount() === 6, (string) $probe->cachedCount());

// 光标唯一入口 / advance
check('Text · indexAt 落在正确的码位', $hello->indexAt(12.0, 8.0) === 1);
check('Text · advance() 与单行 layout 宽度一致', abs($textMetrics->advance('Hello', $body, $unit) - $hello->width) < 1e-9);

// 光栅：位图即 em 盒，未覆盖不画方框
$bitmap = $latinFace->rasterize(0x41, 14.0);
$ink = 0;
for ($y = 0; $y < $bitmap->height; $y++) {
    for ($x = 0; $x < $bitmap->width; $x++) {
        if ($bitmap->coverageAt($x, $y) > 0) {
            $ink++;
        }
    }
}
check('Text · 位图尺寸随 pixelSize 缩放', $bitmap->width === 9 && $bitmap->height === 14, $bitmap->width . 'x' . $bitmap->height);
check('Text · 基线上方 = ascent × pixelSize（位图即 em 盒）', $bitmap->bearingY === -12, (string) $bitmap->bearingY);
check('Text · 位图有墨', $ink > 0);
check('Text · 未覆盖码位不画方框', $latinFace->rasterize(0x4E2D, 14.0) === null);

// 响亮失败：未覆盖码位问步进留痕；家族无 face / 解析字体文件 ⇒ Fatal
fresh($log);
$latinFace->glyphAdvance(0x4E2D);
$ring = Errors::recentDiagnostics();
check('Text · 未覆盖码位问步进 ⇒ 留痕不静默', $ring !== [] && str_contains($ring[count($ring) - 1], 'U+4E2D'));

fresh($log);
[$code, $output] = runCase(__FILE__, 'font', null);
check('Text · 家族无可用 face 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Text · 家族无可用 face 写了 Errors.log', str_contains(logText($log), '没有可用的字体面'));

fresh($log);
[$code, $output] = runCase(__FILE__, 'fontfile', null);
check('Text · M0 不解析字体文件 ⇒ 响亮失败', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Text · 未实现就说是未实现（不静默忽略注册）', str_contains(logText($log), '不解析字体文件'));

// ⑫ 文字上屏：段落绘制命令 → 字形位图上屏（§7.2 / §8.2 坑 2）
fresh($log);
$black = Color::fromRgb(0, 0, 0);
$textScale = Scale::of(1.0);
$textParagraph = $textMetrics->layout('Hello', $body, INF, $textScale);
$textList = new DisplayList();
(new RecordingCanvas($textList))->drawParagraph($textParagraph, Paint::fill($black), 0.0, 0.0);
check('Rendering · 段落录制成一条命令', $textList->count() === 1 && $textList->opAt(0) === DisplayList::OP_DRAW_PARAGRAPH);

$textCanvas = new RasterCanvas(60, 20, Color::white(), $textScale);
$textList->replay($textCanvas);
$ink = inkCount($textCanvas);
check('Rendering · 文字真的画上了（有墨）', $ink > 0, (string) $ink);
check('Rendering · 文字落在基线之上（首行顶部仍是背景）', $textCanvas->pixelAt(0, 0)->equals(Color::white()));

// G3：同一份 DisplayList 重放 ⇒ 逐字节同一张位图
$repeated = new RasterCanvas(60, 20, Color::white(), $textScale);
$textList->replay($repeated);
check('Rendering · 段落重放确定（逐字节相同）', $textCanvas->toRgba() === $repeated->toRgba());

// 设备分辨率光栅：dpr 翻倍 ⇒ 墨的像素数变多（不是把 dpr=1 的位图放大）
$hidpi = new RasterCanvas(120, 40, Color::white(), Scale::of(2.0));
$textList->replay($hidpi);
check('Rendering · 字形在设备分辨率光栅（dpr=2 墨更多）', inkCount($hidpi) > $ink * 2, inkCount($hidpi) . ' vs ' . $ink);

// 裁剪对文本一样生效
$clipList = new DisplayList();
$clipRecorder = new RecordingCanvas($clipList);
$clipRecorder->pushClip(new Rect(0.0, 0.0, 8.0, 20.0));
$clipRecorder->drawParagraph($textParagraph, Paint::fill($black), 0.0, 0.0);
$clipRecorder->popClip();
$clipCanvas = new RasterCanvas(60, 20, Color::white(), $textScale);
$clipList->replay($clipCanvas);
$clippedInk = inkCount($clipCanvas);
check('Rendering · 裁剪对文本生效（区内画、区外挡）', $clippedInk > 0 && $clippedInk < $ink, $clippedInk . ' vs ' . $ink);

// 没有位图的字形（兜底 face）不画 —— 不是画一个方框顶上
$blankList = new DisplayList();
$blankParagraph = $textMetrics->layout("\u{1F600}", $body, INF, $textScale);
(new RecordingCanvas($blankList))->drawParagraph($blankParagraph, Paint::fill($black), 0.0, 0.0);
$blankCanvas = new RasterCanvas(32, 20, Color::white(), $textScale);
$blankList->replay($blankCanvas);
check('Rendering · 无位图的字形不上屏（不画方框顶替）', inkCount($blankCanvas) === 0, (string) inkCount($blankCanvas));

// 覆盖度即 alpha：半透明色画出的是灰，不是黑
$faded = new RasterCanvas(60, 20, Color::white(), $textScale);
$fadeList = new DisplayList();
(new RecordingCanvas($fadeList))->drawParagraph($textParagraph, Paint::fill(Color::fromRgba(0, 0, 0, 64)), 0.0, 0.0);
$fadeList->replay($faded);
check('Rendering · 覆盖度 × 颜色 alpha 走同一条混合（半透明 ⇒ 灰）', $faded->pixelAt(1, 4)->r > $textCanvas->pixelAt(1, 4)->r);

// ⑪ Key：跨位置稳定的身份（§12.3）
fresh($log);
$keyProbe = new SealProbe();
check('Key · 默认无 Key ⇒ 身份由位置决定', $keyProbe->identity() === null);
$keyProbe->key(7);
check('Key · key() 之后拿到值对象', $keyProbe->identity()?->value === 7);
check('Key · 同值即同一身份', Key::same(new Key(7), new Key(7)));
check('Key · 异值即不同身份', !Key::same(new Key(7), new Key(8)));
check('Key · 1 与 "1" 不等（不做类型互转）', !Key::same(new Key(1), new Key('1')));
check('Key · 双方都没有 ⇒ 可复用（位置相同）', Key::same(null, null));
check('Key · 一方有一方没有 ⇒ 不复用', !Key::same(new Key(1), null));
check('Key · key() 走 mutate()（返回同一实例）', $keyProbe->key(7) === $keyProbe);

fresh($log);
[$code, $output] = runCase(__FILE__, 'key', null);
check('Key · 封存后再给 Key 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Key · 封存后再给 Key 写了 Errors.log', str_contains(logText($log), 'Widget 已封存'));

// ⑫ 盒样式：值对象 + 中间基类（§15.7）
fresh($log);
$boxProbe = new BoxProbe();
check('BoxWidget · 不用样式 ⇒ 不分配 BoxStyle（零成本）', $boxProbe->box() === null);
check('BoxWidget · 结构节点继承裸 Widget ⇒ 拿不到 background()', !method_exists(SealProbe::class, 'background'));
$boxProbe->background(Color::white());
check('BoxWidget · background 落进 BoxStyle', $boxProbe->box()?->hasBackground() === true);
$boxProbe->border(Color::black(), 2.0);
check(
    'BoxWidget · 链式叠加是整体替换，不丢前一项',
    $boxProbe->box()?->hasBackground() === true && $boxProbe->box()?->borderWidth === 2.0
);
$boxProbe->radius(8.0);
check('BoxWidget · radius 独立改写', $boxProbe->box()?->radius === 8.0);
check('BoxWidget · 链式返回自身（不产生新实例）', $boxProbe->radius(4.0) === $boxProbe);

$plainBox = BoxStyle::none()->withBackground(Color::white());
$roundedBox = $plainBox->withRadius(4.0);
check(
    'BoxStyle · with- 返回新值，原值不变',
    $plainBox->radius === 0.0 && $roundedBox->radius === 4.0 && $roundedBox->hasBackground()
);

fresh($log);
[$code, $output] = runCase(__FILE__, 'box', null);
check('BoxWidget · 封存后改样式退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('BoxWidget · 封存后改样式写了 Errors.log', str_contains(logText($log), 'Widget 已封存'));

fresh($log);
if (is_file($log)) {
    unlink($log);
}

printf("\n--- %s ---\n", $failures === 0 ? '冒烟全绿' : $failures . ' 项失败');
exit($failures === 0 ? 0 : 1);
