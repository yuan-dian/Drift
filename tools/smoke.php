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
use Drift\App\FocusManager;
use App\Hello\HelloApplication;
use Drift\App\Frame;
use Drift\Dev\Smoke\ErrorBoundary\BoomPage;
use Drift\Dev\Smoke\ErrorBoundary\BoomState;
use Drift\Dev\Smoke\ErrorBoundary\ConstructBoom;
use Drift\Dev\Smoke\ErrorBoundary\LayoutBoomBox;
use Drift\Dev\Smoke\ErrorBoundary\PaintBoomBox;
use Drift\Dev\Smoke\Frame\QuietWidget;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Smoke\Frame\TickerWidget;
use Drift\Dev\Smoke\Overlay\FlatBox;
use Drift\Dev\Preview\DemoPage;
use Drift\Dev\Preview\FormPage;
use Drift\Dev\Preview\Renderer;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\App\AccelApplication;
use Drift\Dev\Smoke\App\CompositeRootApp;
use Drift\Dev\Smoke\App\MenuApplication;
use Drift\Dev\Smoke\App\RecordingHandler;
use Drift\Dev\Smoke\App\TaskRow;
use Drift\Dev\Smoke\App\TaskRowJob;
use Drift\Dev\Smoke\App\SumJob;
use Drift\App\Job;
use Drift\App\SyncTaskHost;
use Drift\App\TaskHost;
use App\Users\UsersApplication;
use App\Users\UsersRoot;
use App\Users\Store\MemoryUserSource;
use Drift\Dev\Smoke\App\CursorApplication;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\App\NavApplication;
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
use Drift\Input\AcceleratorTable;
use Drift\Input\CursorShape;
use Drift\Input\FocusNode;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Layout\FlexAlign;
use Drift\Layout\Overflow;
use Drift\Layout\RenderAppBar;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderButton;
use Drift\Layout\RenderCenter;
use Drift\Layout\RenderDock;
use Drift\Layout\RenderErrorBoundary;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderFlexible;
use Drift\Layout\RenderPadding;
use Drift\Layout\RenderProxyBox;
use Drift\Layout\RenderMenuItem;
use Drift\Layout\RenderObject;
use Drift\Layout\RenderPopupPanel;
use Drift\Layout\RenderSpinner;
use Drift\Layout\RenderText;
use Drift\Layout\RenderTextField;
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
use Drift\Rendering\SkiaCanvas;
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
use Drift\Widget\AppBar;
use Drift\Widget\BoxWidget;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Dock;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Drag\DragTarget;
use Drift\Widget\Each;
use Drift\Widget\Element;
use Drift\Widget\ErrorBoundary;
use Drift\Widget\ErrorBoundaryElement;
use Drift\Widget\ErrorPlaceholder;
use Drift\Widget\Menu\ContextMenu;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Menu\MenuBar;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\PopupPanel;
use Drift\Widget\Key;
use Drift\Widget\NavButton;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\Spinner;
use Drift\Widget\SpinnerState;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;
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

    if ($case === 'dockoverflow') {
        // C5/W4：Dock 默认 Report ⇒ 条轨**合计**超出必须中止（每根各自合规，
        //   冲突在合计层暴露 —— 30+20 > 40）
        $dock = new RenderDock(Overflow::Report);
        $dock->setSlot('top', new SolidBox(new Size(100.0, 30.0), 100.0, 30.0));
        $dock->setSlot('bottom', new SolidBox(new Size(100.0, 20.0), 100.0, 20.0));
        $dock->setSlot('center', new SolidBox(new Size(10.0, 10.0), 10.0, 10.0));
        $dock->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
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

    if ($case === 'accelconflict') {
        // G2：同一个组合绑两次 ⇒ 响亮失败，不是后绑覆盖前绑（用户按了没反应且不报错）
        AcceleratorTable::make()
            ->bind(KeyChord::ctrl('R'), 'users.refresh')
            ->bind(KeyChord::ctrl('R'), 'users.reload');
    }

    if ($case === 'noboundary') {
        // O-12 A′ S3 的"无边界"半边：帧末异常沿途没有 ErrorBoundary 捕获
        //   ⇒ Fatal 序列（exit 3 + Errors.log）。**不吞** —— 吞了就是窗口冻结
        //   在上一帧且不报错（G2 明令禁止的半静默）。
        $ctx = new BuildContext(240.0, 80.0, ThemeData::light(), new DeterministicMetricsProvider(
            DeterministicFontManager::standard()->collection(),
            new DeterministicShaper(),
        ));
        $bad = BoomPage::make()->createElement();
        $bad->mount(null, $ctx); // 首次 build 成功
        $bad->state()?->boom();  // 标脏 ⇒ 帧末重建时抛
        (new Frame(new SurfaceProbe()))->run($bad, BoxConstraints::tight(new Size(240.0, 80.0)), 0.0);
        echo "不该到达这里\n";
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
function inkCount(RasterCanvas $canvas, ?Color $background = null): int
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

/** 把一个盒画到白底光栅上，数非背景像素（用来断言"插入符真的画上了"） */
function fieldInk(?RenderBox $box, int $width, int $height): int
{
    if ($box === null) {
        return 0;
    }

    $list = new DisplayList();
    $box->paint(new RecordingCanvas($list));

    $canvas = new RasterCanvas($width, $height, Color::white(), Scale::of(1.0));
    $list->replay($canvas);

    return inkCount($canvas);
}

/**
 * 盒在客户区的绝对原点（沿父链累加相对偏移）。
 *
 * 菜单 / 右键 / 拖放的测试要"点在某一行上"，而行的位置只能从盒树反推 ——
 * 坐标写死会在任何 padding 变动时静默失准。
 *
 * @return array{0: float, 1: float}
 */
function boxOrigin(RenderBox $box): array
{
    $x = 0.0;
    $y = 0.0;

    for ($node = $box; $node !== null; $node = $node->parent()) {
        $x += $node->offsetX();
        $y += $node->offsetY();
    }

    return [$x, $y];
}

// ── Skia 桥的假实现（⑨+1 conformance 夹具用）──────────────────────
// ★ 签名与 cpp-src/drift_skia.stub.php 逐字一致 —— 夹具测的必须就是 ABI 本身
//   （stub 注释原话："签名不一致时是链接期静默失败"）。
// ★ `use function` 是运行期解析：真桥只存在于 AOT 构建，解释器面从不加载
//   ⇒ SkiaCanvas 的调用落到这些假函数，参数进 $GLOBALS 记录 ——
//   "Skia 收到了什么"成为可断言的纯数据，不必每次构建 exe。
// ★ 两个开关只服务夹具：FAIL_CREATE 造"句柄 0"（H 用例）；
//   SABOTAGE 把 draw_rect 的 x 篡改一格（量具反例自检）。
if (!function_exists('drift_skia_canvas_create')) {
    function drift_skia_canvas_create(int $width, int $height, int $backgroundRgba): int
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['create', $width, $height, $backgroundRgba];
        return empty($GLOBALS['DRIFT_SMOKE_SKIA_FAIL_CREATE']) ? 1 : 0;
    }

    function drift_skia_canvas_destroy(int $canvas): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['destroy'];
    }

    function drift_skia_push_clip(int $canvas, float $x, float $y, float $w, float $h): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['push_clip', $x, $y, $w, $h];
    }

    function drift_skia_pop_clip(int $canvas): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['pop_clip'];
    }

    function drift_skia_draw_rect(
        int $canvas,
        float $x,
        float $y,
        float $w,
        float $h,
        float $radius,
        int $rgba,
        bool $stroke,
        float $strokeWidth,
    ): void {
        if (!empty($GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'])) {
            $x += 1.0; // 已知违规件：只服务量具反例自检（判据恒真 = 夹具空转）
        }
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_rect', $x, $y, $w, $h, $radius, $rgba, $stroke, $strokeWidth];
    }

    function drift_skia_draw_glyph(
        int $canvas,
        string $family,
        bool $bold,
        float $fontSize,
        float $penX,
        float $baselineY,
        int $codepoint,
        int $rgba,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_glyph', $family, $bold, $fontSize, $penX, $baselineY, $codepoint, $rgba];
    }

    function drift_skia_read_pixels(int $canvas): string
    {
        return '';
    }

    function drift_skia_font_ready(): bool
    {
        return true;
    }
}

// ── 假桥记录的访问器与换算（⑨+1 用）──────────────────────────────

/** 清空跨边界调用记录（每个用例前必调，否则用例互相污染） */
function skiaBridgeReset(): void
{
    $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] = [];
}

/** @return list<string> 按序取出调用操作名（create / draw_rect / push_clip …） */
function skiaCallOps(): array
{
    $ops = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        $ops[] = $call[0];
    }
    return $ops;
}

/** @return list<array> draw_rect 记录：[op, x, y, w, h, radius, rgba, stroke, strokeWidth] */
function skiaDrawRectCalls(): array
{
    $out = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        if ($call[0] === 'draw_rect') {
            $out[] = $call;
        }
    }
    return $out;
}

/** @return list<array> push_clip 记录：[op, x, y, w, h] */
function skiaClipCalls(): array
{
    $out = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        if ($call[0] === 'push_clip') {
            $out[] = $call;
        }
    }
    return $out;
}

/**
 * 桥记录里的矩形 (x, y, w, h) → 软光栅墨迹 bbox（含端点）。
 *
 * 换算依据：rasterize 逐**像素中心**测试，整边矩形覆盖 [left, right)×[top, bottom)
 * ⇒ 墨迹像素 x ∈ [left, right−1]。设备矩形由 quantizeRect 产出（round 的产物，
 * 必为整数）⇒ 强转 int 无损。
 *
 * ★ 返回顺序与 `deviceInkBounds()` 同款（房规）：[left, right, top, bottom]（含端点）。
 *   ★ 踩过：第一版写成 [left, top, right, bottom]，8 条对照全线 FAIL ——
 *     夹具第一次跑就靠这个顺序错位自证了"判据真的在比数值"。
 */
function skiaRectToInk(array $rec): array
{
    $x = (int) $rec[1];
    $y = (int) $rec[2];
    return [$x, $x + (int) $rec[3] - 1, $y, $y + (int) $rec[4] - 1];
}

/** 墨迹 bbox 的调试串 */
function fmtInk(?array $ink): string
{
    return $ink === null ? 'null' : '[' . implode(',', $ink) . ']';
}

/**
 * 墨迹 bbox（含端点，尺寸无关版）："不等于透明"即墨。
 * `inkBounds()` 写死 320×240 且判据是 r<200（白底假设）—— 这里要任意尺寸 + 透明底。
 */
