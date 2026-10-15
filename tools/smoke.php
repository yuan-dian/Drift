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
use Drift\Dev\Smoke\BuildProbe;
use Drift\Dev\Smoke\Layout\ConstraintSpy;
use Drift\Dev\Smoke\Layout\OffsetBox;
use Drift\Dev\Smoke\Layout\OversizeBox;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Smoke\Commands\CommandWidget;
use Drift\Dev\Smoke\Commands\EchoCommands;
use Drift\Dev\Smoke\Commands\PickyCommands;
use Drift\App\Application;
use App\Hello\HelloApplication;
use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\QuietWidget;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Smoke\Frame\TickerWidget;
use Drift\Dev\Preview\DemoPage;
use Drift\Dev\Preview\Renderer;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\App\CompositeRootApp;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Dev\Smoke\Routing\NavStore;
use Drift\Dev\Smoke\Theme\ThemeSpy;
use Drift\Dev\Smoke\SealProbe;
use Drift\Dev\Smoke\State\BuildWatchWidget;
use Drift\Dev\Smoke\State\FakeStore;
use Drift\Dev\Smoke\State\HoverWidget;
use Drift\Dev\Smoke\Tree\BranchWidget;
use Drift\Dev\Smoke\Tree\CounterState;
use Drift\Dev\Smoke\Tree\CounterWidget;
use Drift\Errors;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Commands\CommandRouter;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Layout\FlexAlign;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderButton;
use Drift\Layout\RenderCenter;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderFlexible;
use Drift\Layout\RenderPadding;
use Drift\Layout\RenderProxyBox;
use Drift\Layout\RenderText;
use Drift\Rendering\BoxStyle;
use Drift\Routing\PageHost;
use Drift\Routing\PageHostState;
use Drift\Routing\NoPage;
use Drift\Routing\RouteTable;
use Drift\Widget\Offstage;
use Drift\Widget\OffstageElement;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\DisplayList;
use Drift\Rendering\MetricToken;
use Drift\Rendering\Paint;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\State\Cell;
use Drift\State\SubjectBuilder;
use Drift\State\Subject;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Text\FontFamily;
use Drift\Text\FontSlant;
use Drift\Text\FontWeight;
use Drift\Text\TextAlign;
use Drift\Text\TextLayout;
use Drift\Text\TextRole;
use Drift\Text\TextStyle;
use Drift\Theme\SystemTheme;
use Drift\Theme\ThemeData;
use Drift\Theme\ThemeScope;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\BoxWidget;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Each;
use Drift\Widget\Element;
use Drift\Widget\Key;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\Text;
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

    if ($case === 'watchbuild') {
        // A4：订阅写在 build 体内 ⇒ 每重建一次多订一份。运行期守卫必须响。
        $element = (new BuildWatchWidget(new FakeStore()))->createElement();
        $element->mount(null, smokeContext());
    }

    if ($case === 'cellunbound') {
        // 绕过 State::cell() 直接 new ⇒ 没有 Owner ⇒ 标不了脏 ⇒ 静默。G2：现在就炸。
        (new Cell(1))->setIfChanged(2);
    }

    if ($case === 'routedup') {
        // 同名路由注册两次 ⇒ 后一次静默覆盖 ⇒ 上线后"导航到某页却没反应"
        RouteTable::make()->route('users', static fn (): Widget => new LeafProbe(1.0))
            ->route('users', static fn (): Widget => new LeafProbe(2.0));
    }

    if ($case === 'cmdup') {
        // 同名注册两次 ⇒ 后一次静默覆盖前一次。上线后"点了没反应"且查不出为什么。
        CommandRouter::make()->on('a.b', new EchoCommands())->on('a.b', new EchoCommands());
    }

    if ($case === 'layout') {
        (new OversizeBox())->layout(new BoxConstraints(0.0, 10.0, 0.0, 10.0)); // C6 ⇒ 必须响亮失败
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

    if ($case === 'flexreport') {
        // C5：Report 策略 ⇒ 溢出必须中止，不是画到盒外面去
        $column = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Report);
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->layout(BoxConstraints::tight(new Size(10.0, 50.0)));
    }

    if ($case === 'flexscroll') {
        // Scroll / Wrap：M2 没实现 ⇒ 响亮失败，不是静默按 Clip 走
        $column = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Scroll);
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->layout(BoxConstraints::tight(new Size(10.0, 50.0)));
    }

    if ($case === 'buttoncommand') {
        // M2 还没接命令系统 ⇒ 响亮失败，不静默留一个按了没反应的按钮
        Button::make('刷新')->command('users.refresh')->createRenderObject(
            new BuildContext(320.0, 240.0, ThemeData::light(), new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ))
        );
    }

    if ($case === 'eachdup') {
        // 同层重复 key ⇒ diff 复用错乱（R4）。绝不静默 ⇒ Fatal
        Each::make(fn(array $u): int => $u['id'])
            ->items([['id' => 1], ['id' => 1]], fn(array $u): Widget => Text::make('x'));
    }

    if ($case === 'eachkeytag') {
        // key 1 与 '1' **不**算重复 —— 与 Key::same 同口径，不做类型互转
        Each::make(fn(array $u): string|int => $u['id'])
            ->items([['id' => 1], ['id' => '1']], fn(array $u): Widget => Text::make('x'));
        exit(1); // 走到这里 = 该报的没报
    }

    if ($case === 'flexvalue') {
        // flex 份额 < 1 分不到空间，静默夹紧只会让"给了 Expanding 却不生效"更难查
        new RenderFlexible(0);
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

/**
 * 墨迹包围盒 `[minX, maxX, minY, maxY]`；无墨 ⇒ null。
 *
 * ★ 只数墨点数（`inkCount`）测不出"画在了哪儿" —— `Center` 算对偏移而
 *   绘制丢掉它，墨点数一样多，画面却贴在左上角。
 */
function inkBounds(RasterCanvas $canvas): ?array
{
    $minX = PHP_INT_MAX;
    $maxX = -1;
    $minY = PHP_INT_MAX;
    $maxY = -1;
    $w = 320;
    $h = 240;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($canvas->pixelAt($x, $y)->r < 200) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
    }

    return $maxX < 0 ? null : [$minX, $maxX, $minY, $maxY];
}

function logText(string $log): string
{
    return is_file($log) ? (string) file_get_contents($log) : '';
}

/** 子进程用例也要挂载树 ⇒ 造一份和主进程同规格的渲染环境 */
function smokeContext(): BuildContext
{
    return new BuildContext(
        320.0,
        240.0,
        ThemeData::light(),
        new DeterministicMetricsProvider(
            DeterministicFontManager::standard()->collection(),
            new DeterministicShaper(),
        )
    );
}

/** 相对亮度（WCAG 口径），用来断言"高对比度"是真高而不是标了个名 */
function luminance(Color $c): float
{
    return (0.2126 * $c->r + 0.7152 * $c->g + 0.0722 * $c->b) / 255.0;
}

/** 数整棵子树有多少个 Element（含自己） */
function countTree(Element $element): int
{
    $total = 1;
    $element->visitChildren(function (Element $child) use (&$total): void {
        $total += countTree($child);
    });

    return $total;
}