function deviceInkBounds(RasterCanvas $canvas): ?array
{
    $clear = Color::transparent();
    $minX = PHP_INT_MAX;
    $maxX = -1;
    $minY = PHP_INT_MAX;
    $maxY = -1;

    for ($y = 0; $y < $canvas->height(); $y++) {
        for ($x = 0; $x < $canvas->width(); $x++) {
            if (!$canvas->pixelAt($x, $y)->equals($clear)) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
    }

    return $maxX < 0 ? null : [$minX, $maxX, $minY, $maxY];
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

// ⑨+1 Skia 后端 conformance：drawRect / pushClip 与软光栅逐像素同构
//   （§7.3 的 M1 后端之二。hello 夹具只有文字 ⇒ 矩形 / 裁剪路径此前**零视觉覆盖**）
// ★ 覆盖：A 填充 dpr=1 · B dpr=2 小数边界+量化锚点 · C 平移在逻辑空间叠加 ·
//   D save/restore 偏移栈 · E1 pushClip 几何 · E2 popClip 释放裁剪 ·
//   E3 popClip 不动偏移（两后端同解）· E4 兄弟干扰（TextField 序列后兄弟保住
//   容器偏移）· F 描边宽度不乘 dpr · G 零宽早退 · H 句柄 0 静默跳过。
//
// ★ 在解释器面做，不构建 exe：真桥（cpp-src/drift_skia.cc）只存在于 AOT 构建，
//   而 `use function` 是**运行期**解析 ⇒ 助手区的假桥接管调用并记录每一次跨边界
//   参数 —— "Skia 收到了什么"成为可断言的纯数据。
// ★ 判据是**跨后端几何同构**，不是像素 golden：两后端的抗锯齿范围与描边实现
//   **有意不同**（SkiaCanvas 类注释明写两条差异），像素级互比没有意义。能互比的
//   是设备矩形 —— 软光栅 rasterize 逐**像素中心**测试，整边矩形覆盖
//   [left, right)×[top, bottom) ⇒ 墨迹 bbox（含端点）= (left, top, right−1, bottom−1)。
// ★ 量化规则本身由 ⑦ 钉住（round(logical×dpr)、相邻矩形共享像素边）；这里钉的是
//   "Skia 路径确实走 Scale::quantizeRect 这一个量化点"。
fresh($log);

// ── A. 填充 · dpr=1：桥收到的设备矩形 == 软光栅墨迹 bbox ──
skiaBridgeReset();
$listA = new DisplayList();
(new RecordingCanvas($listA))->drawRect(new Rect(3.0, 4.0, 10.0, 8.0), Paint::fill($red));
$rasterA = new RasterCanvas(24, 20, null, Scale::of(1.0));
$skiaA = new SkiaCanvas(24, 20, null, Scale::of(1.0));
$listA->replay($rasterA);
$listA->replay($skiaA);
$inkA = deviceInkBounds($rasterA);
$rectsA = skiaDrawRectCalls();
$expectA = count($rectsA) === 1 ? skiaRectToInk($rectsA[0]) : null;
check(
    'Skia conformance · A 填充：桥恰好收到一次 draw_rect（dpr=1）',
    count($rectsA) === 1,
    (string) count($rectsA)
);
check(
    'Skia conformance · A 填充：设备矩形 == 软光栅墨迹 bbox（dpr=1）',
    $inkA === $expectA,
    'ink=' . fmtInk($inkA) . ' 桥收到=' . fmtInk($expectA)
);

// ── B. 填充 · dpr=2 小数边界：量化在逻辑空间一次做完 ──
skiaBridgeReset();
$listB = new DisplayList();
(new RecordingCanvas($listB))->drawRect(new Rect(3.25, 4.75, 10.5, 8.25), Paint::fill($red));
$rasterB = new RasterCanvas(48, 40, null, Scale::of(2.0));
$skiaB = new SkiaCanvas(48, 40, null, Scale::of(2.0));
$listB->replay($rasterB);
$listB->replay($skiaB);
$inkB = deviceInkBounds($rasterB);
$rectsB = skiaDrawRectCalls();
$expectB = count($rectsB) === 1 ? skiaRectToInk($rectsB[0]) : null;
check(
    'Skia conformance · B 填充：dpr=2 小数边界的量化口径两后端一致',
    $inkB === $expectB,
    'ink=' . fmtInk($inkB) . ' 桥收到=' . fmtInk($expectB)
);
check(
    'Skia conformance · B 量化锚点：(3.25,4.75,10.5,8.25)×2 ⇒ 设备 (7,10,21,16)（half-up）',
    count($rectsB) === 1
        && $rectsB[0][1] === 7.0 && $rectsB[0][2] === 10.0
        && $rectsB[0][3] === 21.0 && $rectsB[0][4] === 16.0,
    count($rectsB) === 1
        ? '桥收到 x=' . $rectsB[0][1] . ' y=' . $rectsB[0][2] . ' w=' . $rectsB[0][3] . ' h=' . $rectsB[0][4]
        : 'draw_rect 次数=' . count($rectsB)
);

// ── C. 平移叠加在逻辑空间：先加偏移再取整（不是先取整后加） ──
// translate(0.3,0.3) + rect(0.3,0.3,10,10)、dpr=2 ⇒ left = round(1.2) = 1；
// 若"先量化后加偏移"会得 round(0.6)+round(0.6) = 2 —— 本用例专门分开这两条路。
skiaBridgeReset();
$listC = new DisplayList();
$recC = new RecordingCanvas($listC);
$recC->translate(0.3, 0.3);
$recC->drawRect(new Rect(0.3, 0.3, 10.0, 10.0), Paint::fill($red));
$rasterC = new RasterCanvas(48, 40, null, Scale::of(2.0));
$skiaC = new SkiaCanvas(48, 40, null, Scale::of(2.0));
$listC->replay($rasterC);
$listC->replay($skiaC);
$inkC = deviceInkBounds($rasterC);
$rectsC = skiaDrawRectCalls();
$expectC = count($rectsC) === 1 ? skiaRectToInk($rectsC[0]) : null;
check(
    'Skia conformance · C 平移：先加偏移再取整，两后端一致',
    $inkC === $expectC,
    'ink=' . fmtInk($inkC) . ' 桥收到=' . fmtInk($expectC)
);
check(
    'Skia conformance · C 锚点：left = round((0.3+0.3)×2) = 1（先量化后加会得 2）',
    count($rectsC) === 1 && $rectsC[0][1] === 1.0 && $rectsC[0][2] === 1.0,
    count($rectsC) === 1 ? '桥收到 x=' . $rectsC[0][1] . ' y=' . $rectsC[0][2] : 'draw_rect 次数=' . count($rectsC)
);

// ── D. save/restore 偏移栈：restore 必须把偏移归位（不是残留） ──
// P1 画在 translate 作用域内 ⇒ 落 (20,4)；P2 画在 restore 之后 ⇒ 必须回到 (4,4)。
// restore 失效（偏移残留）会让 P2 也落 (20,4) ⇒ 与 P1 重叠 ⇒ 软光栅墨迹从 128 塌成 64
//   —— 这个 inkCount 判据两个方向**可区分**（第一版设计成"三块墨"不可区分：P2 无论
//   落回 P0 还是残留到 P1，计数都是 128，纯坏判据，smoke 当场把它揪出来了）。
skiaBridgeReset();
$listD = new DisplayList();
$recD = new RecordingCanvas($listD);
$recD->save();
$recD->translate(16.0, 0.0);
$recD->drawRect(new Rect(4.0, 4.0, 8.0, 8.0), Paint::fill($red));       // P1 = (20,4)
$recD->restore();
$recD->drawRect(new Rect(4.0, 4.0, 8.0, 8.0), Paint::fill($red));       // P2 = (4,4)（restore 归零）
$rasterD = new RasterCanvas(40, 24, null, Scale::of(1.0));
$skiaD = new SkiaCanvas(40, 24, null, Scale::of(1.0));
$listD->replay($rasterD);
$listD->replay($skiaD);
$rectsD = skiaDrawRectCalls();
check(
    'Skia conformance · D save/restore：桥收到两次 draw_rect',
    count($rectsD) === 2,
    (string) count($rectsD)
);
check(
    'Skia conformance · D 平移生效：第一块 == (20,4)',
    count($rectsD) === 2 && $rectsD[0][1] === 20.0 && $rectsD[0][2] === 4.0,
    count($rectsD) === 2 ? '桥收到 x=' . $rectsD[0][1] . ' y=' . $rectsD[0][2] : '次数=' . count($rectsD)
);
check(
    'Skia conformance · D restore 归零：第二块 == (4,4)（偏移残留会变成 (20,4)）',
    count($rectsD) === 2 && $rectsD[1][1] === 4.0 && $rectsD[1][2] === 4.0,
    count($rectsD) === 2 ? '桥收到 x=' . $rectsD[1][1] . ' y=' . $rectsD[1][2] : '次数=' . count($rectsD)
);
$inkD = inkCount($rasterD, Color::transparent());
check(
    'Skia conformance · D 软光栅两块墨互不重叠（inkCount=128；restore 失效会塌成 64）',
    $inkD === 128,
    'inkCount=' . $inkD
);
check(
    'Skia conformance · D 软光栅定点：两块位置都有墨',
    $rasterD->pixelAt(5, 5)->equals($red) && $rasterD->pixelAt(21, 5)->equals($red),
    'p(5,5)=' . ($rasterD->pixelAt(5, 5)->equals($red) ? 'red' : 'clear')
        . ' p(21,5)=' . ($rasterD->pixelAt(21, 5)->equals($red) ? 'red' : 'clear')
);

// ── E1. pushClip 几何：桥收到的裁剪矩形 == 被裁后的墨迹 bbox ──
skiaBridgeReset();
$listE1 = new DisplayList();
$recE1 = new RecordingCanvas($listE1);
$recE1->save();
$recE1->pushClip(new Rect(2.0, 2.0, 6.0, 6.0));
$recE1->drawRect(new Rect(0.0, 0.0, 20.0, 20.0), Paint::fill($red));
$recE1->popClip();
$recE1->restore();
$rasterE1 = new RasterCanvas(24, 20, null, Scale::of(1.0));
$skiaE1 = new SkiaCanvas(24, 20, null, Scale::of(1.0));
$listE1->replay($rasterE1);
$listE1->replay($skiaE1);
$inkE1 = deviceInkBounds($rasterE1);
$clipsE1 = skiaClipCalls();
$expectE1 = count($clipsE1) === 1 ? skiaRectToInk($clipsE1[0]) : null;
check(
    'Skia conformance · E1 pushClip：裁剪矩形 == 被裁后的墨迹 bbox',
    $inkE1 === $expectE1,
    'ink=' . fmtInk($inkE1) . ' 裁剪=' . fmtInk($expectE1)
);
check(
    'Skia conformance · E1 配对：push_clip / draw_rect / pop_clip 各一次（外加 create）',
    skiaCallOps() === ['create', 'push_clip', 'draw_rect', 'pop_clip'],
    implode('→', skiaCallOps())
);

// ── E2. popClip 真的释放裁剪：释放后画的矩形能落在裁剪区外 ──
skiaBridgeReset();
$listE2 = new DisplayList();
$recE2 = new RecordingCanvas($listE2);
$recE2->save();
$recE2->pushClip(new Rect(2.0, 2.0, 6.0, 6.0));
$recE2->popClip();
$recE2->drawRect(new Rect(16.0, 16.0, 2.0, 2.0), Paint::fill($red));
$recE2->restore();
$rasterE2 = new RasterCanvas(24, 20, null, Scale::of(1.0));
$skiaE2 = new SkiaCanvas(24, 20, null, Scale::of(1.0));
$listE2->replay($rasterE2);
$listE2->replay($skiaE2);
$inkE2 = deviceInkBounds($rasterE2);
$rectsE2 = skiaDrawRectCalls();
$expectE2 = count($rectsE2) === 1 ? skiaRectToInk($rectsE2[0]) : null;
check(
    'Skia conformance · E2 popClip 释放裁剪：矩形落在裁剪区外且两后端一致',
    $inkE2 === $expectE2,
    'ink=' . fmtInk($inkE2) . ' 桥收到=' . fmtInk($expectE2)
);

// ── F. 描边：宽度不乘 dpr；外圈墨迹 bbox == 设备矩形 ──
skiaBridgeReset();
$listF = new DisplayList();
(new RecordingCanvas($listF))->drawRect(new Rect(1.0, 1.0, 10.0, 10.0), Paint::stroke($red, 2.0));
$rasterF = new RasterCanvas(48, 40, null, Scale::of(2.0));
$skiaF = new SkiaCanvas(48, 40, null, Scale::of(2.0));
$listF->replay($rasterF);
$listF->replay($skiaF);
$inkF = deviceInkBounds($rasterF);
$rectsF = skiaDrawRectCalls();
$expectF = count($rectsF) === 1 ? skiaRectToInk($rectsF[0]) : null;
check(
    'Skia conformance · F 描边：外圈墨迹 bbox == 设备矩形（dpr=2）',
    $inkF === $expectF,
    'ink=' . fmtInk($inkF) . ' 桥收到=' . fmtInk($expectF)
);
check(
    'Skia conformance · F 描边宽度不乘 dpr（dpr=2 收到 2.0，不是 4.0）且标记为描边',
    count($rectsF) === 1 && $rectsF[0][8] === 2.0 && $rectsF[0][7] === true,
    count($rectsF) === 1 ? 'strokeWidth=' . $rectsF[0][8] . ' isStroke=' . var_export($rectsF[0][7], true) : '次数=' . count($rectsF)
);
check(
    'Skia conformance · F 描边结构：边上有墨、中间是空的',
    $rasterF->pixelAt(3, 3)->equals($red) && $rasterF->pixelAt(11, 11)->equals(Color::transparent()),
    'p(3,3)=' . ($rasterF->pixelAt(3, 3)->equals($red) ? 'red' : 'clear')
        . ' p(11,11)=' . ($rasterF->pixelAt(11, 11)->equals(Color::transparent()) ? 'clear' : 'ink')
);

// ── G. 零宽矩形：两后端都当空操作（Skia 早退、软光栅循环不进） ──
skiaBridgeReset();
$listG = new DisplayList();
(new RecordingCanvas($listG))->drawRect(new Rect(0.0, 0.0, 0.0, 10.0), Paint::fill($red));
$rasterG = new RasterCanvas(16, 16, null, Scale::of(1.0));
$skiaG = new SkiaCanvas(16, 16, null, Scale::of(1.0));
$listG->replay($rasterG);
$listG->replay($skiaG);
check(
    'Skia conformance · G 零宽矩形：无墨迹、无跨边界调用（两后端一致空操作）',
    deviceInkBounds($rasterG) === null && skiaDrawRectCalls() === [],
    'ink=' . fmtInk(deviceInkBounds($rasterG)) . ' 桥调用次数=' . count(skiaDrawRectCalls())
);

// ── H. 创建失败（句柄 0）：所有绘制静默跳过（装饰性失败，不崩不报） ──
$GLOBALS['DRIFT_SMOKE_SKIA_FAIL_CREATE'] = true;
skiaBridgeReset();
$dead = new SkiaCanvas(20, 16, null, Scale::of(1.0));
$listH = new DisplayList();
$recH = new RecordingCanvas($listH);
$recH->save();
$recH->translate(3.0, 4.0);
$recH->pushClip(new Rect(0.0, 0.0, 5.0, 5.0));
$recH->drawRect(new Rect(0.0, 0.0, 5.0, 5.0), Paint::fill($red));
$recH->popClip();
$recH->restore();
$listH->replay($dead);
check(
    'Skia conformance · H 句柄 0：isValid=false 且整条命令流零跨边界调用（只有 create）',
    !$dead->isValid() && skiaCallOps() === ['create'],
    'isValid=' . ($dead->isValid() ? 'true' : 'false') . ' 调用序列=' . implode('→', skiaCallOps())
);
$dead->dispose();
check(
    'Skia conformance · H 句柄 0 的 dispose 不产生跨边界调用',
    skiaCallOps() === ['create'],
    '调用序列=' . implode('→', skiaCallOps())
);
$GLOBALS['DRIFT_SMOKE_SKIA_FAIL_CREATE'] = false;
$alive = new SkiaCanvas(20, 16, null, Scale::of(1.0));
check('Skia conformance · H 失败开关复位后创建恢复有效', $alive->isValid());

// ── E3. popClip 只复位裁剪、不动偏移：两后端必须同解（2026-10-17 翻正）──────
// 历史：本断言 2026-10-16 上线时钉的是**两侧现状**（Raster 的 popClip 弹通用状态
// 帧 = 半个 restore，偏移也归位；Skia 只复位裁剪）。那不是"已知差异"而是**活
// 缺陷**：RenderTextField::paint 的生产序列 pushClip; save; translate(insets); 画;
// restore; popClip 只有双栈语义下自洽 —— Raster 上 TextField 的 popClip 吃掉父盒
// kid 循环的帧 ⇒ 后续兄弟丢容器偏移（同形状的完整用例见 E4）。
// 2026-10-17 已裁决并修复：RasterCanvas 加独立裁剪栈（结构与 SkiaCanvas 一致：
// 变换栈只存偏移，裁剪独立成栈）。本断言翻正为**正确语义**。
// ★ 判据可区分：弹通用帧（修复前）⇒ 第二块落 (0,0)，ink=8 且 (1,1) 有墨；
//   只复位裁剪（修复后）⇒ 第二块落 (6,0)，ink=4 且 (1,1) 无墨。
skiaBridgeReset();
$listX = new DisplayList();
$recX = new RecordingCanvas($listX);
$recX->save();
$recX->translate(6.0, 0.0);
$recX->pushClip(new Rect(0.0, 0.0, 8.0, 8.0));
$recX->drawRect(new Rect(0.0, 0.0, 2.0, 2.0), Paint::fill($red));
$recX->popClip();
$recX->drawRect(new Rect(0.0, 0.0, 2.0, 2.0), Paint::fill($red));
$recX->restore();
$rasterX = new RasterCanvas(24, 16, null, Scale::of(1.0));
$skiaX = new SkiaCanvas(24, 16, null, Scale::of(1.0));
$listX->replay($rasterX);
$listX->replay($skiaX);
$rectsX = skiaDrawRectCalls();
$inkX = inkCount($rasterX, Color::transparent());
check(
    'Skia conformance · E3 翻正：Raster 的 popClip 只复位裁剪（第二块仍带偏移 6，两块重叠 ink=4）',
    $rasterX->pixelAt(7, 1)->equals($red) && $rasterX->pixelAt(1, 1)->equals(Color::transparent()) && $inkX === 4,
    'p(7,1)=' . ($rasterX->pixelAt(7, 1)->equals($red) ? 'red' : 'clear')
        . ' p(1,1)=' . ($rasterX->pixelAt(1, 1)->equals(Color::transparent()) ? 'clear' : 'ink')
        . ' inkCount=' . $inkX
);
check(
    'Skia conformance · E3 两后端同解：Skia 侧第二块仍带偏移 6（popClip 不动偏移）',
    count($rectsX) === 2 && $rectsX[1][1] === 6.0 && $rectsX[1][2] === 0.0,
    count($rectsX) === 2 ? '第二块 x=' . $rectsX[1][1] . ' y=' . $rectsX[1][2] : '次数=' . count($rectsX)
);

// ── E4. 兄弟干扰：TextField 序列之后，兄弟必须保住容器偏移 ─────────────
// ★ 这是**唯一能抓 popClip 帧腐败的用例族**：单盒用例（E1–E3）全绿也看不见 ——
//   腐败的症状是"兄弟丢容器偏移"，只有多子形状才可观测。历史上窗口探针（非白
//   像素比例）、预览（RasterCanvas 像素转 SVG，自证）、W8（录制侧配平）对这一
//   类全是结构性盲区 ⇒ 本节按"多子"构造。
// 逐字镜像两个真实 paint：
//   · RenderFlex::paint      —— save/translate(padding)/[per kid: save/translate(kidOffset)/画/restore]/restore
//   · RenderTextField::paint —— pushClip/save/translate(insets)/画/restore/popClip
// 形状 = SignupPage 的 Column：padding 24；kid1 = TextField 形状；kid2 = 普通盒。
// 判据：kid2 的 10×10 蓝块必须落在布局位置 (24, 24+40)=(24,64)。
//   · 腐败（popClip 弹通用帧）：kid1 的 popClip 吃掉 kid 循环的帧 ⇒ kid2 落 (0,40)；
//   · 正确（两条独立栈）：两后端都把 kid2 画在 (24,64)。
skiaBridgeReset();
$blue = Color::fromRgb(0, 0, 255);
$listE4 = new DisplayList();
$recE4 = new RecordingCanvas($listE4);
$recE4->save();                                     // RenderFlex::paint
$recE4->translate(24.0, 24.0);                      // column padding
$recE4->save();                                     // kid 循环 · kid1
$recE4->translate(0.0, 0.0);                        // kid1 offset
$recE4->pushClip(new Rect(0.0, 0.0, 200.0, 28.0));  // RenderTextField::paint
$recE4->save();
$recE4->translate(4.0, 4.0);                        // TextField insets
$recE4->drawRect(new Rect(0.0, 0.0, 10.0, 10.0), Paint::fill($red));
$recE4->restore();
$recE4->popClip();
$recE4->restore();                                  // kid 循环 restore
$recE4->save();                                     // kid 循环 · kid2（offsetY=28+12=40）
$recE4->translate(0.0, 40.0);
$recE4->drawRect(new Rect(0.0, 0.0, 10.0, 10.0), Paint::fill($blue));
$recE4->restore();
$recE4->restore();                                  // flex restore
$rasterE4 = new RasterCanvas(320, 240, null, Scale::of(1.0));
$skiaE4 = new SkiaCanvas(320, 240, null, Scale::of(1.0));
$listE4->replay($rasterE4);
$listE4->replay($skiaE4);
$rectsE4 = skiaDrawRectCalls();
check(
    'Skia conformance · E4 兄弟干扰：TextField 自身落 (28,28)（kid1 不受帧腐败影响）',
    $rasterE4->pixelAt(29, 29)->equals($red),
    'p(29,29)=' . ($rasterE4->pixelAt(29, 29)->equals($red) ? 'red' : 'clear')
);
check(
    'Skia conformance · E4 兄弟保住容器偏移：蓝块落 (24,64) 而非腐败位 (0,40)',
    $rasterE4->pixelAt(25, 65)->equals($blue) && $rasterE4->pixelAt(1, 41)->equals(Color::transparent()),
    'p(25,65)=' . ($rasterE4->pixelAt(25, 65)->equals($blue) ? 'blue' : 'clear')
        . ' p(1,41)=' . ($rasterE4->pixelAt(1, 41)->equals(Color::transparent()) ? 'clear' : 'ink')
);
check(
    'Skia conformance · E4 跨后端同解：桥收到的两块设备矩形 == (28,28) 与 (24,64)',
    count($rectsE4) === 2
        && $rectsE4[0][1] === 28.0 && $rectsE4[0][2] === 28.0
        && $rectsE4[1][1] === 24.0 && $rectsE4[1][2] === 64.0,
    count($rectsE4) === 2
        ? '第一块 x=' . $rectsE4[0][1] . ' y=' . $rectsE4[0][2]
            . ' 第二块 x=' . $rectsE4[1][1] . ' y=' . $rectsE4[1][2]
        : '次数=' . count($rectsE4)
);

// ── 量具反例自检（§20 纪律 1）──────────────────────────────────────
// 合成一个已知违规件：让假桥把 draw_rect 的 x 报错一格（等价于"SkiaCanvas 算错
// 了偏移"），同一套换算判据必须变**假**。抓不到 = 判据恒真、本段整段空转。
// 紧接的正对照：同一输入不篡改时判据必须为**真**。
skiaBridgeReset();
$GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'] = true;
$listS = new DisplayList();
(new RecordingCanvas($listS))->drawRect(new Rect(3.0, 4.0, 10.0, 8.0), Paint::fill($red));
$rasterS = new RasterCanvas(24, 20, null, Scale::of(1.0));
$skiaS = new SkiaCanvas(24, 20, null, Scale::of(1.0));
$listS->replay($rasterS);
$listS->replay($skiaS);
$inkS = deviceInkBounds($rasterS);
$rectsSBad = skiaDrawRectCalls();
$expectSBad = count($rectsSBad) === 1 ? skiaRectToInk($rectsSBad[0]) : null;
check(
    'Skia conformance · 反例自检：桥把 x 篡改一格 ⇒ 判据变假（否则判据恒真、夹具空转）',
    $inkS !== $expectSBad,
    'ink=' . fmtInk($inkS) . ' 篡改后桥收到=' . fmtInk($expectSBad)
);
$GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'] = false;
skiaBridgeReset();
$skiaS2 = new SkiaCanvas(24, 20, null, Scale::of(1.0));
$listS->replay($skiaS2);
$rectsSGood = skiaDrawRectCalls();
$expectSGood = count($rectsSGood) === 1 ? skiaRectToInk($rectsSGood[0]) : null;
check(
    'Skia conformance · 反例自检正对照：同一输入不篡改 ⇒ 判据为真',
    $inkS === $expectSGood,
    'ink=' . fmtInk($inkS) . ' 桥收到=' . fmtInk($expectSGood)
);

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
[$code, $output] = runCase(__FILE__, 'dockoverflow', null);
check('Dock · C5 Report 条轨超出退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Dock · Report 超出写了 Errors.log', str_contains(logText($log), 'Dock 纵向超出'));

// ── ㉓ TextField：受控输入框（§15.2 / §A.2.9）──

// make() 不吃参数 —— 字段的必填项是 value，由链式给（§A.2.9 的 `TextField::make()`）
$tf = TextField::make()->value('abc')->label('搜索')->placeholder('输入姓名');
check(
    'TextField · make() 无参 + 链式配置生效',
    $tf->valueText() === 'abc' && $tf->labelText() === '搜索' && $tf->placeholderText() === '输入姓名',
);
check('TextField · 没给 label ⇒ null（"没标签"与"标签是空串"不是一件事）', TextField::make()->labelText() === null);
check('TextField · 默认 = 空文本 / 无占位符', TextField::make()->valueText() === '' && TextField::make()->placeholderText() === '');

// onChanged 是纯 UI 回调（与 Button::onPressed 同形：不包一层的机制）
$changedText = null;
$tfHandler = TextField::make()->onChanged(function (string $v) use (&$changedText): void {
    $changedText = $v;
})->changedHandler();
check('TextField · onChanged 闭包原样递下去', $tfHandler !== null && $tfHandler('x') === null && $changedText === 'x');

// 三段分工：State（编辑态）→ TextFieldBox（解析主题）→ RenderTextField（几何 + 绘制）
$fieldRoot = TextField::make()->value('abc')->placeholder('输入姓名')->createElement();
$fieldRoot->mount(null, $context);
$fieldBox = $fieldRoot->effectiveBox();
check('TextField · 造出 RenderTextField（StatefulWidget 自己不产盒）', $fieldBox instanceof RenderTextField);

$fieldBox?->layout(BoxConstraints::loose(new Size(200.0, 60.0)));
$fieldW = $fieldBox?->size()?->width ?? 0.0;
$fieldH = $fieldBox?->size()?->height ?? 0.0;
check(
    'TextField · 盒尺寸 = 内边距 + 内容（不是 0，也不填满约束）',
    $fieldW > 0.0 && $fieldW < 200.0 && $fieldH > 0.0,
    sprintf('实际 %.2f × %.2f', $fieldW, $fieldH),
);
check(
    'TextField · 有文本时显示真实文本（不是占位符）',
    $fieldBox instanceof RenderTextField && !$fieldBox->isShowingPlaceholder(),
);
check('TextField · 显式声明溢出策略 Clip（C5：会超的容器必须声明）', $fieldBox?->overflow() === Overflow::Clip);

// 空值 ⇒ 占位符，且高度不塌（行高由样式推，不靠内容）
$emptyRoot = TextField::make()->placeholder('输入姓名')->createElement();
$emptyRoot->mount(null, $context);
$emptyBox = $emptyRoot->effectiveBox();
$emptyBox?->layout(BoxConstraints::loose(new Size(200.0, 60.0)));
check(
    'TextField · 空值时显示占位符，且高度不塌成 0',
    $emptyBox instanceof RenderTextField
        && $emptyBox->isShowingPlaceholder()
        && ($emptyBox->size()?->height ?? 0.0) > 0.0,
);

// 主题经 BuildContext 解析一次（§15.7 纪律 3）—— Layout 层不碰 Theme
$lightField = TextField::make()->value('x')->createElement();
$lightField->mount(null, $context);
$darkField = TextField::make()->value('x')->createElement();
$darkField->mount(null, $darkContext);
$lightFieldBox = $lightField->effectiveBox();
$darkFieldBox = $darkField->effectiveBox();
check(
    'TextField · 明/暗下取到不同底色（换肤改表不改控件）',
    $lightFieldBox instanceof RenderTextField
        && $darkFieldBox instanceof RenderTextField
        && $lightFieldBox->boxStyle()->background?->toArgb() !== $darkFieldBox->boxStyle()->background?->toArgb(),
);
check(
    'TextField · 输入"井"有描边（与 Button::Default 的凸起观感区分开）',
    $fieldBox instanceof RenderTextField && $fieldBox->boxStyle()->borderColor !== null,
);

// 有 label ⇒ build 出 Column（标签在上、字段在下），根盒是 RenderFlex
$labelRoot = TextField::make()->value('x')->label('搜索')->createElement();
$labelRoot->mount(null, $context);
check('TextField · 有 label ⇒ 根是 Column（标签与字段上下排）', $labelRoot->effectiveBox() instanceof RenderFlex);

// ── 编辑态：M3 的输入层将来接在这几个方法上 ──

$seen = [];
$editRoot = TextField::make()->value('ab')->onChanged(function (string $v) use (&$seen): void {
    $seen[] = $v;
})->createElement();
$editRoot->mount(null, $context);
$editState = $editRoot->state();
check('TextField · element->state() 是 TextFieldState', $editState instanceof TextFieldState);
check('TextField · 初值来自 value()，插入符在末尾', $editState?->text() === 'ab' && $editState?->caretOffset() === 2);
check('TextField · 默认不在编辑态（插入符不画）', $editState?->isEditing() === false);

$editState?->beginEditing();
check('TextField · beginEditing() 进入编辑态', $editState?->isEditing() === true);

$editState?->insert('X');       // ab   → abX（插入符 3）
$editState?->deleteBackward();  // abX  → ab （插入符 2）
$editState?->moveCaret(1);      // 插入符 1
$editState?->insert('Z');       // ab   → aZb（插入符 2）
check(
    'TextField · 编辑序列 ab → abX → ab → aZb（插入符跟随）',
    $editState?->text() === 'aZb' && $editState?->caretOffset() === 2,
    '实际 ' . ($editState?->text() ?? 'null') . ' @' . (string) ($editState?->caretOffset() ?? -1),
);
check(
    'TextField · 每次真改动都通知 onChanged（无变化不通知）',
    $seen === ['abX', 'ab', 'aZb'],
    '实际 ' . json_encode($seen),
);

// ★ 多字节：退格与移动都按**码位**走，不会把字符切成半个
$cjkSeen = [];
$cjkRoot = TextField::make()->value('中文')->onChanged(function (string $v) use (&$cjkSeen): void {
    $cjkSeen[] = $v;
})->createElement();
$cjkRoot->mount(null, $context);
$cjkState = $cjkRoot->state();
$cjkState?->deleteBackward();   // 删掉「文」（3 字节），不是 1 字节
check(
    'TextField · 退格按码位删（多字节不被切半）',
    $cjkState?->text() === '中' && $cjkState?->caretOffset() === 3,
    '实际 ' . ($cjkState?->text() ?? 'null') . ' @' . (string) ($cjkState?->caretOffset() ?? -1),
);
$cjkState?->moveCaret(2);       // 落在「中」字符内部 ⇒ 吸附到 0
check('TextField · moveCaret 吸附到字符边界（不会切半字符）', $cjkState?->caretOffset() === 0);
$cjkState?->insert('甲');
check('TextField · 在字符边界插入多字节字符', $cjkState?->text() === '甲中', '实际 ' . ($cjkState?->text() ?? 'null'));

// 插入符几何（IME 定位候选窗的来源，§8.4）
// ★ 读 caretRect() 之前必须 settle()：它读的是**最近一次 build 的盒**，
//   而 moveCaret() 只标脏（§5.2 裁决 3：重建发生在帧末）。
$editState?->moveCaret(0);
$editRoot->settle();
$caretAt0 = $editState?->caretRect();
$editState?->moveCaretToEnd();
$editRoot->settle();
$caretAtEnd = $editState?->caretRect();
check(
    'TextField · caretRect() 给出盒内几何（高度 > 0，且在盒内）',
    $caretAtEnd !== null && $caretAtEnd->height > 0.0 && $caretAtEnd->left >= 0.0,
);
check(
    'TextField · 插入符越靠右 x 越大（几何真的跟着插入符走）',
    $caretAt0 !== null && $caretAtEnd !== null && $caretAtEnd->left > $caretAt0->left,
    sprintf('x %.2f → %.2f', $caretAt0?->left ?? -1.0, $caretAtEnd->left),
);

// ★ 端到端：编辑态真的改变画面（rebuild → 新盒 → 多出插入符的墨）
$editRoot->settle();
$caretPaintBox = $editRoot->effectiveBox();
$caretPaintBox?->layout(BoxConstraints::loose(new Size(200.0, 60.0)));
$caretInk = fieldInk($caretPaintBox, 120, 40);

$editState?->endEditing();
$editRoot->settle();
$noCaretBox = $editRoot->effectiveBox();
$noCaretBox?->layout(BoxConstraints::loose(new Size(200.0, 60.0)));
$noCaretInk = fieldInk($noCaretBox, 120, 40);
check(
    'TextField · 插入符真的画上了（编辑态比非编辑态多出墨）',
    $caretPaintBox instanceof RenderTextField && $caretInk > $noCaretInk,
    '编辑 ' . $caretInk . ' / 非编辑 ' . $noCaretInk,
);

// 受控回推：外部换 value ⇒ State 采纳（§A.2.9 的官方订阅形态靠这条闭环）
$editRoot->update(TextField::make()->value('新值')->onChanged(function (string $v) use (&$seen): void {
    $seen[] = $v;
}));
check('TextField · 外部推新 value ⇒ State 采纳并重建', $editState?->text() === '新值');

// §20 纪律 4：能力必须在**示例**里出现过（没出现 = 没交付）。
// FormPage 是 TextField 的契约示例 —— 这条锁住“示例页不静默腐烂”。
$formSvg = (new Renderer(new SvgSurface(), $context->metrics(), ThemeData::light()))
    ->render(new FormPage(), 360.0, 200.0, 1.0);
check(
    'TextField · 表单示例页能无头出图（§20 纪律 4）',
    substr_count($formSvg, '<rect x=') > 0,
    'run 数 ' . (string) substr_count($formSvg, '<rect x=')
);

// ── ㉔ 输入层最小集（§9.1：命中 → 焦点 → 按键）──
// ★ 这是让 TextField **真能被用户驱动**的那一环（§20 纪律 4：没进示例 = 没交付）。
//   数据与声明住 Drift\Input；路由（焦点链 + 命中测试）住 Drift\App\FocusManager。

check(
    '输入层 · KeyChord 值语义（Ctrl+A ≠ Ctrl+C）',
    KeyChord::ctrl('A')->is(KeyChord::ctrl('A')) && !KeyChord::ctrl('A')->is(KeyChord::ctrl('C')),
);
check('输入层 · KeyChord::f(5) ⇒ F5', KeyChord::f(5)->key === 'F5' && KeyChord::f(5)->label() === 'F5');
check(
    '输入层 · KeyEvent::character 保留原字符、chord 归一化',
    KeyEvent::character('井')->text === '井' && KeyEvent::character('井')->chord->key === '井',
);
check('输入层 · KeyEvent::down 不带文本（编辑键 / 加速键）', !KeyEvent::down(KeyChord::tab())->isText());

$fn = FocusNode::make()->withOrder(3)->claiming([KeyChord::ctrl('A')]);
check('输入层 · FocusNode 链式声明 + hasOrder', $fn->order === 3 && $fn->hasOrder() && !$fn->skip);
check(
    '输入层 · FocusNode::claims 只认声明过的组合',
    $fn->claims(KeyChord::ctrl('A')) && !$fn->claims(KeyChord::ctrl('B')),
);
check('输入层 · FocusNode::skipped 是子树级标记', FocusNode::make()->skipped()->skip);

// Widget 侧声明 ⇒ focusNode()
$declared = TextField::make()->focusOrder(2)->claimKeys([KeyChord::ctrl('A')]);
check(
    'Widget · focusOrder/claimKeys 落到 focusNode()',
    $declared->focusNode()?->order === 2 && ($declared->focusNode()?->claims(KeyChord::ctrl('A')) ?? false),
);
check('Widget · 没声明任何焦点属性 ⇒ focusNode() 为 null', Text::make('x')->focusNode() === null);
check('Widget · Text 不可聚焦（默认 false）', !Text::make('x')->isFocusable());
check('Widget · TextField 天然可聚焦', TextField::make()->isFocusable());

// 一棵可交互的树：两个字段上下排
$inContext = smokeContext();
$inRoot = Column::make()->spacing(8)->children([
    TextField::make()->value('ab'),
    TextField::make()->value('cd'),
])->createElement();
$inRoot->mount(null, $inContext);
$inBox = $inRoot->effectiveBox();
$inBox?->layout(BoxConstraints::loose(new Size(300.0, 200.0)));

$fm = new FocusManager();
$fm->collect($inRoot);
check('输入层 · 焦点链收进 2 个字段（文档序）', count($fm->order()) === 2);

$inFirst = $fm->order()[0] ?? null;
$inSecond = $fm->order()[1] ?? null;
$inFirstState = $inFirst instanceof StatefulElement ? $inFirst->state() : null;

check('输入层 · 没焦点时 dispatchKey 不吃键', !$fm->dispatchKey(KeyEvent::character('X')));

$fm->dispatchKey(KeyEvent::down(KeyChord::tab()));
check('输入层 · Tab ⇒ 焦点落到第一个字段', $fm->focused() === $inFirst);
check(
    '输入层 · 焦点落下即进入编辑态（插入符出现）',
    $inFirstState instanceof TextFieldState && $inFirstState->isEditing(),
);

$fm->dispatchKey(KeyEvent::character('X'));
check('输入层 · 打字 ⇒ 文本真的变了', $inFirstState?->text() === 'abX');

$fm->dispatchKey(KeyEvent::down(KeyChord::backspace()));
check('输入层 · 退格 ⇒ 删掉一个字符', $inFirstState?->text() === 'ab');

$fm->dispatchKey(KeyEvent::down(KeyChord::left()));
check('输入层 · 左方向键 ⇒ 插入符左移一位', $inFirstState?->caretOffset() === 1);
$fm->dispatchKey(KeyEvent::down(KeyChord::right()));
check(
    '输入层 · 右方向键 ⇒ 插入符右移一位（★ 在边界上不是原地不动）',
    $inFirstState?->caretOffset() === 2,
);
$fm->dispatchKey(KeyEvent::down(KeyChord::home()));
check('输入层 · Home ⇒ 行首', $inFirstState?->caretOffset() === 0);
$fm->dispatchKey(KeyEvent::down(KeyChord::end()));
check('输入层 · End ⇒ 行尾', $inFirstState?->caretOffset() === 2);

$fm->dispatchKey(KeyEvent::down(KeyChord::tab()));
check('输入层 · Tab ⇒ 焦点移到第二个字段', $fm->focused() === $inSecond);
check('输入层 · 焦点离开 ⇒ 前一个字段退出编辑态', $inFirstState !== null && !$inFirstState->isEditing());

$fm->dispatchKey(KeyEvent::down(KeyChord::tab()));
check('输入层 · 到末尾 Tab 就停（不环绕）', $fm->focused() === $inSecond);
$fm->dispatchKey(KeyEvent::down(KeyChord::shift('Tab')));
check('输入层 · Shift+Tab ⇒ 回到第一个字段', $fm->focused() === $inFirst);

// 命中测试 → 焦点
check('输入层 · hitTest 越界 ⇒ null', $inBox?->hitTest(-1.0, 5.0) === null);
check(
    '输入层 · hitTest 落在第一个字段的盒上（最深优先）',
    $inBox?->hitTest(10.0, 10.0) === $inFirstState?->element()?->effectiveBox(),
);

$fmHit = new FocusManager();
$fmHit->collect($inRoot);
$fmHit->dispatchPointer(PointerEvent::down(10.0, 10.0), $inRoot);
check(
    '输入层 · 点一下字段 ⇒ 它拿到焦点并进入编辑态',
    $fmHit->focused() === $inFirst && $inFirstState !== null && $inFirstState->isEditing(),
    sprintf(
        'focused=first:%s null:%s｜editing=%s｜order=%d｜hit=%s',
        $fmHit->focused() === $inFirst ? 'y' : 'n',
        $fmHit->focused() === null ? 'y' : 'n',
        $inFirstState?->isEditing() ? 'y' : 'n',
        count($fmHit->order()),
        $inBox?->hitTest(10.0, 10.0) === null ? 'null' : 'box',
    ),
);

// ★ 帧末重建（§5.2 裁决 3）：脏 StatefulElement 造了新盒后，**父容器必须换上新盒**
//   （§13.2 + C1 父管挂接）。漏掉这一步是**静默**的 —— 新盒确实造出来了，但祖先
//   容器仍握着旧盒对象，`layout()/paint()` 走的是重建前那棵渲染树 ⇒ 画面纹丝不动、
//   也不报错。首个暴露者就是 `TextField`：`--focus` 曾是**空操作**（插入符画不出来）。
//   ⇒ 这条断言的作用是"量具双向自检"（§20 纪律 1）：`Element::settle()` 里去掉
//   `adoptBoxes()` 它必须变红。
$inBoxBefore = $inBox?->children()[0] ?? null;
$inFirstState?->markDirty();          // 等价于事件中途的 setState：只标脏
$inRoot->settle();                    // 帧末统一重建
$inBoxNow = $inRoot->effectiveBox();
check(
    '输入层 · 帧末重建后父容器换上了新盒（§13.2 挂接，不是握着旧盒）',
    $inBoxNow instanceof RenderFlex
        && ($inBoxNow->children()[0] ?? null) === $inFirstState?->element()?->effectiveBox()
        && ($inBoxNow->children()[0] ?? null) !== $inBoxBefore,
    sprintf(
        'before=%s after=%s effective=%s',
        $inBoxBefore === null ? 'null' : $inBoxBefore::class . '#' . spl_object_id($inBoxBefore),
        ($inBoxNow?->children()[0] ?? null) === null ? 'null' : ($inBoxNow->children()[0])::class . '#' . spl_object_id($inBoxNow->children()[0]),
        $inFirstState?->element()?->effectiveBox() === null ? 'null' : spl_object_id($inFirstState->element()->effectiveBox()),
    ),
);

// skipFocus / focusOrder
$skipRoot = Column::make()->children([
    TextField::make()->value('a'),
    Column::make()->skipFocus()->children([TextField::make()->value('b')]),
])->createElement();
$skipRoot->mount(null, $inContext);
$fmSkip = new FocusManager();
$fmSkip->collect($skipRoot);
check('输入层 · skipFocus 整棵子树不进 Tab 序', count($fmSkip->order()) === 1);

$orderRoot = Column::make()->children([
    TextField::make()->value('first')->focusOrder(2),
    TextField::make()->value('second')->focusOrder(1),
])->createElement();
$orderRoot->mount(null, $inContext);
$fmOrder = new FocusManager();
$fmOrder->collect($orderRoot);
$orderedFirst = $fmOrder->order()[0] ?? null;
$orderedState = $orderedFirst instanceof StatefulElement ? $orderedFirst->state() : null;
check(
    '输入层 · focusOrder 稳定排序（1 排在 2 前面）',
    $orderedState instanceof TextFieldState && $orderedState->text() === 'second',
);

// claims：焦点独占键（§9.1 让位规则 ②）
$claimRoot = TextField::make()->claimKeys([KeyChord::ctrl('A')])->createElement();
$claimRoot->mount(null, $inContext);
$fmClaim = new FocusManager();
$fmClaim->collect($claimRoot);
$fmClaim->focusNext();
check(
    '输入层 · claimKeys ⇒ 焦点独占 Ctrl+A、不独占 Ctrl+C',
    $fmClaim->claims(KeyChord::ctrl('A')) && !$fmClaim->claims(KeyChord::ctrl('C')),
);

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

// ④b **容器**也要立即换盒**且子盒已挂上**（§13.2：先换盒、再挂子）。
//    顺序反了（先 rebuild 后 syncBox）会让新盒为空 ⇒ 容器整棵子树静默消失。
//    ★ 这条与上面那条**不重复**：上面测叶子（自己就是盒），这条测容器（盒里还要装子盒）。
$scopedColumn = Column::make()->children([Text::make('a'), Text::make('b')]);
$columnScope = ThemeScope::make(ThemeData::light(), $scopedColumn)->createElement();
$columnScope->mount(null, $context);
$columnScope->update(ThemeScope::make(ThemeData::dark(), $scopedColumn));
$columnBox = $columnScope->childAt(0)?->renderObject();
check(
    'ThemeScope · 切主题 ⇒ 容器的盒立即重算**且子盒已挂上**（§13.2 顺序）',
    $columnBox instanceof RenderFlex && count($columnBox->children()) === 2,
    sprintf(
        'box=%s kids=%s',
        $columnBox === null ? 'null' : $columnBox::class,
        $columnBox instanceof RenderFlex ? (string) count($columnBox->children()) : 'n/a',
    ),
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

// ── ㉜ 光标形状（§9.1「一次命中，三方消费」的第 ③ 方）──
// ★ 三方 = 事件路由 / 语义 / **光标形状**。前两方早就在（`focusHit()`；语义树是
//   M4 的活）；第 ③ 方此前**完全缺位** ⇒ 真机窗口的指针永远是箭头，**且不报错**
//   （§9.1 只把"光标形状"写进了名字，没有一行代码兑现它）。
// ★ 本段只判**解释器面判得了的**：形状表（= 桥的 ABI）· 声明落到盒上 · 命中链
//   取最近者 · 应用层出口。**"系统光标真的变成手形"只能真机判**（`GetCursorInfo`），
//   不在这里假装 —— 那是 §20 纪律 1 的双向量具纪律。

check('光标 · Default 是 0（= 桥的 IDC_ARROW，兜底值）', CursorShape::Default->value === 0);
check(
    '光标 · IBeam=1 / PointingHand=2（★ value 就是桥的 ABI：改它等于改协议）',
    CursorShape::IBeam->value === 1 && CursorShape::PointingHand->value === 2,
);
$shapeValues = array_map(static fn (CursorShape $c): int => $c->value, CursorShape::cases());
check(
    '光标 · 8 个 case、value 恰好是 0..7 且互不重复（桥的 switch 覆盖面）',
    $shapeValues === [0, 1, 2, 3, 4, 5, 6, 7],
    'values=' . implode(',', $shapeValues),
);
check(
    '光标 · from() 往返（桥只传编号，PHP 侧能还原形状）',
    CursorShape::from(2) === CursorShape::PointingHand,
);

// 声明：Widget::cursor() ⇒ 盒上的 RenderObject::cursor()
check(
    '光标 · 没声明 ⇒ cursorShape() 为 null（"我不管，问上面"）',
    Text::make('x')->cursorShape() === null,
);
check(
    '光标 · Widget::cursor() 是声明（链式 + 可读回）',
    Text::make('x')->cursor(CursorShape::IBeam)->cursorShape() === CursorShape::IBeam,
);

// ★ 用 tight 约束：`RenderCenter` 是**贴身**的（size = 孩子 size），loose 下盒只有
//   文字那么大 ⇒ 命中点得算字体度量。tight 下盒铺满，命中点取 (10,10) 即可，
//   与度量无关（§20 纪律 1：断言不该依赖它不打算测的东西）。
$curContext = smokeContext();
$curRoot = Center::make()->cursor(CursorShape::PointingHand)->child(Text::make('hit me'))->createElement();
$curRoot->mount(null, $curContext);
$curBox = $curRoot->effectiveBox();
$curBox?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));
check(
    '光标 · 声明落到了盒上（Element::syncBox 是唯一落点）',
    $curBox?->cursor() === CursorShape::PointingHand,
);

$curFm = new FocusManager();

// 内层声明盖过外层：Padding(Wait) > Center(PointingHand) > Text(无)
$nestRoot = Padding::make(Center::make()->cursor(CursorShape::PointingHand)->child(Text::make('deep')))
    ->cursor(CursorShape::Wait)
    ->createElement();
$nestRoot->mount(null, $curContext);
$nestBox = $nestRoot->effectiveBox();
$nestBox?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));
$nestHit = $nestBox?->hitTest(10.0, 10.0);
check(
    '光标 · 命中链取**最近**声明者（内层 PointingHand 盖过外层 Wait）',
    $curFm->cursorOf($nestHit) === CursorShape::PointingHand,
    'resolved=' . $curFm->cursorOf($nestHit)->name . '｜hit=' . ($nestHit === null ? 'null' : $nestHit::class),
);

// 命中者自己没声明 ⇒ 沿父链上溯
$outerRoot = Padding::make(Center::make()->child(Text::make('deep')))
    ->cursor(CursorShape::Wait)
    ->createElement();
$outerRoot->mount(null, $curContext);
$outerBox = $outerRoot->effectiveBox();
$outerBox?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));
$outerHit = $outerBox?->hitTest(10.0, 10.0);
check(
    '光标 · 命中者自己没声明 ⇒ 沿父链上溯到声明者（Wait）',
    $curFm->cursorOf($outerHit) === CursorShape::Wait,
    'resolved=' . $curFm->cursorOf($outerHit)->name . '｜hit=' . ($outerHit === null ? 'null' : $outerHit::class),
);

// 整条链没人声明 ⇒ Default（不是 null）
$bareRoot = Center::make()->child(Text::make('bare'))->createElement();
$bareRoot->mount(null, $curContext);
$bareBox = $bareRoot->effectiveBox();
$bareBox?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));
check(
    '光标 · 整条链没人声明 ⇒ Default（★ 不是 null：Default 是**明确决定**）',
    $curFm->cursorOf($bareBox?->hitTest(10.0, 10.0)) === CursorShape::Default,
);
check('光标 · 没命中任何盒（点在根外）⇒ Default', $curFm->cursorOf(null) === CursorShape::Default);