function contrast(Color $a, Color $b): float
{
    $hi = max(luminance($a), luminance($b));
    $lo = min(luminance($a), luminance($b));

    return ($hi + 0.05) / ($lo + 0.05);
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

// ⑬ 主题：角色 → 值的一次解析（§11.5 / §15.7）
fresh($log);
$lightTheme = ThemeData::light();
$darkTheme = ThemeData::dark();

$bothModes = true;
foreach (ColorToken::cases() as $token) {
    if (!$token->lightValue() instanceof Color || !$token->darkValue() instanceof Color) {
        $bothModes = false;
    }
}
check('Theme · 每个颜色角色明暗都有值 ⇒ 换肤只换值不换键', $bothModes);
check(
    'Theme · 正文与底色在明暗下都反相（浅底深字 / 深底浅字）',
    luminance($lightTheme->color(ColorToken::Text)) < luminance($lightTheme->color(ColorToken::Pane))
        && luminance($darkTheme->color(ColorToken::Text)) > luminance($darkTheme->color(ColorToken::Pane))
);
check(
    'Theme · 暗色表把强调色提亮（深蓝压深灰 ⇒ 看不清）',
    luminance($darkTheme->color(ColorToken::Accent)) > luminance(ColorToken::Accent->lightValue())
);
check(
    'Theme · 度量与明暗无关（只有一张表）',
    $lightTheme->metric(MetricToken::FontSize) === $darkTheme->metric(MetricToken::FontSize)
);
check(
    'Theme · 语义角色字号在表里 ⇒ 换肤不改调用点',
    $lightTheme->metric(MetricToken::FontSizeTitle) > $lightTheme->metric(MetricToken::FontSize)
);

$accentTheme = $lightTheme->withColor(ColorToken::Accent, Color::black());
check(
    'Theme · withColor 返回新实例，原主题不变',
    $accentTheme !== $lightTheme
        && $accentTheme->color(ColorToken::Accent)->equals(Color::black())
        && $lightTheme->color(ColorToken::Accent)->equals(ColorToken::Accent->lightValue())
);
check(
    'Theme · withMetric 覆盖生效且原值不变',
    $lightTheme->withMetric(MetricToken::Gap, 20.0)->metric(MetricToken::Gap) === 20.0
        && $lightTheme->metric(MetricToken::Gap) === 8.0
);

$highContrast = $lightTheme->withHighContrast();
check(
    'Theme · 高对比度：边框对底色的对比度真的更高（不是标了个名）',
    contrast($highContrast->color(ColorToken::Border), $highContrast->color(ColorToken::Pane))
        > contrast($lightTheme->color(ColorToken::Border), $lightTheme->color(ColorToken::Pane)),
    sprintf(
        '%.2f vs %.2f',
        contrast($highContrast->color(ColorToken::Border), $highContrast->color(ColorToken::Pane)),
        contrast($lightTheme->color(ColorToken::Border), $lightTheme->color(ColorToken::Pane))
    )
);
check(
    'Theme · 高对比度跳过没有 HC 形态的角色（Hover 保持原值，不与 Pressed 撞车）',
    $highContrast->color(ColorToken::Hover)->equals($lightTheme->color(ColorToken::Hover))
);
check(
    'Theme · forSystem 按系统主题选表',
    ThemeData::forSystem(SystemTheme::Dark)->isDark() && !ThemeData::forSystem(SystemTheme::Light)->isDark()
);

// ⑭ BuildContext + StatelessWidget（§3.4 / §15.6.1）
fresh($log);
$context = new BuildContext(320.0, 240.0, ThemeData::light(), new DeterministicMetricsProvider(
    DeterministicFontManager::standard()->collection(),
    new DeterministicShaper(),
));
check('BuildContext · 只读渲染环境快照（约束宽高 + 解析后的主题）', $context->width === 320.0 && $context->height === 240.0);
check(
    'BuildContext · color() 与 theme() 是同一落点（不是两套取色）',
    $context->color(ColorToken::Text)->equals($context->theme()->color(ColorToken::Text))
);
check(
    'BuildContext · metric() 与 theme() 是同一落点',
    $context->metric(MetricToken::Gap) === $context->theme()->metric(MetricToken::Gap)
);
check(
    'BuildContext · 无向上查找（无 of / find / maybeOf —— §18 已否决 provider soup）',
    !method_exists(BuildContext::class, 'of')
        && !method_exists(BuildContext::class, 'find')
        && !method_exists(BuildContext::class, 'maybeOf')
);
check(
    'BuildContext · 无业务数据出口（不返回 Subject）',
    !method_exists(BuildContext::class, 'store') && !method_exists(BuildContext::class, 'subject')
);
check(
    'Widget · 基类上刻意没有 build()（§15.6.1：否则与 StatelessWidget 不兼容覆盖）',
    !method_exists(Widget::class, 'build')
);
check('StatelessWidget · build() 拿到本次渲染环境', (new BuildProbe())->build($context)->width === 320.0);
check(
    'StatelessWidget · 同样的入参保证同样的输出（纯函数）',
    (new BuildProbe())->build($context)->width === (new BuildProbe())->build($context)->width
);

// ⑮ 布局：两阶段 + 六条契约（§13.1）
fresh($log);
$solid = new SolidBox(new Size(10.0, 6.0), 24.0, 7.0);
check(
    'Layout · 布局是纯函数（同约束两次 ⇒ 同尺寸）',
    $solid->layout(BoxConstraints::unbounded())->equals($solid->layout(BoxConstraints::unbounded()))
);
check(
    'Layout · layout() 把尺寸存下来（第二阶段 paint 用得上）',
    $solid->hasSize() && $solid->size()?->equals(new Size(10.0, 6.0)) === true
);
check(
    'Layout · C3 内禀测量带另一轴（不是把自身尺寸回抄）',
    $solid->intrinsicWidth(0.0) === 24.0 && $solid->intrinsicWidth(0.0) !== $solid->size()?->width
);

// C1 单向数据流：父给子约束、父定位子
$childBox = new SolidBox(new Size(8.0, 5.0), 8.0, 5.0);
$holder = (new OffsetBox(new Size(100.0, 50.0)))->at(20.0, 10.0)->child($childBox);
$holder->layout(BoxConstraints::tight(new Size(100.0, 50.0)));
check(
    'Layout · C1 约束真的往下传了（父给子的是自己那份，不是自己的约束）',
    $holder->seenChildConstraints !== null && $holder->seenChildConstraints->hasInfiniteWidth()
);
check(
    'Layout · C1 父定位子（偏移由父写）',
    $childBox->offsetX() === 20.0 && $childBox->offsetY() === 10.0
);
$visited = 0;
$holder->visitChildren(function (RenderBox $visitor) use (&$visited): void {
    $visited++;
});
check('Layout · visitChildren 遍历到子节点', $visited === 1);

// paint：父的 translate 必须落到设备像素上
$treeList = new DisplayList();
$holder->paint(new RecordingCanvas($treeList));
$treeCanvas = new RasterCanvas(40, 30, Color::white(), Scale::of(1.0));
$treeList->replay($treeCanvas);
check(
    'Layout · paint 把父的 translate 落到设备像素上（偏移 20,10 处有墨、原点没有）',
    $treeCanvas->pixelAt(21, 11)->r < 128 && $treeCanvas->pixelAt(0, 0)->r >= 250
);

// C4 / C5：溢出策略
check(
    'Layout · C5 默认溢出策略 = Clip（只给不会超的容器）',
    (new SolidBox(new Size(1.0, 1.0), 1.0, 1.0))->overflow() === Overflow::Clip
);
check(
    'Layout · C5 会超的容器显式覆盖默认',
    (new OversizeBox())->overflow() === Overflow::Report
);

// ── ⑮ Flex：C4 弹性子拿无界主轴 / spacing / flex 分配 / 交叉轴 / 溢出 ──

// C4：有弹性子 ⇒ 弹性子拿无界主轴；非弹性子拿 0..可用
$elasticSpy = new ConstraintSpy(new Size(40.0, 30.0));
$plainSpy = new ConstraintSpy(new Size(40.0, 10.0));
$c4Column = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
$c4Column->addChild($plainSpy);
$c4Column->addChild(new RenderFlexible(1));
$c4Column->children()[1]->setChild($elasticSpy);
$c4Column->layout(new BoxConstraints(0.0, 100.0, 0.0, 60.0));
check(
    'Layout · C4 弹性子拿到无界主轴（不是"现在剩多少"）',
    $elasticSpy->firstSeen !== null && $elasticSpy->firstSeen->hasInfiniteHeight(),
    '实际第一遍 maxHeight=' . (string) ($elasticSpy->firstSeen?->maxHeight ?? -1.0)
);
check(
    'Layout · C4 非弹性子拿 0..可用（同一遍里两者不同）',
    $plainSpy->firstSeen !== null && !$plainSpy->firstSeen->hasInfiniteHeight() && $plainSpy->firstSeen->maxHeight === 60.0
);

// flex 分配：1 : 2 分 60 的可用高（两个自然高 0）
$flexA = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
$flexA->addChild(new RenderFlexible(1));
$flexA->addChild(new RenderFlexible(2));
$flexA->layout(BoxConstraints::tight(new Size(100.0, 60.0)));
$flexA->visitChildren(function (RenderBox $kid) use (&$got): void {
});
check(
    'Layout · flex 按份额分剩余空间（1:2 ⇒ 20 / 40）',
    $flexA->children()[0]->size()?->height === 20.0 && $flexA->children()[1]->size()?->height === 40.0,
    sprintf('实际 %.2f / %.2f', $flexA->children()[0]->size()?->height ?? -1.0, $flexA->children()[1]->size()?->height ?? -1.0)
);

// spacing + 位置由父定（C1）
$spaced = new RenderFlex(true, 8.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
$spaced->addChild(new SolidBox(new Size(20.0, 10.0), 20.0, 10.0));
$spaced->addChild(new SolidBox(new Size(20.0, 12.0), 20.0, 12.0));
$spaced->addChild(new SolidBox(new Size(20.0, 14.0), 20.0, 12.0));
$spaced->layout(BoxConstraints::loose(new Size(80.0, 100.0)));
check(
    'Layout · spacing 落在相邻子之间（10 / 10+12+8）',
    $spaced->children()[0]->offsetY() === 0.0 && $spaced->children()[1]->offsetY() === 18.0,
    sprintf('实际 %.2f / %.2f', $spaced->children()[0]->offsetY(), $spaced->children()[1]->offsetY())
);
check(
    'Layout · 自然主轴高 = 各子相加 + 间距（10+12+14+2×8）',
    $spaced->size()?->height === 52.0,
    '实际 ' . (string) ($spaced->size()?->height ?? -1.0)
);

// 交叉轴对齐
$centered = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Center, Overflow::Clip);
$centered->addChild(new SolidBox(new Size(30.0, 10.0), 30.0, 10.0));
$centered->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
check(
    'Layout · 交叉轴 Center 把短的那轴推到中间',
    $centered->children()[0]->offsetY() === 15.0,
    '实际 ' . (string) $centered->children()[0]->offsetY()
);

// padding：几何面，减约束再加回尺寸
$padded = new RenderFlex(true, 0.0, EdgeInsets::all(6.0), FlexAlign::Start, Overflow::Clip);
$padded->addChild(new SolidBox(new Size(10.0, 8.0), 10.0, 8.0));
$padded->layout(BoxConstraints::loose(new Size(80.0, 80.0)));
check(
    'Layout · padding 进尺寸（子 8 + 上下各 6）',
    $padded->size()?->height === 20.0 && $padded->size()?->width === 22.0,
    sprintf('实际 %.2f × %.2f', $padded->size()?->width ?? -1.0, $padded->size()?->height ?? -1.0)
);
check(
    'Layout · padding 减约束后子拿到的是内容区（不是 80 高）',
    $padded->children()[0]->size()?->height === 8.0
);

// 单子代理：RenderPadding
$inner = new SolidBox(new Size(10.0, 6.0), 10.0, 6.0);
$padBox = new RenderPadding(EdgeInsets::symmetric(4.0, 2.0));
$padBox->setChild($inner);
$padBox->layout(BoxConstraints::loose(new Size(60.0, 60.0)));
check(
    'Layout · RenderPadding 内禀值 = 内边距 + 孩子（不是孩子本身）',
    $padBox->intrinsicWidth(0.0) === 18.0 && $padBox->intrinsicHeight(0.0) === 10.0,
    sprintf('实际 %.2f × %.2f', $padBox->intrinsicWidth(0.0), $padBox->intrinsicHeight(0.0))
);

// C4 / C5 的失败路径（子进程：会 Fatal）
fresh($log);
[$code, $output] = runCase(__FILE__, 'flexreport', null);
check('Layout · C5 Report 溢出退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Layout · C5 Report 溢出写了 Errors.log', str_contains(logText($log), '溢出'));

// Clip 是"盒子夹住、子照自然排"——不中止，但自己的尺寸不越界
$clipColumn = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
$clipColumn->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
$clipColumn->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
$clipColumn->layout(BoxConstraints::tight(new Size(10.0, 50.0)));
check(
    'Layout · Clip 允许子溢出但自己夹进约束（不撞 C6）',
    $clipColumn->size()?->height === 50.0 && $clipColumn->children()[1]->offsetY() === 40.0,
    sprintf('实际 %.2f / %.2f', $clipColumn->size()?->height ?? -1.0, $clipColumn->children()[1]->offsetY())
);

// Scroll / Wrap：声明了但没实现 ⇒ 响亮失败，不是空壳
fresh($log);
[$code, $output] = runCase(__FILE__, 'flexscroll', null);
check('Layout · Scroll / Wrap 未实现 ⇒ 响亮失败退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Layout · Scroll / Wrap 未实现写了 Errors.log', str_contains(logText($log), '尚未实现'));

// flex 份额 < 1 是构造成员错误（G2：绝不静默夹紧）⇒ 子进程里验
fresh($log);
[$code, $output] = runCase(__FILE__, 'flexvalue', null);
check('Layout · flex 份额 < 1 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Layout · flex 份额 < 1 写了 Errors.log', str_contains(logText($log), 'flex 份额'));

fresh($log);
[$code, $output] = runCase(__FILE__, 'layout', null);
check('Layout · C6 布局违反约束退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Layout · C6 布局违反约束写了 Errors.log', str_contains(logText($log), '违反约束'));

// ⑯ 三树：Element 的挂载 / diff / 卸载（§3.2 / §12.3）
fresh($log);
$treeRoot = (new BranchWidget([new BuildProbe(), new BuildProbe()]))->createElement();
$treeRoot->mount(null, $context);

// 结构节点(1) + 2 个 Stateless(各带 1 个叶子) = 5
check('Element · 挂载建出整棵子树（结构节点 → 2 个无状态 → 各 1 叶）', countTree($treeRoot) === 5, (string) countTree($treeRoot));
check('Element · 挂载时封存 Widget', $treeRoot->widget()->isSealed() && $treeRoot->childAt(0) !== null && $treeRoot->childAt(0)->widget()->isSealed());
check('Element · 渲染环境沿父链继承（不是每层新建一份）', $treeRoot->childAt(0)?->context() === $context);

// diff：换同一形态的新控件 ⇒ Element 身份稳定（State / 子树不重置）
$firstKid = $treeRoot->childAt(0);
$treeRoot->update(new BranchWidget([new BuildProbe(), new BuildProbe()]));
check(
    'Element · diff 复用同槽旧子（身份稳定，不重置子树）',
    $treeRoot->childAt(0) === $firstKid && $treeRoot->isMounted()
);

// 位置决定身份：无 Key 换位置 ⇒ 还是同两个槽位复用（§12.3 原话）
$keyless = (new BranchWidget([(new BuildProbe())->key('a'), (new BuildProbe())->key('b')]))->createElement();
$keyless->mount(null, $context);
$slotA = $keyless->childAt(0);
$slotB = $keyless->childAt(1);
$keyless->update(new BranchWidget([(new BuildProbe())->key('b'), (new BuildProbe())->key('a')]));
check(
    'Element · 有 Key ⇒ 位置变了身份不变（换位复用的是同名那个）',
    $keyless->childAt(0) === $slotB && $keyless->childAt(1) === $slotA
);

// Key 变了 ⇒ 不复用，换新 Element
$keyless->update(new BranchWidget([(new BuildProbe())->key('a'), (new BuildProbe())->key('c')]));
check(
    'Element · Key 变了 ⇒ 不复用（新 Element 进槽）',
    $keyless->childAt(0) === $slotA && $keyless->childAt(1) !== $slotB
);

// 同一实例挂两处合法 ⇒ 两个 Element（身份由位置决定）
$twice = new BuildProbe();
$pair = (new BranchWidget([$twice, $twice]))->createElement();
$pair->mount(null, $context);
check(
    'Element · 同一实例挂两处 ⇒ 两个 Element（位置各有一个）',
    $pair->childCount() === 2 && $pair->childAt(0) !== $pair->childAt(1)
);

// 卸载：级联到全树
$unmountRoot = (new BranchWidget([new BuildProbe(), new BuildProbe()]))->createElement();
$unmountRoot->mount(null, $context);
$kidsBefore = [];
$unmountRoot->visitChildren(function (Element $child) use (&$kidsBefore): void {
    $kidsBefore[] = $child;
});
$unmountRoot->unmount();
$allDown = true;
foreach ($kidsBefore as $kid) {
    if ($kid->isMounted()) {
        $allDown = false;
    }
}
check('Element · unmount 级联到全树', !$unmountRoot->isMounted() && $allDown);
check('Element · unmount 后子列表清空', $unmountRoot->childCount() === 0);

// update() 换的是新描述，且同样封存
$rebuilt = (new BranchWidget([new BuildProbe()]))->createElement();
$rebuilt->mount(null, $context);
$nextWidget = new BuildProbe();
$rebuilt->update(new BranchWidget([$nextWidget]));
check('Element · update 换整体新描述并封存', $rebuilt->childAt(0)?->widget() === $nextWidget && $nextWidget->isSealed());

// ── ⑱ 结构控件：Column / Row / Padding / Center / Spacer / Expanded ──

// 盒子真的建出来了，且子盒由父挂接（C1：父管挂接）
$columnRoot = Column::make()->children([Spacer::make(), Spacer::make(3)])->createElement();
$columnRoot->mount(null, $context);
$columnFlex = $columnRoot->renderObject();
check('结构控件 · Column 建出 RenderFlex', $columnFlex instanceof RenderFlex);
check('结构控件 · 结构节点的子 Box 也建了盒', $columnRoot->childCount() === 2 && $columnRoot->childAt(0)?->renderObject() !== null);

// 端到端：Element → adoptBoxes → flex 分配。1 : 3 分 60
$columnFlex?->layout(BoxConstraints::tight(new Size(100.0, 60.0)));
$columnHeight = [];
$columnFlex?->visitChildren(function (RenderBox $kid) use (&$columnHeight): void {
    $columnHeight[] = $kid->size()?->height ?? -1.0;
});
check(
    '结构控件 · 端到端 flex 分配（Spacer 1:3 ⇒ 15 / 45，不是各 30）',
    $columnHeight === [15.0, 45.0],
    '实际 ' . implode(' / ', $columnHeight)
);

// Row 与 Column 只差方向：同一个基类，不是两份拷贝
$rowRoot = Row::make()->children([Spacer::make(), Spacer::make(3)])->createElement();
$rowRoot->mount(null, $context);
$rowFlex = $rowRoot->renderObject();
$rowFlex?->layout(BoxConstraints::tight(new Size(120.0, 40.0)));
$rowWidth = [];
$rowFlex?->visitChildren(function (RenderBox $kid) use (&$rowWidth): void {
    $rowWidth[] = $kid->size()?->width ?? -1.0;
});
check(
    '结构控件 · Row 主轴是横（1:3 分 120 ⇒ 30 / 90）',
    $rowWidth === [30.0, 90.0],
    '实际 ' . implode(' / ', $rowWidth)
);

// Padding：几何面，进盒尺寸
$padRoot = Padding::make(Spacer::make())->all(10.0)->createElement();
$padRoot->mount(null, $context);
$padBox = $padRoot->renderObject();
check('结构控件 · Padding 建出 RenderPadding', $padBox instanceof RenderPadding);
$padBox?->layout(BoxConstraints::loose(new Size(80.0, 80.0)));
check(
    '结构控件 · Padding 进盒尺寸（孩子 0 + 上下各 10）',
    $padBox?->size()?->height === 20.0,
    '实际 ' . (string) ($padBox?->size()?->height ?? -1.0)
);

// Center：孩子在内容区正中
$centerRoot = Center::make()->child(Spacer::make())->createElement();
$centerRoot->mount(null, $context);
$centerBox = $centerRoot->renderObject();
check('结构控件 · Center 建出 RenderCenter', $centerBox instanceof RenderCenter);
$centerBox?->layout(BoxConstraints::loose(new Size(100.0, 100.0)));
$centerKid = null;
$centerBox?->visitChildren(function (RenderBox $kid) use (&$centerKid): void {
    $centerKid = $kid;
});
check(
    '结构控件 · Center 把孩子推到正中（100×100 里 offset 50,50）',
    $centerKid !== null && $centerKid->offsetX() === 50.0 && $centerKid->offsetY() === 50.0
);

// 结构节点拿不到 background() —— §15.7 纪律 1（防以后手滑把样式提上基类）
check('结构控件 · Column / Row / Spacer 不是 BoxWidget', !is_subclass_of(Column::class, BoxWidget::class) && !is_subclass_of(Row::class, BoxWidget::class));

// 结构控件没有 build() —— 它们不是 StatelessWidget
check('结构控件 · Column / Row 上没有 build()（结构节点形态）', !method_exists(Column::class, 'build') && !method_exists(Row::class, 'build'));

// 换 children：Element 复用，盒重造（§13.2）
$growRoot = Column::make()->children([Spacer::make()])->createElement();
$growRoot->mount(null, $context);
$firstBox = $growRoot->renderObject();
$growRoot->update(Column::make()->children([Spacer::make(2)]));
check('结构控件 · update 换描述 ⇒ 换盒（§13.2：不存在字段级半同步）', $growRoot->renderObject() !== null && $growRoot->renderObject() !== $firstBox);

// ── ⑲ Text 控件：哨兵三档 + 角色经主题解析（§15.2 / §15.7 纪律 3）──

// 哨兵：Unset 是显式 case ⇒ 0 是合法取值（->body() 解析出来就是它）
$unsetText = Text::make('x');
check('Text · 哨兵是 enum case 不是裸数字（Unset 有名字）', TextRole::Unset->value === 0 && $unsetText->role() === TextRole::Unset);
check('Text · body() 落成 Body（0 是合法值，不是"未设置"）', Text::make('x')->body()->role() === TextRole::Body);

// 三态 bool：未设置 / 否 / 是 三者可区分
$tristateA = Text::make('x');
$tristateB = Text::make('x')->bold(false);
$tristateC = Text::make('x')->bold();
check(
    'Text · bold 三态可分（未设置 / 否 / 是）',
    $tristateA->explicitBold() === null && $tristateB->explicitBold() === false && $tristateC->explicitBold() === true
);

// float 哨兵：负值域外
check('Text · size 哨兵是 -1.0（尺寸非负 ⇒ 域外）', Text::make('x')->explicitSize() === null);
check('Text · 给了 size 就用显式值', Text::make('x')->size(22.0)->explicitSize() === 22.0);

// 角色 → 主题：换肤只换表，调用点不动
$lightTitle = Text::make('标题')->title()->createRenderObject($context);
$darkContext = new BuildContext(320.0, 240.0, ThemeData::dark(), $context->metrics());
$darkTitle = Text::make('标题')->title()->createRenderObject($darkContext);
check(
    'Text · title() 的字号来自主题表（明 18）',
    $lightTitle instanceof RenderText && $lightTitle->styleSize() === 18.0,
    (string) ($lightTitle instanceof RenderText ? $lightTitle->styleSize() : -1.0)
);
check(
    'Text · caption 比 body 小，title 比 body 大（层次是排出来的不是写死的）',
    Text::make('x')->caption()->createRenderObject($context)->styleSize() === 12.0
        && Text::make('x')->title()->createRenderObject($context)->styleSize() === 18.0
);

// 显式 size 优先于角色
check(
    'Text · 显式 size 盖过角色（优先级只在 ThemeData 解析一次）',
    Text::make('x')->title()->size(40.0)->createRenderObject($context)->styleSize() === 40.0
);

// 端到端：Element → 盒 → 真实排版（不是"能造出来"）
$textRoot = Text::make('Hello Drift')->body()->createElement();
$textRoot->mount(null, $context);
$textBox = $textRoot->renderObject();
check('Text · 造出 RenderText', $textBox instanceof RenderText);
$textBox?->layout(BoxConstraints::loose(new Size(200.0, 100.0)));
check(
    'Text · 排版走注入的度量，得到非零尺寸',
    ($textBox?->size()?->width ?? 0.0) > 0.0 && ($textBox?->size()?->height ?? 0.0) > 0.0,
    sprintf('实际 %.2f × %.2f', $textBox?->size()?->width ?? -1.0, $textBox?->size()?->height ?? -1.0)
);

// 受宽度约束 ⇒ 折行 ⇒ 变高（断行依赖可用宽，这是 C1 的必然推论）
$narrow = Text::make('Hello Drift')->body()->createElement();
$narrow->mount(null, $context);
$narrowBox = $narrow->renderObject();
$narrowBox?->layout(BoxConstraints::loose(new Size(30.0, 1000.0)));
check(
    'Text · 收窄约束 ⇒ 折行 ⇒ 更高（宽度变了断行就变）',
    ($narrowBox?->size()?->height ?? 0.0) > ($textBox?->size()?->height ?? 0.0),
    sprintf('窄 %.2f vs 宽 %.2f', $narrowBox?->size()?->height ?? -1.0, $textBox?->size()?->height ?? -1.0)
);

// maxLines / ellipsize 进的是 TextStyle（§8.1：影响排版的输入都必须在 style 里，
// 否则缓存键 (text, style, maxWidth, scale) 会撒谎）
$clipped = Text::make('Hello Drift Framework')->body()->maxLines(1)->ellipsize()->createRenderObject($context);
$clipped->layout(BoxConstraints::loose(new Size(40.0, 1000.0)));
check(
    'Text · maxLines 真的限住行数（1 行 ⇒ 高度 = 一行高）',
    $clipped instanceof RenderText && $clipped->lineCount() === 1,
    (string) ($clipped instanceof RenderText ? $clipped->lineCount() : -1)
);

// ── ㉑ Each（原方案的 ForEach —— PHP 保留字撞名，见提交说明）──

$users = [
    ['id' => 7, 'name' => '甲'],
    ['id' => 3, 'name' => '乙'],
    ['id' => 9, 'name' => '丙'],
];
$eachWidget = Each::make(fn(array $u): int => $u['id'])
    ->items($users, fn(array $u): Widget => Text::make($u['name'])->body());

// keyFn 产出的 key 真的落到了子 widget 上 —— 不是"能调用"就算完
$eachKids = $eachWidget->itemWidgets();
check('Each · 每个 item 都带上了 keyFn 产出的 key', count($eachKids) === 3 && $eachKids[1]->identity()?->value === 3, var_export($eachKids[1]->identity()?->value, true));

// key 改变了 diff 身份：同位置换新控件但 key 相同 ⇒ 复用；key 不同 ⇒ 卸旧挂新
$eachRoot = $eachWidget->createElement();
$eachRoot->mount(null, $context);
check('Each · 挂载铺出 3 个子 Element', $eachRoot->childCount() === 3);
$firstKidElement = $eachRoot->childAt(0);
$eachRoot->update(
    Each::make(fn(array $u): int => $u['id'])->items($users, fn(array $u): Widget => Text::make($u['name'])->body())
);
check(
    'Each · key 相同 ⇒ diff 复用同一个 Element（State 不重置）',
    $eachRoot->childAt(0) === $firstKidElement
);
$reordered = [['id' => 3, 'name' => '乙'], ['id' => 7, 'name' => '甲'], ['id' => 9, 'name' => '丙']];
$eachRoot->update(
    Each::make(fn(array $u): int => $u['id'])->items($reordered, fn(array $u): Widget => Text::make($u['name'])->body())
);
check(
    'Each · 列表重排 ⇒ key 跟随 item（不是跟随槽位）',
    $eachRoot->childAt(0)?->widget()->identity()?->value === 3
        && $eachRoot->childAt(1)?->widget()->identity()?->value === 7
);

// 空列表 ⇒ 0 个子，不炸
$emptyRoot = Each::make(fn(array $u): int => $u['id'])->items([], fn(array $u): Widget => Text::make('x'))->createElement();
$emptyRoot->mount(null, $context);
check('Each · 空列表铺出 0 个子（不塌、不炸）', $emptyRoot->childCount() === 0);

// 重复 key ⇒ 响亮失败（G2：绝不静默）
fresh($log);
[$code, $output] = runCase(__FILE__, 'eachdup', null);
check('Each · 重复 key 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Each · 重复 key 写了 Errors.log', str_contains(logText($log), '重复的 key'));

// key 1 与 '1' 不算重复（与 Key::same 同口径）：int id 与字符串 id 不静默撞车
fresh($log);
[$code, $output] = runCase(__FILE__, 'eachkeytag', null);
check("Each · key 1 与 '1' 不算重复（子进程 exit 1 = 该报的没报）", $code === 1, '实际 ' . $code . ' / ' . $output);

// ── ㉒ Button：变体是 make() 的薄壳别名（§15.4 删除测试）──

// "糖只转发"的删除测试：primary/danger 必须等价于 make()->variant(...)
$primaryDirect = Button::primary('刷新');
$primaryForward = Button::make('刷新')->variant(ButtonVariant::Primary);
check(
    'Button · primary() 是 make()+variant() 的薄壳（糖删掉框架契约不变）',
    $primaryDirect->variantValue() === $primaryForward->variantValue()
        && $primaryDirect->variantValue() === ButtonVariant::Primary
);
check('Button · danger() 同形', Button::danger('删')->variantValue() === ButtonVariant::Danger);

// onPressed 是纯 UI 回调；Button 本身不依赖命令系统
$fired = 0;
$btn = Button::make('取消')->onPressed(function () use (&$fired): void { $fired++; });
$handler = $btn->pressedHandler();
check('Button · onPressed 闭包原样递下去（不是包一层的机制）', $handler !== null && $handler() === null && $fired === 1);

// 造盒 → 配色经 BuildContext 解析一次（§15.7 纪律 3），Layout 层不碰主题
$lightBtn = Button::primary('刷新')->createRenderObject($context);
$darkBtn = Button::primary('刷新')->createRenderObject($darkContext);
check('Button · 造出 RenderButton', $lightBtn instanceof RenderButton);
check(
    'Button · 同一变体在明/暗下取到不同底色（换肤改表不改控件）',
    $lightBtn->boxStyle()->background?->toArgb() !== $darkBtn->boxStyle()->background?->toArgb(),
);
check(
    'Button · Default 变体有边框、Primary 没有（描边是 Default 的辨识手段）',
    Button::make('x')->createRenderObject($context)->boxStyle()->borderColor !== null
        && Button::primary('x')->createRenderObject($context)->boxStyle()->borderColor === null
);

// 端到端：盒子尺寸 = 内边距 + 标签，且 Label 是它的孩子（按钮文字换行规则与 Text 一致）
$btnRoot = Button::primary('刷新')->createElement();
$btnRoot->mount(null, $context);
$btnBox = $btnRoot->renderObject();
$btnBox?->layout(BoxConstraints::loose(new Size(200.0, 60.0)));
$btnKids = [];
$btnBox?->visitChildren(function (RenderBox $kid) use (&$btnKids): void { $btnKids[] = $kid; });
check(
    'Button · 标签是它的唯一孩子（RenderText）',
    count($btnKids) === 1 && $btnKids[0] instanceof RenderText,
);
check(
    'Button · 盒尺寸 = 内边距 + 标签（不是 0，也不是填满约束）',
    ($btnBox?->size()?->width ?? 0.0) > 0.0 && ($btnBox?->size()?->width ?? 0.0) < 200.0,
    sprintf('实际 %.2f × %.2f', $btnBox?->size()?->width ?? -1.0, $btnBox?->size()?->height ?? -1.0)
);

// command() 在 M2 响亮说没实现（不静默留下一个按了没反应的按钮）
fresh($log);
[$code, $output] = runCase(__FILE__, 'buttoncommand', null);
check('Button · M2 未接命令系统退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Button · 未接命令系统写了 Errors.log', str_contains(logText($log), '命令系统属 M3'));

// ── ⑳ 控件与结构节点混用（§A.1 的形态真的能搭起来）──
$mixed = Column::make()->spacing(8)->paddingAll(12)->children([
    Text::make('标题')->title(),
    Row::make()->spacing(8)->children([
        Text::make('左')->body(),
        Spacer::make(),
        Text::make('右')->caption(),
    ]),
]);
$mixedRoot = $mixed->createElement();
$mixedRoot->mount(null, $context);
$mixedBox = $mixedRoot->renderObject();
$mixedBox?->layout(BoxConstraints::loose(new Size(400.0, 300.0)));
check(
    '控件 · Column 包 Text + Row(Text/Spacer/Text) 能一路排到底',
    ($mixedBox?->size()?->width ?? 0.0) > 0.0 && ($mixedBox?->size()?->height ?? 0.0) > 0.0,
    sprintf('实际 %.2f × %.2f', $mixedBox?->size()?->width ?? -1.0, $mixedBox?->size()?->height ?? -1.0)
);

// ⑰ State：setState 只标脏（§5.2 / §11.3.1 / §12.3）
fresh($log);
$counterElement = (new CounterWidget())->createElement();
$counterElement->mount(null, $context);
$counter = $counterElement->state();

check('State · 挂载时造一次，之后是同一个', $counter instanceof CounterState && $counterElement->state() === $counter);
check('State · build() 经 State 出，并收到渲染环境', $counter->builds === 1 && $counter->seenWidth === 320.0);
check('State · 挂载时也建出了一层子', countTree($counterElement) === 2);

// ★ §5.2 的核心：setState **只标脏**，不立即重建
$counter->bump();
check('State · setState 跑闭包', $counter->bumps === 1);
check('State · setState 只标脏，不立即重建', $counter->needsBuild() && $counter->builds === 1);
$counterElement->rebuild();
check('State · 帧末重建后清脏', !$counter->needsBuild() && $counter->builds === 2);
check('State · 重建不换 State（纯 UI 态活过整个 build）', $counterElement->state() === $counter);

// §12.3：换描述时 State 跟着走，旧的不丢
$newCounter = new CounterWidget();
$counterElement->update($newCounter);
check('State · 换新描述时 State 不换（状态活过更新）', $counterElement->state() === $counter);
check('State · State 手里的 Widget 换成整份新的', $counter->widget() === $newCounter);

// §12.3 回收纪律：卸载 ⇒ dispose
$counterElement->unmount();
check('State · unmount 调 dispose()（§12.3 回收纪律）', $counter->disposed);

// ── ㉓ State 层：订阅生命周期 / Cell 门控 / select 切片（§11.3）──

// ① SubjectBuilder：门控比的是**两次 select 输出**，不是 Store 的新旧值（§11.3.3）
$store = new FakeStore();
$builderRuns = 0;
$builderArgs = [];
$sub = SubjectBuilder::make($store)
    ->select(static function (FakeStore $s): int { return $s->status(); })
    ->builder(static function (mixed ...$args) use (&$builderRuns, &$builderArgs): Widget {
        $builderRuns++;
        $builderArgs = $args;

        return new LeafProbe(1.0);
    });
$subElement = $sub->createElement();
$subElement->mount(null, $context);
check(
    'State · initState 在首帧 build 之前 ⇒ 首帧就拿到选中的值',
    $builderRuns === 1 && $builderArgs === [0],
    'runs=' . $builderRuns . ' args=' . json_encode($builderArgs)
);

// 同值再 notify：select 输出没变 ⇒ **连脏都不标**（门控在回调里就挡掉了）
$store->setStatus(0);
check(
    'State · 同值 notify 被门控挡住（两次 select 输出相等）',
    $builderRuns === 1 && !$subElement->state()->needsBuild(),
    'runs=' . $builderRuns
);

// 改了字段但不 notify ⇒ 订阅者收不到（重建由**通知**驱动，不是由字段变动驱动）
$store->silentTouch(5);
check(
    'State · 改字段但不 notify ⇒ 订阅者收不到',
    $builderRuns === 1 && !$subElement->state()->needsBuild(),
    'runs=' . $builderRuns
);

// notify 且 select 输出变了 ⇒ 标脏
$store->setStatus(6);
check('State · notify 后 select 输出变了 ⇒ 标脏', $subElement->state()->needsBuild(), '没有标脏');

// 帧末 flush 的动作。★ flush 循环（走全树消费脏标记）本身属 App 层（#22）——
// State 层只负责"标脏标对"，这里显式 rebuild 一次来证明链路是通的。
$subElement->rebuild();
$subChild = $subElement->childAt(0)?->widget();
check(
    'State · 重建后 builder 拿到 select 的新值（不是 null、不是 Store）',
    $builderRuns === 2 && $builderArgs === [6] && $subChild instanceof LeafProbe,
    'runs=' . $builderRuns . ' args=' . json_encode($builderArgs)
);

// ② 卸载后退订 —— 没有"僵尸更新"，且**不依赖用户写 dispose()**
$subElement->unmount();
$store->setStatus(9);
check(
    'State · unmount 后退订（订阅袋全退，无僵尸更新）',
    $builderRuns === 2 && !$subElement->state()->needsBuild(),
    'runs=' . $builderRuns
);

// ③ 无 select ⇒ builder 收 0 个参数（★ 不是统一传 null）
$plainRuns = 0;
$plainArgs = null;
$plain = SubjectBuilder::make($store)->builder(static function (mixed ...$args) use (&$plainRuns, &$plainArgs): Widget {
    $plainRuns++;
    $plainArgs = $args;

    return new LeafProbe(1.0);
});
$plainElement = $plain->createElement();
$plainElement->mount(null, $context);
check(
    'State · 无 select ⇒ builder 收 0 个参数',
    $plainRuns === 1 && $plainArgs === [],
    'args=' . json_encode($plainArgs)
);
$store->setStatus(11);
check('State · 无 select ⇒ 任何 notify 都标脏', $plainElement->state()->needsBuild(), '没有标脏');
$plainElement->rebuild();
check(
    'State · 无 select ⇒ 重建后 builder 仍收 0 个参数',
    $plainRuns === 2 && $plainArgs === [],
    'args=' . json_encode($plainArgs)
);
$plainElement->unmount();

// ④ Cell：设同值不标脏（§11.3.1 / RT-4）
$hoverElement = (new HoverWidget())->createElement();
$hoverElement->mount(null, $context);
$hoverState = $hoverElement->state();
check('State · initState 只跑一次（重复挂载不重复订阅）', $hoverState->inits === 1, '实际 ' . $hoverState->inits);
$hoverState->onRowHover(2);
check('State · Cell 换值 ⇒ 标脏', $hoverState->needsBuild(), '没有标脏');
$hoverElement->rebuild();
$hoverState->onRowHover(2); // 同一行
check('State · Cell 设同值不标脏（RT-4：=== 严格比较）', !$hoverState->needsBuild(), '同值也标了脏');
$hoverState->onRowHover(3);
check('State · Cell 换新值 ⇒ 再标脏', $hoverState->needsBuild(), '换值没标脏');
$hoverElement->unmount();

// ⑤ RT-3：一个订阅者抛异常，不妨碍其余订阅者收到通知
$debugWas = Errors::isDebug();
Errors::setDebug(false); // Recoverable 在 debug 会中止，先关掉才测得着"隔离"
try {
    $isoLog = $root . '/iso.log';
    fresh($isoLog);
    $ran = [];
    $ownerA = new FakeStore();
    $ownerB = new FakeStore();
    $ownerC = new FakeStore();
    $iso = new FakeStore();
    $iso->subscribeFor($ownerA, static function () use (&$ran): void { $ran[] = 'a'; });
    $iso->subscribeFor($ownerB, static function () use (&$ran): void {
        $ran[] = 'b';
        throw new \RuntimeException('订阅者 B 炸了');
    });
    $iso->subscribeFor($ownerC, static function () use (&$ran): void { $ran[] = 'c'; });
    $iso->notifyChanged();
    check(
        'State · 一个订阅者抛异常，其余仍收到通知（RT-3）',
        $ran === ['a', 'b', 'c'],
        '实际 ' . implode(',', $ran)
    );
    check('State · 订阅者异常没有静默（写了 Errors 出口）', str_contains(logText($isoLog), '订阅者异常已隔离'));
    check('State · 异常报的是 Recoverable 级', str_contains(logText($isoLog), 'RECOVERABLE'));
    $iso->unsubscribeAllOf($ownerC);
    $ran = [];
    $iso->notifyChanged();
    check('State · unsubscribeAllOf 退掉整个属主的订阅', $ran === ['a', 'b'], '实际 ' . implode(',', $ran));
    if (is_file($isoLog)) {
        unlink($isoLog);
    }
} finally {
    Errors::setDebug($debugWas);
}

// ⑥ 两条守卫都跑在子进程里（会 exit(3)）
fresh($log);
[$code, $output] = runCase(__FILE__, 'watchbuild', null);
check('State · build 期订阅 ⇒ Fatal（A4 运行期守卫）', $code === 3, '实际 ' . $code . ' / ' . $output);
check('State · build 期订阅写了 Errors.log', str_contains(logText($log), 'initState'));

fresh($log);
[$code, $output] = runCase(__FILE__, 'cellunbound', null);
check('State · 未绑定的 Cell ⇒ Fatal（不留下"设了没用"的值）', $code === 3, '实际 ' . $code . ' / ' . $output);
check('State · 未绑定 Cell 写了 Errors.log', str_contains(logText($log), 'State::cell()'));

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉔ Commands：参数 / 注册表 / 冒泡链第 ① 环（§11.4.3）──

check(
    'Commands · arg 不是裸 int（int id 与 string key 各有一个槽）',
    CommandArg::none()->i === 0 && CommandArg::none()->s === ''
        && CommandArg::id(7)->i === 7 && CommandArg::id(7)->s === ''
        && CommandArg::key('nav.users')->s === 'nav.users' && CommandArg::key('nav.users')->i === 0
);

$echo = new EchoCommands();
$router = CommandRouter::make()->on('user.del', $echo);
check('Commands · on() 返回同一个注册表（可链式，不每步 new）', $router->names() === ['user.del']);
check(
    'Commands · dispatch 把名字与参数原样递给 handler',
    $router->dispatch('user.del', CommandArg::id(42)) && $echo->seen === [['user.del', 42, '']]
);

// handler 说"不是我管" ⇒ 视为没接（调用方继续第 ④ 环）
$picky = new PickyCommands('nav.users');
$pickyRouter = CommandRouter::make()->on('nav.users', $picky);
check(
    'Commands · handler 返回 false ⇒ 视为没接（继续冒泡的语义）',
    !$pickyRouter->dispatch('nav.signup', CommandArg::none())
);

// 没人接 ⇒ Diagnostic 级上报，**不静默**（菜单项亮了但点了什么都没发生最难查）
fresh($log);
$ringBefore = count(Errors::recentDiagnostics());
check('Commands · 未注册的名字 ⇒ 返回 false 且不中止', !$pickyRouter->dispatch('ghost.cmd', CommandArg::none()));
check(
    'Commands · 未注册 ⇒ 进了 Diagnostic 环形缓冲（不是静默）',
    count(Errors::recentDiagnostics()) === $ringBefore + 1
);

// 冒泡链第 ① 环：State 默认"不接"
check('Commands · State 默认不接命令（不接是常态，不做成抽象）', (new CommandWidget())->createState()->handleCommand('x', CommandArg::none()) === false);

// 第 ① 环接了 ⇒ 不需要走到注册表
fresh($log);
$cmdElement = (new CommandWidget())->createElement();
$cmdElement->mount(null, $context);
$cmdState = $cmdElement->state();
$taken = $cmdState->handleCommand('widget.echo', CommandArg::id(3));
$skipped = $cmdState->handleCommand('other.thing', CommandArg::none());
check(
    'Commands · State 接管自己的命令、其余放行（冒泡链有明确返回值）',
    $taken && !$skipped && $cmdState->handled === ['widget.echo', 'other.thing']
);
$cmdElement->unmount();

// 同名重复注册 ⇒ Fatal（子进程）
fresh($log);
[$code, $output] = runCase(__FILE__, 'cmdup', null);
check('Commands · 同名重复注册 ⇒ Fatal（不静默覆盖）', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Commands · 重复注册写了 Errors.log', str_contains(logText($log), '重复注册'));

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉕ App.Frame：flush → layout → paint → present（§5.2）──

$surface = new SurfaceProbe();
$surface->resize(320, 240, 1.0);
$frame = new Frame($surface);

// setState **不立即重建**，Frame.run 才消费脏标记（§5.2 裁决 3）
// ★ 根必须是**有盒**的节点（Column）：Frame 在 flush 之后才拿 renderObject，
//   拿不到就直接返回 —— 这是设计如此（结构根无从排起），不是 bug。
$rootElement = Column::make()->children([new TickerWidget()])->createElement();
$rootElement->mount(null, $context);
$tickerElement = $rootElement->childAt(0);
check('Frame · 挂载即建出子树', $tickerElement !== null && $tickerElement->childCount() === 1);
$ticker = $tickerElement?->state();
check('Frame · 挂载时 State 只建一次', $ticker !== null && $ticker->builds === 1);
$firstChild = $tickerElement?->childAt(0)?->widget();
check(
    'Frame · 首帧 build 的产物来自 State 初值',
    $firstChild instanceof LeafProbe && $firstChild->width === 0.0
);

$ticker?->tick(); // 只标脏
check(
    'Frame · setState 之后、Frame 之前 ⇒ 还没重建',
    $ticker !== null && $ticker->builds === 1 && $ticker->needsBuild()
);

$frame->run($rootElement, BoxConstraints::tight(new Size(320.0, 240.0)), 16.0);
check(
    'Frame · 帧末 flush 重建了脏节点',
    $ticker !== null && $ticker->builds === 2 && !$ticker->needsBuild()
);
$secondChild = $tickerElement?->childAt(0)?->widget();
check(
    'Frame · 重建后子树换成新产物（setState → flush → 新 widget）',
    $secondChild instanceof LeafProbe && $secondChild->width === 1.0,
    (string) ($secondChild instanceof LeafProbe ? $secondChild->width : -1.0)
);

// 时间戳是**注入**的，不读环境时钟（§5.2 裁决 2 ⇒ G3 的 golden 基础）
check('Frame · beginFrame 收到注入的时间戳', $surface->lastTimestamp === 16.0);
check('Frame · beginFrame / endFrame 各一次（不重不漏）', $surface->frames === 1 && $surface->ends === 1);

// 脏边界：没脏的节点不该被重建
$quietElement = (new QuietWidget())->createElement();
$quietElement->mount(null, $context);
$quiet = $quietElement->state();
$frame->run($quietElement, BoxConstraints::tight(new Size(320.0, 240.0)), 32.0);
check('Frame · 没脏的节点不重建（flush 有脏边界，不是每帧全树）', $quiet->builds === 1);

// layout + paint 真的跑了：一棵 Column[Text] 有墨上图
$columnElement = Column::make()->children([Text::make('Hi')])->createElement();
$columnElement->mount(null, $context);
$surface2 = new SurfaceProbe();
$surface2->resize(320, 240, 1.0);
$frame2 = new Frame($surface2);
$frame2->run($columnElement, BoxConstraints::tight(new Size(320.0, 240.0)), 48.0);
check(
    'Frame · layout → paint → present 一路通到表面（画布上有墨）',
    $surface2->canvas !== null && inkCount($surface2->canvas) > 0,
    '墨点数 ' . (string) ($surface2->canvas !== null ? inkCount($surface2->canvas) : -1)
);

// ── ㉖ Routing：RouteTable（数据）+ Offstage（保状态，§12.1 第 4/7 条）──

$table = RouteTable::make()
    ->route('users', static fn (): Widget => new LeafProbe(1.0))
    ->route('signup', static fn (): Widget => new LeafProbe(2.0));
check(
    'Routing · route() 链式 + names() 可枚举（devtools 列举的输入）',
    $table->names() === ['users', 'signup']
);
$built = $table->build('users');
check('Routing · build() 调工厂造页', $built instanceof LeafProbe && $built->width === 1.0);
check('Routing · 未命中 ⇒ null（开放域 ⇒ 由调用方 default 处理）', $table->build('ghost') === null);
check('Routing · has() 与 names() 一致', $table->has('users') && !$table->has('ghost'));

fresh($log);
[$code, $output] = runCase(__FILE__, 'routedup', null);
check('Routing · 同名路由重复注册 ⇒ Fatal（不静默覆盖）', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Routing · 重复注册写了 Errors.log', str_contains(logText($log), '已注册'));

// ① Offstage 三档里的"保留"档：切走再切回，State 还是同一个
$offstage = Offstage::make(new CounterWidget());
$offElement = $offstage->createElement();
$offElement->mount(null, $context);
$hidden = $offElement->childAt(0)?->state();
check('Routing · 可见 ⇒ 子层在树里', $offElement->childCount() === 1);
check('Routing · 挂载即建出 State', $hidden !== null);

$hidden?->bump(); // 标脏，先不重建
$updated = Offstage::make($offstage->child(), false);
$offElement->update($updated);
check('Routing · 隐藏 ⇒ 子层不在树里（不 layout / 不 paint / flush 走不到）', $offElement->childCount() === 0);
check('Routing · 隐藏**不卸载**（State 没被 dispose）', $hidden !== null && !$hidden->disposed);

// 隐藏期间 Frame 不该重建它 —— 否则"跳过 layout"是假的
$frame->run($offElement, BoxConstraints::tight(new Size(320.0, 240.0)), 64.0);
check('Routing · 隐藏期间的 Frame 走不到它（脏标记留着）', $hidden !== null && $hidden->needsBuild());

// 切回来 ⇒ 同一个 State（§12.3"保留"档的全部意义）
$backElement = Offstage::make($offstage->child(), true);
$offElement->update($backElement);
$restored = $offElement->childAt(0)?->state();
check('Routing · 切回来 ⇒ State 是同一个对象（状态没丢）', $restored === $hidden);
// ★ 隐藏期间攒下的脏标记在**切回来那一刻就兑现**（update 内部 settle），
//   不等下一帧、也不用谁手动 rebuild。这正是"切回来不是旧画面"的机制保证：
//   若改成"留着等 Frame"，帧还没跑时画面就是旧的 —— 而且是静默的。
check(
    'Routing · 切回来 ⇒ 隐藏期间攒的 setState 立即兑现',
    $hidden !== null && !$hidden->needsBuild() && $hidden->builds === 2 && $hidden->bumps === 1,
    'builds=' . (string) ($hidden?->builds ?? -1) . ' bumps=' . (string) ($hidden?->bumps ?? -1)
);

// ② 另一种"消失"是丢弃：整个 Offstage 被卸载 ⇒ State 必须 dispose（不许泄漏）
//    ★ 先可见再隐藏：隐藏子层拿不到引用（那正是"树里没有它"的证明），
//      所以要在它还看得见时把 State 抓在手里。
$doomed = Offstage::make(new CounterWidget());
$doomElement = $doomed->createElement();
$doomElement->mount(null, $context);
$doomState = $doomElement->childAt(0)?->state();
$offElement->update(Offstage::make($doomed->child(), false));
$doomElement->update(Offstage::make($doomed->child(), false));
$doomElement->unmount();
check('Routing · 隐藏页被卸载 ⇒ State 仍走 dispose（不漏）', $doomState !== null && $doomState->disposed);

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉗ ThemeScope：面覆盖（§2446 / §3171）──

// ① 子树看到的是覆盖后的主题，外层不受影响
$lightRoot = ThemeScope::make(ThemeData::light(), new ThemeSpy())->createElement();
$lightRoot->mount(null, $context);
$lightSpy = $lightRoot->childAt(0)?->state();
check('ThemeScope · 外层是浅色 ⇒ 子层看到浅色', $lightSpy !== null && !$lightSpy->sawDark);

$darkRoot = ThemeScope::make(ThemeData::dark(), new ThemeSpy())->createElement();
$darkRoot->mount(null, $context);
$darkSpy = $darkRoot->childAt(0)?->state();
check('ThemeScope · 子树看到覆盖后的暗色', $darkSpy !== null && $darkSpy->sawDark);
check('ThemeScope · 覆盖不外溢（外层 ctx 仍是浅色）', !$context->theme()->isDark());

// ② 嵌套 ⇒ 最内层赢，没有特异性权重（§2446 原话"谁生效查父链"）
$nested = ThemeScope::make(
    ThemeData::light(),
    ThemeScope::make(ThemeData::dark(), new ThemeSpy()),
)->createElement();
$nested->mount(null, $context);
$nestedSpy = $nested->childAt(0)?->childAt(0)?->state();
check('ThemeScope · 嵌套 ⇒ 最内层赢（不算特异性权重）', $nestedSpy !== null && $nestedSpy->sawDark);

// ③ 切换主题 ⇒ 子层换表。**两种兑现时机**（Element::applyContext 的注释写清了）：
//    有状态节点只标脏、等帧末；无状态节点立即重建。两种都在这儿钉住。
$scopedChild = new ThemeSpy(); // Widget 不可变 ⇒ 复用一个实例（换主题不换控件）
$switchRoot = ThemeScope::make(ThemeData::light(), $scopedChild)->createElement();
$switchRoot->mount(null, $context);
$switchSpy = $switchRoot->childAt(0)?->state();
check('ThemeScope · 切换前是浅色', $switchSpy !== null && !$switchSpy->sawDark);
$switchRoot->update(ThemeScope::make(ThemeData::dark(), $scopedChild));
check(
    'ThemeScope · 切主题 ⇒ 有状态子层先只标脏（§5.2 裁决 3，不在事件中途重建）',
    $switchSpy !== null && $switchSpy->needsBuild() && $switchSpy->builds === 1
);
$switchRoot->settle(); // 帧末：与 Frame.run 走同一条 settle()
check(
    'ThemeScope · 帧末兑现 ⇒ 子层看到暗色，且 State 没换（切主题不丢状态）',
    $switchSpy !== null && $switchSpy->sawDark && $switchSpy->builds === 2
        && $switchRoot->childAt(0)?->state() === $switchSpy
);

// ④ 无状态控件**立即**兑现：它的盒是按 ctx 算的，不重算就是"换表不换色"
$scopedButton = Button::make('确定');
$buttonScope = ThemeScope::make(ThemeData::light(), $scopedButton)->createElement();
$buttonScope->mount(null, $context);
$lightBox = $buttonScope->childAt(0)?->renderObject();
check('ThemeScope · scope 内按钮先取到浅色表', $lightBox?->boxStyle()->background !== null);
$buttonScope->update(ThemeScope::make(ThemeData::dark(), $scopedButton));
$darkBox = $buttonScope->childAt(0)?->renderObject();
check(
    'ThemeScope · 切主题 ⇒ 无状态控件的盒立即重算（不是下一帧）',
    $darkBox !== null && $darkBox !== $lightBox
        && $darkBox->boxStyle()->background !== $lightBox->boxStyle()->background
);

// ⑤ 端到端：同一按钮在两份表下取到不同底色（§15.7 纪律 3 只解析一次）
$darkButton = Button::make('确定')->createRenderObject(
    (new BuildContext(320.0, 240.0, ThemeData::dark(), $context->metrics()))
);
$lightButton = Button::make('确定')->createRenderObject(
    (new BuildContext(320.0, 240.0, ThemeData::light(), $context->metrics()))
);
check(
    'ThemeScope · 同一按钮在两份表下取到不同底色（§15.7 纪律 3 只解析一次）',
    $darkButton->boxStyle()->background !== $lightButton->boxStyle()->background
);

// ⑤ ThemeScope 自己是结构节点：拿不到盒样式糖方法（§15.7）
check(
    'ThemeScope · 结构节点不碰绘制样式',
    !method_exists(ThemeScope::class, 'background')
);

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉘ PageHost：换页 = 旧页 Offstage 化（§12.1 第 4/7 条）──

$nav = new NavStore();
$navTable = RouteTable::make()
    ->route('users', static fn (): Widget => Text::make('用户列表'))
    ->route('signup', static fn (): Widget => Text::make('注册'));
$host = PageHost::make()->source($nav)->select(static fn (NavStore $s): string => $s->page())->table($navTable);
$hostElement = $host->createElement();
$hostElement->mount(null, $context);

/** @var PageHostState $hostState */
$hostState = $hostElement->state();
check('PageHost · 首帧选出当前页', $hostState !== null && $hostState->page() === 'users');
check('PageHost · 初始就两页都挂上（各一个 Offstage）', $hostElement->childCount() === 1);
$usersOffstage = $hostElement->childAt(0)?->childAt(0);
$signupOffstage = $hostElement->childAt(0)?->childAt(1);
check(
    'PageHost · 当前页可见、另一页隐藏',
    $usersOffstage instanceof OffstageElement && $usersOffstage->childCount() === 1
        && $signupOffstage instanceof OffstageElement && $signupOffstage->childCount() === 0
);

// 换页：旧页隐藏但**不卸载**（State 留着），新页可见
$nav->go('signup');
check('PageHost · notify 后选中新页', $hostState !== null && $hostState->page() === 'signup');
$hostElement->settle();
check(
    'PageHost · 换页 ⇒ 旧页进 Offstage、新页可见',
    $hostElement->childAt(0)?->childAt(0)?->childCount() === 0
        && $hostElement->childAt(0)?->childAt(1)?->childCount() === 1
);

// ★ 每页只造一次：切回来是同一个 Widget 实例（否则"保留状态"是空话）
$firstUsers = $hostElement->childAt(0)?->childAt(0);
$nav->go('users');
$hostElement->settle();
$backUsers = $hostElement->childAt(0)?->childAt(0);
check(
    'PageHost · 切回来 ⇒ 页面是同一个实例（工厂没有二次调用）',
    $firstUsers !== null && $firstUsers === $backUsers && $hostState !== null && $hostState->cachedCount() === 2,
    '缓存 ' . (string) ($hostState?->cachedCount() ?? -1) . ' 页'
);

// 同值 notify 被门控挡住（§11.3.3）：别的字段变了，页名没变 ⇒ 不换页
$notifiesBefore = $nav->notifyCount();
$nav->touchOtherField();
check(
    'PageHost · 同值 notify 被门控挡住（两次 select 输出相等）',
    $hostState !== null && !$hostState->needsBuild()
);
// 反证：通知真的到了（否则"门控挡下"与"订阅没联上"长得一样）
check(
    'PageHost · 被门控挡的通知仍到达订阅者（排除"订阅没联上"）',
    $nav->notifyCount() === $notifiesBefore + 1
);

// 未命中：开放域 ⇒ 什么都不显示，不崩（§12.1 第 2 条）
$nav->go('ghost');
$hostElement->settle();
$ghostSlot = $hostElement->childAt(0)?->childAt(2);
check(
    'PageHost · 未命中的页名 ⇒ 末槽是可见的 NoPage（不崩、不静默错误）',
    $ghostSlot instanceof OffstageElement
        && $ghostSlot->childCount() === 1
        && $ghostSlot->childAt(0)?->widget() instanceof NoPage
);

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉙ Application：组合根（§4.5 / §11.4.3 全链）──

$surface3 = new SurfaceProbe();
$surface3->resize(320, 240, 1.0);
$app = new EchoApplication();
$app->attach($surface3, BoxConstraints::tight(new Size(320.0, 240.0)));

check('Application · buildRoot 被调一次（组合根只组合）', $app->kerned);
check('Application · 根 Element 挂上且有盒', $app->root() !== null && $app->rootBox() !== null);
check('Application · attach 即跑首帧（表面收到 beginFrame）', $surface3->frames === 1);
check('Application · title() 由应用给', $app->title() === '冒烟应用');

// 平台驱动后续帧：每调一次 surface 多一帧
$app->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$app->frame(32.0, BoxConstraints::tight(new Size(320.0, 240.0)));
check('Application · frame() 由平台驱动（每调一次一帧）', $surface3->frames === 3 && $surface3->ends === 3);

// ★ 命令派发链（§11.4.3 四环齐全）
//   第 ①② 环：树内冒泡；第 ③ 环：CommandRouter；第 ④ 环：谁都没接 ⇒ Diagnostic
check(
    'Application · 注册表里的命令接得走（第 ③ 环）',
    $app->dispatch('echo.hello') === true
);

// ★ 顺序：树内冒泡**优先于**注册表。同一个名字两边都能接 ⇒ 树内先接，
//   注册表的 handler 一次都不该被调到。顺序反了页面就永远收不到自己的命令。
$ordered = new EchoApplication();
$ordered->withCommandWidget = true;
$ordered->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
check(
    'Application · 树内冒泡优先于注册表（页面自己最知道命令是不是它的）',
    $ordered->dispatch('widget.echo') === true
);
$routerHits = 0;
$ordered->root()?->visitChildren(function (\Drift\Widget\Element $e) use (&$routerHits): void {
    $st = $e instanceof \Drift\Widget\StatefulElement ? $e->state() : null;
    if ($st instanceof \Drift\Dev\Smoke\Commands\CommandState) {
        $routerHits = count($st->handled);
    }
});
check(
    'Application · 树内接走后注册表没被调（handler 的 seen 不增长）',
    $routerHits === 1
);
check(
    'Application · 链上谁都没接 ⇒ 返回 false + Diagnostic（不静默）',
    $app->dispatch('nobody.wants.this') === false
);
check(
    'Application · 没人接的命令写进环形缓冲',
    str_contains((string) json_encode(\Drift\Errors::recentDiagnostics()), 'nobody.wants.this')
);

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉚ drift-preview：无头渲染一页到 SVG（§16.3 / §A.5）──

$previewSurface = new SvgSurface();
$preview = new Renderer($previewSurface, $context->metrics(), ThemeData::light());
$svg = $preview->render(new DemoPage(), 320.0, 240.0, 1.0);
check(
    'Preview · SVG 有内容（像素 run > 0，不是空白页）',
    substr_count($svg, '<rect x=') > 0,
    'run 数 ' . (string) substr_count($svg, '<rect x=')
);
check(
    'Preview · 根盒排开到视口尺寸（320 × 240 紧约束）',
    $preview->effectiveRoot()?->size()->width === 320.0,
    (string) ($preview->effectiveRoot()?->size()->width ?? -1.0)
);
// ★ 组合节点对渲染树透明：页面根是 StatelessWidget（无盒），但其下的 Column
//   必须被外层视口收到 —— 少了这一步画面整块消失且不报错（0×0 在 tight 下合法）
check(
    'Preview · 组合根（无盒）下的渲染盒被外层收到（中间层透明）',
    $preview->effectiveRoot()?->size()->height === 240.0
);
check(
    'Preview · dpr=2 ⇒ 设备像素翻倍（§6.1 唯一量化点）',
    substr_count($preview->render(new DemoPage(), 320.0, 240.0, 2.0), 'viewBox="0 0 640 480"') === 1
);

// ★ 回归：应用根是**组合节点**（`StatelessWidget`，自己没盒、底下才有）
//   ⇒ 以前 `Frame::run` 拿 `renderObject()` 得到 null ⇒ 直接 return
//   ⇒ **整屏空白且不报错**（C6 只查上界，什么都不画也不算违反约束）。
//   现在走 `effectiveBox()`，中间层对渲染树透明。
$compositeSurface = new SurfaceProbe();
$compositeSurface->resize(320, 240, 1.0);
$compositeApp = new CompositeRootApp();
$compositeApp->attach($compositeSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
check(
    'Application · 组合根（无盒）仍能排到视口尺寸',
    $compositeApp->rootBox()?->size()->width === 320.0,
    (string) ($compositeApp->rootBox()?->size()->width ?? -1.0)
);
check(
    'Application · 组合根的画面有墨（不是静默空白）',
    $compositeSurface->canvas !== null && inkCount($compositeSurface->canvas) > 0,
    '墨点数 ' . (string) ($compositeSurface->canvas !== null ? inkCount($compositeSurface->canvas) : -1)
);

// ── ㉛ apps/hello：第一个真实消费者（§4.5 / §A.1）──

$hello = new HelloApplication();
$helloSurface = new SurfaceProbe();
$helloSurface->resize(320, 240, 1.0);
$hello->attach($helloSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
check('apps/hello · 应用只写 buildRoot/title/commands（§4.5）', $hello->title() === 'Hello Drift');
check('apps/hello · 无平台也能挂载（表面是注入的，§7.2 裁决 4）', $hello->root() !== null);
check(
    'apps/hello · 屏幕中央有「Hello Drift」（画出墨了）',
    $helloSurface->canvas !== null && inkCount($helloSurface->canvas) > 0,
    '墨点数 ' . (string) ($helloSurface->canvas !== null ? inkCount($helloSurface->canvas) : -1)
);
check(
    'apps/hello · 根排满视口（Center 撑满紧约束）',
    $hello->rootBox()?->size()->width === 320.0 && $hello->rootBox()?->size()->height === 240.0
);

// preview 的 --app 路径：Application 也能无头出图（§12.2）
$helloSvgSurface = new SvgSurface();
$helloSvg = (new Renderer($helloSvgSurface, $context->metrics(), ThemeData::light()))
    ->renderApp(new HelloApplication(), 320.0, 240.0, 1.0);
check(
    'apps/hello · drift-preview --app 出 SVG（应用可独立预览）',
    substr_count($helloSvg, '<rect x=') > 0,
    'run 数 ' . (string) substr_count($helloSvg, '<rect x=')
);

// ★ 回归：文本**必须吃画布变换**。`drawRect` 走 `toDevice()`（跟变换），
//   而 `drawParagraph` 曾直接用传入坐标（不跟）⇒ `Center` 布局算出的偏移
//   在 paint 时被丢掉 ⇒ 文字永远贴左上角，且墨点数一模一样（最难查的一档）。
$shiftCanvas = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$shiftCanvas->translate(50.0, 20.0);
$shiftPara = $context->metrics()->layout('Hi', TextStyle::of(14.0), INF, Scale::of(1.0));
$shiftList = new DisplayList();
(new RecordingCanvas($shiftList))->drawParagraph($shiftPara, Paint::fill(Color::black()), 0.0, 0.0);
$shiftList->replay($shiftCanvas);
$shiftBounds = inkBounds($shiftCanvas);
check(
    'Rendering · 文本吃画布平移（translate 之后墨迹整体挪位）',
    $shiftBounds !== null && $shiftBounds[0] >= 50 && $shiftBounds[2] >= 20,
    '包围盒 ' . ($shiftBounds === null ? 'null' : implode(',', $shiftBounds))
);

// ★ 回归：hello 的「Hello Drift」是**居中**的，不是贴着左上角
$helloBounds = inkBounds($helloSurface->canvas);
check(
    'apps/hello · 文字居中（Center 的偏移真被画出来）',
    $helloBounds !== null
        && abs(($helloBounds[0] + $helloBounds[1]) / 2 - 160) <= 4
        && abs(($helloBounds[2] + $helloBounds[3]) / 2 - 120) <= 4,
    '包围盒 ' . ($helloBounds === null ? 'null' : implode(',', $helloBounds))
);

fresh($log);
if (is_file($log)) {
    unlink($log);
}