// 应用面：dispatchInput() 的返回值**就是**喂给 WindowHost::setCursor() 的形状
$curApp = new CursorApplication();
check(
    '光标 · 未挂载 ⇒ dispatchInput 返回 null（没有树就没有路由）',
    $curApp->dispatchInput(PointerEvent::down(1.0, 1.0)) === null,
);
$curApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
check(
    '光标 · 按键不吃光标（dispatchInput(KeyEvent) 返回 null，焦点链照常走）',
    $curApp->dispatchInput(KeyEvent::down(KeyChord::tab())) === null,
);
check(
    '光标 · 指针落在控件上 ⇒ dispatchInput 返回声明形状（第 ③ 方的出口）',
    $curApp->dispatchInput(PointerEvent::down(160.0, 120.0)) === CursorShape::PointingHand,
);
check(
    '光标 · 指针落在窗口外 ⇒ 返回 Default（壳据此把光标还原成箭头）',
    $curApp->dispatchInput(PointerEvent::down(-5.0, -5.0)) === CursorShape::Default,
);

// ── ㉗ Widget.Spinner：加载指示器（§11.5 三态 Loading 分支，2026-10-16 落地）──

/** 把一个盒画到白底光栅上（§14.1：同输入 ⇒ 同指纹，golden 的物质基础） */
function spinCanvas(RenderBox $box, int $width, int $height): RasterCanvas
{
    $list = new DisplayList();
    $box->paint(new RecordingCanvas($list));

    $canvas = new RasterCanvas($width, $height, Color::white(), Scale::of(1.0));
    $list->replay($canvas);

    return $canvas;
}

$spinRoot = Spinner::make()->diameter(40.0)->createElement();
$spinRoot->mount(null, $context);
$spinBox = $spinRoot->effectiveBox();
check(
    'Spinner · 挂载即建出渲染盒（State.build → SpinnerBox → RenderSpinner）',
    $spinBox instanceof RenderSpinner,
);
$spinBox?->layout(BoxConstraints::loose(new Size(200.0, 200.0)));
check(
    'Spinner · 宽松约束 ⇒ 拿自然直径（40×40）',
    $spinBox?->size()?->width === 40.0 && $spinBox?->size()?->height === 40.0,
);
check(
    'Spinner · 内禀尺寸 = 直径（另一轴不限）',
    $spinBox?->intrinsicWidth(INF) === 40.0 && $spinBox?->intrinsicHeight(INF) === 40.0,
);

// 约束比直径小 ⇒ 缩小而不报 C6（装饰性控件；C6 只罚"画到盒外"）
$tightSpin = Spinner::make()->diameter(40.0)->createElement();
$tightSpin->mount(null, $context);
$tightSpin->effectiveBox()?->layout(BoxConstraints::tight(new Size(16.0, 16.0)));
check(
    'Spinner · 紧约束(16) < 直径(40) ⇒ 缩到约束内（C6 不响）',
    $tightSpin->effectiveBox()?->size()?->width === 16.0,
);

// 紧约束比直径大 ⇒ 盒被父定死（C6 下界提升，框架统一行为），但墨迹不拉伸
$fillSpin = Spinner::make()->diameter(40.0)->createElement();
$fillSpin->mount(null, $context);
$fillBox = $fillSpin->effectiveBox();
$fillBox?->layout(BoxConstraints::tight(new Size(200.0, 200.0)));
check(
    'Spinner · 紧约束(200) > 直径(40) ⇒ 盒被父定死 200×200',
    $fillBox?->size()?->width === 200.0,
);
$fillCanvas = spinCanvas($fillBox, 200, 200);
$fMinX = PHP_INT_MAX;
$fMaxX = -1;
$fMinY = PHP_INT_MAX;
$fMaxY = -1;
$fInk = 0;
for ($y = 0; $y < $fillCanvas->height(); $y++) {
    for ($x = 0; $x < $fillCanvas->width(); $x++) {
        if (!$fillCanvas->pixelAt($x, $y)->equals(Color::white())) {
            $fInk++;
            $fMinX = min($fMinX, $x);
            $fMaxX = max($fMaxX, $x);
            $fMinY = min($fMinY, $y);
            $fMaxY = max($fMaxY, $y);
        }
    }
}
check(
    'Spinner · 授予盒 200×200 但墨迹不拉伸（≈40×40 且居中 —— 图标语义）',
    $fInk > 0
        && ($fMaxX - $fMinX + 1) <= 42 && ($fMaxY - $fMinY + 1) <= 42
        && abs($fMinX + $fMaxX - 199) <= 4 && abs($fMinY + $fMaxY - 199) <= 4,
    sprintf('ink=%d bounds=%d×%d center=(%d,%d)', $fInk, $fMaxX - $fMinX + 1, $fMaxY - $fMinY + 1, (int) (($fMinX + $fMaxX) / 2), (int) (($fMinY + $fMaxY) / 2)),
);

// 纯函数：同相位渲染两次 ⇒ 同指纹
$sigA = md5(spinCanvas($spinBox, 200, 200)->toRgba());
$sigB = md5(spinCanvas($spinBox, 200, 200)->toRgba());
check('Spinner · 同相位渲染两次指纹一致（§14.1 纯函数）', $sigA === $sigB);

// 墨迹结构：8 个点 = 8 档渐隐 ⇒ 墨里恰好 8 种颜色（alpha 尾迹真的画上了）
$dotCanvas = spinCanvas($spinBox, 200, 200);
$spinInk = 0;
$shades = [];
for ($y = 0; $y < $dotCanvas->height(); $y++) {
    for ($x = 0; $x < $dotCanvas->width(); $x++) {
        $pixel = $dotCanvas->pixelAt($x, $y);
        if (!$pixel->equals(Color::white())) {
            $spinInk++;
            $shades[$pixel->toArgb()] = true;
        }
    }
}
check('Spinner · 8 个点都有墨（ink > 0）', $spinInk > 0, 'ink=' . $spinInk);
check(
    'Spinner · 8 档渐隐 = 8 种颜色（不是 8 个同样实心的点）',
    count($shades) === 8,
    'shades=' . count($shades),
);

// 动画：tick() 只标脏，帧末才重建；重建后墨迹变化
$spinState = $spinRoot->state();
check('Spinner · State 只建一次（初相位 0）', $spinState instanceof SpinnerState && $spinState->step() === 0);
$spinState?->tick();
check(
    'Spinner · tick 只标脏、不立即重建（§5.2 裁决 3）',
    $spinState?->needsBuild() === true,
);
$spinSurface = new SurfaceProbe();
$spinSurface->resize(200, 200, 1.0);
$spinFrame = new Frame($spinSurface);
$spinFrame->run($spinRoot, BoxConstraints::tight(new Size(200.0, 200.0)), 16.0);
$spinBox2 = $spinRoot->effectiveBox();
check(
    'Spinner · 帧末重建后换成新盒',
    $spinBox2 !== $spinBox && $spinBox2 instanceof RenderSpinner,
);
check('Spinner · 相位前进一步（step 0 → 1）', $spinState instanceof SpinnerState && $spinState->step() === 1);
check(
    'Spinner · 相位变 ⇒ 墨迹变（动画真的在走）',
    md5(spinCanvas($spinBox2, 200, 200)->toRgba()) !== $sigA,
);

// ── ㉘ 多页外壳：Dock / AppBar / NavButton + 命令激活（§12.1 / §12.2，2026-10-16 落地）──

// RenderDock 五槽布局（直接构造渲染盒，与 C5 负例同一条测试路径）
$dock = new RenderDock(Overflow::Report);
$dock->setSlot('top', new SolidBox(new Size(100.0, 20.0), 100.0, 20.0));
$dock->setSlot('bottom', new SolidBox(new Size(100.0, 15.0), 100.0, 15.0));
$dock->setSlot('left', new SolidBox(new Size(30.0, 65.0), 30.0, 65.0));
$dock->setSlot('right', new SolidBox(new Size(25.0, 65.0), 25.0, 65.0));
$dock->setSlot('center', new SolidBox(new Size(45.0, 65.0), 45.0, 65.0));
$dock->layout(BoxConstraints::tight(new Size(100.0, 100.0)));
check(
    'Dock · top 槽：全宽贴顶、拿自然高',
    $dock->slot('top')?->offsetX() === 0.0 && $dock->slot('top')?->offsetY() === 0.0
        && $dock->slot('top')?->size()?->width === 100.0 && $dock->slot('top')?->size()?->height === 20.0,
);
check(
    'Dock · bottom 槽：全宽贴底（100−15）',
    $dock->slot('bottom')?->offsetY() === 85.0 && $dock->slot('bottom')?->size()?->height === 15.0,
);
check(
    'Dock · left/right 轨：中带全高、贴左右（右轨 100−25）',
    $dock->slot('left')?->offsetY() === 20.0 && $dock->slot('left')?->size()?->height === 65.0
        && $dock->slot('right')?->offsetX() === 75.0 && $dock->slot('right')?->offsetY() === 20.0,
);
check(
    'Dock · center 槽：tight 填满剩余矩形（45×65 @ 30,20）',
    $dock->slot('center')?->offsetX() === 30.0 && $dock->slot('center')?->offsetY() === 20.0
        && $dock->slot('center')?->size()?->width === 45.0 && $dock->slot('center')?->size()?->height === 65.0,
);

// 元素层：挂载、封存、槽盒挂接（盒挂接不变量）
$shellRoot = Dock::make()
    ->top(AppBar::make()->title('外壳')->children([NavButton::make('列表')->command('nav.users')]))
    ->center(Column::make()->children([Text::make('内容')]))
    ->createElement();
$shellRoot->mount(null, $context);
$shellBox = $shellRoot->effectiveBox();
check('Dock · 挂载即建出 RenderDock', $shellBox instanceof RenderDock);
check(
    'Dock · 槽盒按声明挂接（top/center 有盒，left/right/bottom 为空）',
    $shellBox instanceof RenderDock
        && $shellBox->slot('top') !== null && $shellBox->slot('center') !== null
        && $shellBox->slot('left') === null && $shellBox->slot('right') === null && $shellBox->slot('bottom') === null,
);
check('Dock · 挂载后全树封存', $shellRoot->widget()->isSealed());

// 条的宽拉满 / 轨的高铺满（§12.1 第 3 条"全宽横条 / 中带全高竖轨"）
$stretchRoot = Dock::make()
    ->top(AppBar::make()->title('条')->children([NavButton::make('a')->command('x')]))
    ->left(Padding::make(Text::make('轨'))->all(4.0))
    ->center(Text::make('内容'))
    ->createElement();
$stretchRoot->mount(null, $context);
$stretchBox = $stretchRoot->effectiveBox();
$stretchBox?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));
check(
    'Dock · 横条拉满全宽（AppBar 300 宽，不是自然宽）',
    $stretchBox instanceof RenderDock && $stretchBox->slot('top')?->size()?->width === 300.0,
    (string) ($stretchBox instanceof RenderDock ? $stretchBox->slot('top')?->size()?->width : -1.0),
);
check(
    'Dock · 竖轨铺满中带高（轨高 = 200 − 条高）',
    $stretchBox instanceof RenderDock
        && $stretchBox->slot('left')?->size()?->height === 200.0 - ($stretchBox->slot('top')?->size()?->height ?? 0.0),
);

// AppBar：标题 + children 一行；chrome 底有墨
$barRoot = AppBar::make()->title('用户管理')->children([
    NavButton::make('列表')->command('nav.users'),
])->createElement();
$barRoot->mount(null, $context);
$barBox = $barRoot->effectiveBox();
$barBox?->layout(BoxConstraints::tight(new Size(300.0, 60.0)));
check('AppBar · 渲染盒是 RenderAppBar（RenderFlex 的 chrome 子类）', $barBox instanceof RenderAppBar);
$barKids = [];
$barBox?->visitChildren(function (RenderObject $kid) use (&$barKids): void {
    if ($kid instanceof RenderBox) {
        $barKids[] = $kid;
    }
});
check(
    'AppBar · 标题与按钮排成一行（按钮在标题右侧、不重叠）',
    count($barKids) === 2
        && $barKids[1]->offsetX() >= $barKids[0]->offsetX() + ($barKids[0]->size()?->width ?? 0.0),
    sprintf('%d 个子盒', count($barKids)),
);
check('AppBar · chrome 底有墨（底色真的画了）', inkCount(spinCanvas($barBox, 300, 60)) > 0);

// AppBar::skipFocus() ⇒ 整组工具按钮不进 Tab 序（§A.3.4）
$skipRoot = AppBar::make()->children([
    NavButton::make('a')->command('x'),
    NavButton::make('b')->command('y'),
])->skipFocus()->createElement();
$skipRoot->mount(null, $context);
$skipFm = new FocusManager();
$skipFm->collect($skipRoot);
check('AppBar · skipFocus ⇒ 整组按钮不进 Tab 序', $skipFm->order() === []);

$keepRoot = AppBar::make()->children([
    NavButton::make('a')->command('x'),
    NavButton::make('b')->command('y'),
])->createElement();
$keepRoot->mount(null, $context);
$keepFm = new FocusManager();
$keepFm->collect($keepRoot);
check('AppBar · 不声明 skipFocus ⇒ 按钮照常进 Tab 序（2 个）', count($keepFm->order()) === 2);

// 命令激活（§9.1：焦点按钮"回车/空格能触发"）—— 两条路径各一遍
$navApp = new NavApplication();
$navApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$navApp->dispatchInput(KeyEvent::down(KeyChord::tab()));      // 焦点 ⇒ 第一个 NavButton
$navApp->dispatchInput(KeyEvent::down(KeyChord::enter()));    // 激活 ⇒ 命令带 arg 派发
check(
    '激活 · 焦点 NavButton 的 Enter ⇒ 命令带 arg 派发（nav.users#7）',
    $navApp->recorder()->seen === ['nav.users#7/'],
    implode(',', $navApp->recorder()->seen),
);
$navApp->dispatchInput(KeyEvent::down(KeyChord::tab()));      // 焦点 ⇒ 取消按钮
$navApp->dispatchInput(KeyEvent::down(KeyChord::make('Space'))); // 激活 ⇒ onPressed 纯 UI 回调
check(
    '激活 · 焦点 Button 的 Space ⇒ onPressed 被调（纯 UI 回调路径）',
    $navApp->pressed === ['cancelled'],
    implode(',', $navApp->pressed),
);

// 无焦点时激活键 ⇒ 无事发生（不崩、不误派发）
$idleApp = new NavApplication();
$idleApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$idleApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
check(
    '激活 · 无焦点时 Enter ⇒ 无事发生',
    $idleApp->recorder()->seen === [] && $idleApp->pressed === [],
);

// 非激活键不触发（Enter/Space 之外的键不该激活按钮）
$quietApp = new NavApplication();
$quietApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$quietApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$quietApp->dispatchInput(KeyEvent::down(KeyChord::left()));
check(
    '激活 · 方向键不触发激活（只有 Enter/Space）',
    $quietApp->recorder()->seen === [] && $quietApp->pressed === [],
);

// ── ㉝ 错误边界容器 + AcceleratorTable（任务 #5：M3，O-12 A′ + §9.1 第 ① 环）──

// A. AcceleratorTable 单元（§9.1）
$accel = AcceleratorTable::make()
    ->bind(KeyChord::ctrl('R'), 'users.refresh')
    ->bind(KeyChord::f(5), 'users.refresh');
check(
    'AcceleratorTable · 绑定后可查（Ctrl+R / F5 ⇒ 同一命令）',
    $accel->resolve(KeyChord::ctrl('R')) === 'users.refresh'
        && $accel->resolve(KeyChord::f(5)) === 'users.refresh',
);
check(
    'AcceleratorTable · 未绑定的组合 ⇒ null（调用方继续走焦点链）',
    $accel->resolve(KeyChord::ctrl('N')) === null,
);
check(
    'AcceleratorTable · 修饰键不同 ⇒ 不同组合（Ctrl+R ≠ Ctrl+Shift+R）',
    $accel->resolve(KeyChord::make('R', true, false, true)) === null,
);
check('AcceleratorTable · 计数 2 / 空表 isEmpty', $accel->count() === 2 && AcceleratorTable::make()->isEmpty());

[$code, $output] = runCase(__FILE__, 'accelconflict', null);
check(
    'AcceleratorTable · 同一组合绑两次 ⇒ Fatal（exit 3，G2 冲突不静默）',
    $code === 3,
    '实际 ' . $code . ' / ' . $output,
);
check(
    'AcceleratorTable · 冲突写了 Errors.log',
    str_contains(logText($log), 'AcceleratorTable'),
);

// B. 错误边界 · 构造期（O-12 第 ① 层：createRenderObject 抛 ⇒ rebuild 的 try 捕获）
$ebCtor = ErrorBoundary::make(ConstructBoom::make())->createElement();
$ebCtor->mount(null, $context);
check(
    '错误边界 · 构造期异常被容器捕获（不冒泡，窗口其余部分继续）',
    $ebCtor instanceof ErrorBoundaryElement && $ebCtor->hasCaught(),
);
check(
    '错误边界 · 捕获后子树换成占位（ErrorPlaceholder）',
    $ebCtor->childAt(0)?->widget() instanceof ErrorPlaceholder,
);
check(
    '错误边界 · 占位带错误原文（G2：不吞不改写）',
    str_contains((string) $ebCtor->caughtMessage(), 'construct boom'),
);
$ebCtor->effectiveBox()?->layout(BoxConstraints::tight(new Size(240.0, 80.0)));
check(
    '错误边界 · 占位可布局出墨（Center+Text 真画了东西）',
    inkCount(spinCanvas($ebCtor->effectiveBox(), 240, 80), Color::white()) > 0,
);
// ★ 2026-10-16 改判：确定性测试字体已补上**示例/应用字符串里用到的全部非 ASCII 字符**
//   （`CjkGlyphData`，含中文标点与箭头）⇒ 中文文本现在**有墨**。真正的通用 CJK 后端
//   （任意码位、按需打包）仍归 M4（O-08）；这条断言从此守"补的字模真的上屏"。
//   ★ 旧版是**空断言**：探针盒从未 `layout()` ⇒ `RenderText::lineCount()===0` ⇒ 恒 0 墨，
//     加字之前也是 0 ⇒ 它一直通过，却什么都没测到（量具无自检的又一处）。
$cjkProbe = Text::make('出错')->createElement();
$cjkProbe->mount(null, $context);
$cjkBox = $cjkProbe->effectiveBox();
$cjkBox?->layout(BoxConstraints::tight(new Size(240.0, 80.0)));
$cjkInk = inkCount(spinCanvas($cjkBox, 240, 80), Color::white());
check(
    '字体边界 · 确定性测试字体覆盖示例用字 ⇒ 中文有墨',
    $cjkInk > 0,
    (string) $cjkInk,
);

// C. 错误边界 · build 期（第 ② 层：State::build 抛 ⇒ settle 的 try 捕获）
$ebBuild = ErrorBoundary::make(BoomPage::make())->createElement();
$ebBuild->mount(null, $context);
check(
    '错误边界 · 健康时完全透明（孩子就是原页面）',
    $ebBuild->childAt(0)?->widget() instanceof BoomPage && !$ebBuild->hasCaught(),
);
$boomState = $ebBuild->childAt(0) instanceof StatefulElement ? $ebBuild->childAt(0)->state() : null;
if ($boomState instanceof BoomState) {
    $boomState->boom(); // 标脏 ⇒ 帧末重建 ⇒ 第二次 build 抛
}
$ebBuild->settle();
check(
    '错误边界 · build 期异常被 settle() 捕获（帧末重建路径）',
    $ebBuild->hasCaught() && str_contains((string) $ebBuild->caughtMessage(), 'build boom'),
);
check(
    '错误边界 · build 期捕获后也换占位',
    $ebBuild->childAt(0)?->widget() instanceof ErrorPlaceholder,
);

// D. 错误边界 · 渲染期（第 ③ 层：盒自己兜底，不冒泡）
//    ★ loose 下才看得到"自然尺寸"：tight 的 constrain 会把下界提到上限
//      （Spinner 任务确认过的语义 —— 紧约束下父把尺寸定死），fallback 的
//      160×48 会被撑成 200×200，那是约束语义不是兜底失效。
$rebLayout = new RenderErrorBoundary();
$rebLayout->setChild(new LayoutBoomBox());
$rebLayout->layout(BoxConstraints::loose(new Size(200.0, 200.0)));
check(
    '错误边界 · 渲染期 layout 异常 ⇒ 兜底自然尺寸（160×48，不放大到 INF）',
    $rebLayout->size()?->width === 160.0 && $rebLayout->size()?->height === 48.0,
    sprintf('实际 %.6g × %.6g', $rebLayout->size()?->width ?? -1, $rebLayout->size()?->height ?? -1),
);
$rebTight = new RenderErrorBoundary();
$rebTight->setChild(new LayoutBoomBox());
$rebTight->layout(BoxConstraints::tight(new Size(200.0, 200.0)));
check(
    '错误边界 · tight 约束下兜底地被提到上限（constrain 语义，非失效）',
    $rebTight->size()?->width === 200.0,
);
$rebPaint = new RenderErrorBoundary();
$rebPaint->setChild(new PaintBoomBox());
$rebPaint->layout(BoxConstraints::tight(new Size(100.0, 60.0)));
$paintThrew = false;
try {
    $rebPaint->paint(new RecordingCanvas(new DisplayList()));
} catch (Throwable) {
    $paintThrew = true;
}
check(
    '错误边界 · 渲染期 paint 异常 ⇒ 盒兜底（paint 不冒泡）',
    !$paintThrew,
);
check(
    '错误边界 · paint 兜底有墨（红底叉框真的画了）',
    inkCount(spinCanvas($rebPaint, 100, 60)) > 0,
);

// E. 无边界 ⇒ Fatal 序列（S3 的另半边：帧循环吞异常是 G2 不容的半静默）
[$code, $output] = runCase(__FILE__, 'noboundary', null);
check(
    '帧循环 S3 · 无边界 ⇒ Fatal 序列（exit 3，绝不吞掉冻窗口）',
    $code === 3,
    '实际 ' . $code . ' / ' . $output,
);
check(
    '帧循环 S3 · Fatal 写了 Errors.log（可观测）',
    str_contains(logText($log), '未捕获异常冒到帧末'),
);

// F. 加速键接线（§9.1 第 ① 环：焦点之前）
$accelApp = new AccelApplication();
$accelApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$accelApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('R')));
check(
    '加速键 · Ctrl+R ⇒ 命令走全链（第 ① 环命中，焦点之前）',
    $accelApp->seen === ['accel.fire'],
);
$accelApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
check(
    '加速键 · 未绑定的键不走第 ① 环（Tab 仍归焦点链，seen 不变）',
    $accelApp->seen === ['accel.fire'],
);

// G. 让位规则 ②：焦点控件声明独占 ⇒ 不吃快捷键
$claimApp = new AccelApplication();
$claimApp->withClaimer = true;
$claimApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$claimApp->dispatchInput(KeyEvent::down(KeyChord::tab())); // 焦点落到 TextField（文档序第一个可聚焦）
check(
    '加速键 · 焦点控件声明独占 Ctrl+A ⇒ 让位给输入框（seen 为空）',
    $claimApp->seen === [],
);
$claimApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('R')));
check(
    '加速键 · 未独占的组合照常命中（Ctrl+R 仍走第 ① 环）',
    $claimApp->seen === ['accel.fire'],
);

// ── ㉞ 菜单 / 浮层 / 拖放（任务 #6：O-20 方案 A + §9.1 关闭语义 + §A.3.5）──

$menuSurface = new SvgSurface();
$menuSurface->resize(320, 240, 1.0);
$menuApp = new MenuApplication();
$menuApp->attach($menuSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
$tight320 = BoxConstraints::tight(new Size(320.0, 240.0));

check('浮层 · attach 后默认没开（浮层槽位为空）', !$menuApp->isMenuOpen());

// —— 键盘路径打开：Tab 到菜单标题 ⇒ Enter（Application::activateFocused 的 Menu 分支）
$menuApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$menuApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
check(
    '浮层 · Menu 标题 Enter ⇒ onOpen 被调 + 应用打开面板',
    $menuApp->opened && $menuApp->isMenuOpen(),
);
check(
    '浮层 · 锚点 = 标题 Element（菜单钉在被点的控件旁）',
    $menuApp->anchor !== null && $menuApp->anchor->widget() instanceof Menu,
);
$panelBox = $menuApp->menuElement()?->effectiveBox();
check('浮层 · 面板盒是 RenderPopupPanel（与根树同构：一个 Widget 一个 Element）', $panelBox instanceof RenderPopupPanel);

// —— 渲染：主树先画、浮层后画（顺序即层叠）
$svgBefore = $menuSurface->svg();
$menuApp->frame(16.0, $tight320);
$svgAfter = $menuSurface->svg();
check(
    '浮层 · 渲染多画了一棵（SVG 的 rect 数增加 —— 面板底 / 边框 / 项文本）',
    substr_count($svgAfter, '<rect') > substr_count($svgBefore, '<rect'),
);

// —— 落位：面板左上角 = 锚点左下；夹进客户区（按**面板**矩形算）
[$mx, $my] = $menuApp->menuOrigin();
$titleBox = $menuApp->anchor?->effectiveBox();
$titleBottom = $titleBox === null ? 0.0 : boxOrigin($titleBox)[1] + $titleBox->size()->height;
$panelInset = $panelBox instanceof RenderPopupPanel ? $panelBox->panelRect()->left : 0.0;
check(
    '浮层 · 面板左上角钉在锚点左下（含投影留白补偿）',
    abs(($my + $panelInset) - $titleBottom) < 1.0,
    sprintf('面板 y=%.6g 锚点底=%.6g', $my + $panelInset, $titleBottom),
);
check(
    '浮层 · 面板夹进客户区（右 / 下都不越界）',
    $panelBox !== null
        && $mx + $panelBox->size()->width <= 320.5
        && $my + $panelBox->size()->height <= 240.5,
);

// —— 关闭语义 ①：点面板外 ⇒ 关 + 吞掉该次按下（不穿透）
//    ★ 坐标要同时满足两件事：**在面板矩形之外**、**仍在带 `ContextMenu` 的那行上**
//      —— 面板窄（≈140）而客户区宽 320 ⇒ 取该行右侧（x+200）即可。若穿透，
//      这次右键会顺手打开下面那个菜单（最容易被抓的形态）。
$colBox = $menuApp->rootBox();
$rows = $colBox instanceof RenderFlex ? $colBox->children() : [];
$rowBox = $rows[1] ?? null;
$targetBox = $rows[2] ?? null;
[$rx, $ry] = $rowBox === null ? [0.0, 0.0] : boxOrigin($rowBox);
[$sx, $sy] = $targetBox === null ? [0.0, 0.0] : boxOrigin($targetBox);
$outsideX = $rx + 200.0;

$menuApp->dispatchInput(PointerEvent::down($outsideX, $ry + 10.0, 1));
check(
    '浮层 · 点面板外 ⇒ 关且**吞掉**按下（不穿透到下面的右键菜单）',
    !$menuApp->isMenuOpen(),
    $menuApp->isMenuOpen() ? '穿透了：菜单被重开' : '',
);

// —— 右键最小集：再点一次（此刻无浮层）⇒ 打开命中的 ContextMenu
$menuApp->dispatchInput(PointerEvent::down($rx + 10.0, $ry + 10.0, 1));
check('右键 · 无浮层时右键 ⇒ 打开命中行的 ContextMenu', $menuApp->isMenuOpen());

// —— 关闭语义 ②：命令分发**之后**才关
$dispatched = $menuApp->dispatch('row.del');
check(
    '浮层 · 命令分发之后才关（语义 #2）',
    $dispatched && !$menuApp->isMenuOpen() && $menuApp->seen === ['row.del'],
);

// —— 右键落在无声明处 ⇒ 什么也不做（不报错、不落给焦点）
$menuApp->dispatchInput(PointerEvent::down($sx + 10.0, $sy + 10.0, 1));
check('右键 · 落在无 ContextMenu 声明的行 ⇒ 什么也不做', !$menuApp->isMenuOpen());

// —— 关闭语义 ③：ESC 在 dispatchKey 之前拦
$fileMenu = $menuApp->fileMenu();
$menuApp->showMenu($fileMenu->panel()); // 直接开（不依赖焦点当前在哪儿）
check('浮层 · 重开（ESC 用例的前置）', $menuApp->isMenuOpen());
$menuApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
check('浮层 · ESC ⇒ 关（语义 #3：在 dispatchKey 之前拦，不被焦点控件吃掉）', !$menuApp->isMenuOpen());

// —— 关闭语义 ④：点面板**内** ⇒ 不关
$menuApp->showMenu($fileMenu->panel());
$menuApp->frame(32.0, $tight320); // 走一帧 ⇒ 落位算过（menuOrigin 才准）
[$mx2, $my2] = $menuApp->menuOrigin();
$menuApp->dispatchInput(PointerEvent::down($mx2 + 20.0, $my2 + 20.0));
check('浮层 · 点面板内 ⇒ 不关（指针先归浮层，语义 #1 的另一半）', $menuApp->isMenuOpen());
$menuApp->dispatchInput(KeyEvent::down(KeyChord::escape()));

// —— 应用内拖放（§A.3.5）：状态机可驱动（真机指针序列等 M4）
$dragApp = $menuApp;
$dragApp->beginDrag(Draggable::make('user-7'));
check('拖放 · beginDrag ⇒ 进入拖动态', $dragApp->drag()->isDragging());
$dragApp->updateDrag($sx + 5.0, $sy + 5.0);
check('拖放 · 进入 DragTarget ⇒ onHover(true)', $dragApp->hover === [true]);
$dragApp->updateDrag($sx + 9.0, $sy + 9.0);
check('拖放 · 目标不变 ⇒ 不重复通知（§9.1 语义 #5 同源）', $dragApp->hover === [true]);
$dragApp->updateDrag($rx + 5.0, $ry + 5.0);
check('拖放 · 离开目标 ⇒ onHover(false)', $dragApp->hover === [true, false]);
$dragApp->updateDrag($sx + 5.0, $sy + 5.0);
check(
    '拖放 · drop 把负载交给命中的 DragTarget',
    $dragApp->dropDrag() === true && $dragApp->drop === ['user-7'],
);
check('拖放 · drop 后退出拖动态', !$dragApp->drag()->isDragging());
$dragApp->beginDrag(Draggable::make('user-9'));
$dragApp->updateDrag($rx + 5.0, $ry + 5.0); // 拖到没有目标的地方
check(
    '拖放 · 拖空 ⇒ drop 返回 false（不派发 onAccept）',
    $dragApp->dropDrag() === false && count($dragApp->drop) === 1,
);
$dragApp->beginDrag(Draggable::make('user-9'));
$dragApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
check(
    '拖放 · ESC 取消（不派发 onAccept）',
    !$dragApp->drag()->isDragging() && count($dragApp->drop) === 1,
);

// —— 声明落位（Widget 基类三个新声明位）
check(
    '声明 · draggable / contextMenu / dragTarget 落在 widget 上（可被宿主读回）',
    $rowBox !== null
        && FlatBox::make(10.0, 10.0)->draggable(Draggable::make(1))->draggableValue()?->payload() === 1
        && FlatBox::make(10.0, 10.0)->dragTarget(DragTarget::make())->dragTargetValue() instanceof DragTarget
        && FlatBox::make(10.0, 10.0)->contextMenu(ContextMenu::make())->contextMenuValue() instanceof ContextMenu,
);

// —— Menu 四件的形体
$menu = Menu::make('文件')->items([MenuItem::make('刷新')]);
check('Menu · panel() 是 PopupPanel（浮层形体归 PopupPanel，落位归 App）', $menu->panel() instanceof PopupPanel);
check('Menu · 是一种按钮（命令 / 焦点 / 激活整套复用，同 NavButton 先例）', $menu instanceof Button && $menu->isFocusable());

$item = MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('menu.refresh');
check(
    'MenuItem · 命令 / chord 声明可读',
    $item->commandName() === 'menu.refresh' && $item->chordValue()?->label() === 'Ctrl+R',
);
check('MenuItem · 是一种按钮 + 可聚焦（键盘能选中）', $item instanceof Button && $item->isFocusable());
check('MenuItem · separator 形态', MenuItem::separator()->isSeparator());
check('MenuBar · build 出横排（纯组合控件，自己没有盒）', MenuBar::make()->menus([$menu])->build($context) instanceof Row);
check('ContextMenu · panel() 是 PopupPanel（声明对象，不是 Widget）', ContextMenu::make()->items([
    MenuItem::make('删除'),
])->panel() instanceof PopupPanel);

// —— 菜单项盒：两端分布（label 左 / chord 右）+ 分隔线有墨
//    ★ 约束用 **tight 宽 + loose 高**（与 `RenderPopupPanel` 给内容的一致）——
//      tight 高会把文本挤爆（C6：内容放不下就该报，这里是我们给的约束不对）
$itemBox = $item->createRenderObject($context);
check('MenuItem · 盒是 RenderMenuItem', $itemBox instanceof RenderMenuItem);
$itemBox->layout(new BoxConstraints(180.0, 180.0, 0.0, 60.0));
$rowKids = [];
$itemBox->visitChildren(function (RenderBox $c) use (&$rowKids): void {
    $c->visitChildren(function (RenderBox $inner) use (&$rowKids): void {
        $rowKids[] = $inner;
    });
});
$first = $rowKids[0] ?? null;
$last = $rowKids === [] ? null : $rowKids[count($rowKids) - 1];
check(
    'MenuItem · chord 在行的右端（label 与 chord 两端分布 ⇒ 整行可点）',
    $first !== null && $last !== null && $last->offsetX() > $first->offsetX() + 40.0,
    sprintf('label x=%.6g chord x=%.6g', $first?->offsetX() ?? -1, $last?->offsetX() ?? -1),
);
$sepBox = MenuItem::separator()->createRenderObject($context);
$sepBox->layout(new BoxConstraints(180.0, 180.0, 0.0, 20.0));
check(
    'MenuItem · 分隔线有墨（1px 横线真画了）',
    inkCount(spinCanvas($sepBox, 180, 8)) > 0,
);

// —— PopupPanel：统一宽 = max(minWidth, 最宽项)
//    ★ 要**挂载**后再测：面板的 child 由 Element 层挂接（C1 父管挂接），
//      光对 createRenderObject 的盒 layout 会拿到"没有孩子"的空盒。
$panelProbe = $menu->minWidth(200.0)->panel();
$panelProbeEl = $panelProbe->createElement();
$panelProbeEl->mount(null, $context);
$panelProbeBox = $panelProbeEl->effectiveBox();
$panelProbeBox?->layout(BoxConstraints::unbounded());
check(
    'PopupPanel · 宽 = max(minWidth, 最宽项)（面板统一宽 ⇒ 菜单项整行可点）',
    $panelProbeBox?->size() !== null && $panelProbeBox->size()->width >= 200.0,
    sprintf('实际宽 %.6g', $panelProbeBox?->size()?->width ?? -1),
);

// ── ㉟ apps/users 完整样板（§19 M3 出口判据：§A.2 的完整应用可跑）──

$usersSurface = new SvgSurface();
$usersSurface->resize(900, 640, 1.0);
$usersTight = BoxConstraints::tight(new Size(900.0, 640.0));
$usersApp = new UsersApplication(new MemoryUserSource());
$usersApp->attach($usersSurface, $usersTight);

check(
    'apps/users · 完整应用 attach 后就有盒（§A.2 可跑：值缝+动作缝+多页+表单+加速键）',
    $usersApp->root() !== null && $usersApp->rootBox() !== null,
);
check(
    'apps/users · buildRoot 只调一次（框架不重复调；Root 只组合）',
    $usersApp->rootBuilds() === 1,
);
check(
    'apps/users · 首屏渲染有墨（chrome 条 + 状态栏 + 列表行）',
    substr_count($usersSurface->svg(), '<rect') > 50,
);

// ① 值缝 · **跨页共享**（AppStore：状态栏跨页活着）
$usersApp->dispatch('users.refresh');
check(
    'apps/users · 跨页共享态：刷新 ⇒ 状态栏播报"已刷新"（AppStore.say）',
    $usersApp->appStore()->statusLine() === '已刷新',
);
$usersApp->dispatch('nav.signup');
check('apps/users · 跨页共享态：nav.signup ⇒ currentPage=signup', $usersApp->appStore()->currentPage() === 'signup');
check(
    'apps/users · 跨页共享态：切页后状态栏仍在（共享态不随页销毁）',
    $usersApp->appStore()->statusLine() === '已刷新',
);

// ② 值缝 · **局部态**（SignupForm：property hooks + 派生缓存）
$usersApp->signupForm()->name = '张三';
$usersApp->signupForm()->email = 'zhang@example.com';
check(
    'apps/users · 局部态：表单字段写入（property hooks ⇒ 字段名只写一次）',
    $usersApp->signupForm()->name === '张三' && $usersApp->signupForm()->validate() === [],
);
$usersApp->dispatch('form.submit');
check(
    'apps/users · 局部态：合法表单 ⇒ 提交成功并回跳列表页（form.submit 走全链）',
    $usersApp->appStore()->currentPage() === 'users'
        && $usersApp->appStore()->statusLine() === '注册成功',
);
$usersApp->signupForm()->name = '';
$usersApp->dispatch('form.submit');
check(
    'apps/users · 局部态：空姓名 ⇒ 校验失败回显（走 Store 的 submitError，不走 State）',
    str_contains($usersApp->signupForm()->submitError, '姓名不能为空'),
);

// ③ 动作缝 · 第三页 + 删除 + 派生缓存失效
$usersApp->dispatch('nav.settings');
check('apps/users · 动作缝：nav.settings ⇒ 第三页', $usersApp->appStore()->currentPage() === 'settings');
$rowsBefore = count($usersApp->userStore()->rows());
$usersApp->dispatch('user.del', CommandArg::id(1));
check(
    'apps/users · 动作缝：user.del ⇒ 行数减一 + 状态栏播报',
    count($usersApp->userStore()->rows()) === $rowsBefore - 1
        && $usersApp->appStore()->statusLine() === '已删除',
);
$usersApp->userStore()->setQuery('不存在的名字');
check(
    'apps/users · 派生缓存：搜索 ⇒ visible() 变窄（缓存随依赖失效）',
    $usersApp->userStore()->visible() === [],
);
$usersApp->userStore()->setQuery('');

// ④ 加速键（§19 M3 出口判据：Ctrl+S 等加速键可用）
$usersApp->signupForm()->name = '';
$usersApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('S')));
check(
    'apps/users · 加速键 Ctrl+S ⇒ form.submit 被派发（第 ① 环，焦点之前）',
    str_contains($usersApp->signupForm()->submitError, '姓名不能为空'),
);

// ⑤ 菜单打开接线（Widget 不许依赖 App ⇒ 应用把 showMenu 包成闭包传进来）
$openerInjected = 0;
$probeRoot = UsersRoot::make($usersApp->appStore(), $usersApp->userStore(), $usersApp->signupForm())
    ->menuOpener(function () use (&$openerInjected): void {
        $openerInjected++;
    });
check(
    'apps/users · 菜单 opener 可注入（Root 只组合，打开归应用）',
    $probeRoot->menuOpenerHandler() instanceof Closure,
);
unset($probeRoot);

// ⑥ 菜单端到端：Tab 到菜单标题（焦点链第 4 个）⇒ Enter ⇒ 浮层真的开了
for ($i = 0; $i < 4; $i++) {
    $usersApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
}
$usersApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
check(
    'apps/users · 菜单标题 Enter ⇒ 浮层打开（opener → showMenu 端到端）',
    $usersApp->isMenuOpen(),
);
$usersApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
check('apps/users · ESC 关掉菜单', !$usersApp->isMenuOpen());

// ⑦ 帧驱动（宿主每帧调；浮层不开也照常）
$framesBefore = $usersSurface->frameCount();
$usersApp->frame(16.0, $usersTight);
check(
    'apps/users · frame() 由宿主驱动（表面多一帧）',
    $usersSurface->frameCount() === $framesBefore + 1,
);

// ── ㊱ TaskHost 端口：`Job` / `TaskHost` / `SyncTaskHost`（任务 #8：§17.2 后端 B）──
//
// ★ 后端 A（C++ 线程池）已被**探针 K 判死**（`probes/RESULTS.md` §11：类/对象/属性
//   访问在 worker 线程上必崩，根因 = `php_request_cache` 只在 RINIT 里建）⇒ 这里断言的
//   是**后端 B 的端口契约**（§17.2.3），四件事：
//     ① `Job` 是**类**、参数走构造器、`run()` 无参（§O-03 方案 A）；
//     ② `$onDone` 收到 `run()` 的返回值；
//     ③ **同步回贴** —— 回调在 `submit()` 返回前、同一调用栈上执行
//        （这才是"回调里可以改 Store + notifyChanged"的前提，§17.2.3）；
//     ④ 端口搬得动**对象**，不只是标量（真实形态：`fetchPage()` 返回 `Page`）。
//
// ★ **诚实边界（未验，不假装已验）**：本端口**目前没有生产消费者** —— 设计里它的
//   第一个消费者是 `HttpUserSource`（§A.2.8），归 M4（`apps/users` 在 M3 只发
//   `MemoryUserSource`）。⇒ **AOT 可达性**只由 `drift-codegen` 门（`src/` 在 `sources` 里）
//   覆盖到"翻译层"，**没有真机使用点**。这条缺口记在 §24.2。

$tasks = new SyncTaskHost();
check('TaskHost · `SyncTaskHost` 是端口的实现', $tasks instanceof TaskHost);
check('TaskHost · `SumJob` 是 `Job` 的实现（类，不是闭包）', new SumJob(1) instanceof Job);

// ③ 同步回贴：`submit()` 返回前回调就已执行（同一调用栈 ⇒ "UI 线程"平凡成立）
$delivered = [];
$tasks->submit(new SumJob(10), function (int $sum) use (&$delivered): void {
    $delivered[] = $sum;
});
check(
    'TaskHost · 同步后端 ⇒ 回调在 submit() 返回前执行（同调用栈）',
    $delivered === [55],
    'delivered=' . implode(',', $delivered),
);

// ② 值正确 + 回调**恰好一次**
check('TaskHost · `Job::run()` 的返回值原样回贴（1+…+10 = 55）', $delivered === [55]);
check('TaskHost · 回调恰好一次（不是 0 次也不是 2 次）', count($delivered) === 1);

// 顺序：连提交 ⇒ 回调按提交序（同步后端 = FIFO）
$order = [];
$tasks->submit(new SumJob(1), function (int $n) use (&$order): void { $order[] = 'a' . $n; });
$tasks->submit(new SumJob(3), function (int $n) use (&$order): void { $order[] = 'b' . $n; });
$tasks->submit(new SumJob(6), function (int $n) use (&$order): void { $order[] = 'c' . $n; });
check(
    'TaskHost · 多任务按提交序回贴（同步后端 FIFO）',
    $order === ['a1', 'b6', 'c21'],
    'order=' . implode(',', $order),
);

// ④ 搬对象：闭包签名把 `mixed` 收窄成 `Row` ⇒ 回调拿到的就是 Job 造的那个类实例
$gotRow = null;
$tasks->submit(new TaskRowJob('ada'), function (TaskRow $r) use (&$gotRow): void {
    $gotRow = $r;
});
check(
    'TaskHost · 端口搬得动类实例（`mixed` 由调用点闭包签名收窄）',
    $gotRow instanceof TaskRow && $gotRow->name === 'ada' && $gotRow->len === 3,
    $gotRow instanceof TaskRow ? $gotRow->name . '/' . $gotRow->len : 'null',
);

// ★ 反向自检：`mixed` 收窄不是"随便什么都收" —— 换个类型就该被 PHP 拦下（TypeError）。
$typeError = '';
try {
    $tasks->submit(new SumJob(1), function (TaskRow $r): void {});
} catch (\TypeError $e) {
    $typeError = $e->getMessage();
}
check(
    'TaskHost · 闭包签名收窄真的在生效（传错类型 ⇒ TypeError，不是静默）',
    $typeError !== '',
    $typeError,
);

fresh($log);
if (is_file($log)) {
    unlink($log);
}
