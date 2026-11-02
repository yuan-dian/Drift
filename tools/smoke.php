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

use Drift\Core\Pipeline;
use Drift\Dev\Smoke\BoxProbe;
use Drift\Dev\Smoke\BuildProbe;
use Drift\Dev\Smoke\Layout\BleedBox;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\App\Application;
use Drift\App\FocusManager;
use App\Showcase\ShowcaseApplication;
use App\Tutorial\TutorialApplication;
use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Smoke\Frame\RecordingSurface;
use Drift\Dev\Smoke\Rendering\ProbePainter;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\App\FakeDialog;
use Drift\Dev\Smoke\App\LazyApplication;
use Drift\Dev\Smoke\App\NamedBoomJob;
use Drift\Dev\Smoke\App\NamedExitJob;
use Drift\Dev\Smoke\App\NamedSumJob;
use Drift\Dev\Smoke\App\ModalApplication;
use Drift\Dev\Smoke\App\SmokeJobRegistry;
use Drift\Dev\Smoke\App\TaskRow;
use Drift\Dev\Smoke\App\TaskApplication;
use Drift\Dev\Smoke\App\TaskRowJob;
use Drift\Dev\Smoke\App\ThrowingJob;
use Drift\Dev\Smoke\App\SumJob;
use Drift\App\Completion;
use Drift\Async\Future;
use Drift\App\Io\IoPoller;
use Drift\App\Io\Http;
use Drift\App\Io\HttpException;
use Drift\App\Job;
use Drift\App\FrameClock;
use Drift\App\ProcessTaskHost;
use Drift\App\SyncTaskHost;
use Drift\App\TaskHost;
use App\Users\UsersApplication;
use App\Users\UsersRoot;
use App\Users\Jobs\JobRegistry;
use App\Users\Store\MemoryUserSource;
use App\Users\Store\UserStore;
use Drift\Dev\Smoke\App\SemValueApplication;
use Drift\Dev\Smoke\App\TfApplication;
use Drift\Dev\Smoke\App\NavApplication;
use Drift\Dev\Smoke\App\NamedSleepJob;
use Drift\Dev\Smoke\App\SpinnerApplication;
use Drift\Dev\Smoke\SealProbe;
use Drift\Core\Computed;
use Drift\Errors;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Commands\CommandRouter;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\SemanticsInvokeEvent;
use Drift\Input\SemanticsSetValueEvent;
use Drift\Input\SemanticsRole;
use Drift\Layout\FlexAlign;
use Drift\Layout\Overflow;
use Drift\Layout\RenderAppBar;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderButton;
use Drift\Layout\RenderDock;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderProxy;
use Drift\Layout\RenderCustomPaint;
use Drift\Layout\RenderObject;
use Drift\Layout\RenderTextField;
use Drift\Rendering\BoxStyle;
use Drift\Routing\RouteTable;
use Drift\Rendering\CanvasCapabilities;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\DashPattern;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Gradient;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\MetricToken;
use Drift\Rendering\Modifier;
use Drift\Rendering\Paint;
use Drift\Rendering\Path;
use Drift\Rendering\Shadow;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Rendering\SkiaCanvas;
use Drift\State\Subject;
use Drift\Text\DeterministicFontManager;
use Drift\Text\FontLocator;
use Drift\Text\TtfFace;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Text\FontFamily;
use Drift\Text\FontSlant;
use Drift\Text\FontWeight;
use Drift\Text\Glyph;
use Drift\Text\GlyphRun;
use Drift\Text\Paragraph;
use Drift\Text\TextAlign;
use Drift\Text\TextLayout;
use Drift\Text\TextLine;
use Drift\Text\TextStyle;
use Drift\Theme\SystemTheme;
use Drift\Theme\ThemeData;
use Drift\Widget\Activatable;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Card;
use Drift\Widget\CustomPaint;
use Drift\Widget\SizedBox;
use Drift\Widget\AppBar;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\ComboBox;
use Drift\Widget\Each;
use Drift\Widget\LazyColumn;
use Drift\Widget\Grid;
use Drift\Widget\LazyGrid;
use Drift\Widget\ScrollView;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Element;
use Drift\Widget\Expanded;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Modal;
use Drift\Widget\PopupPanel;
use Drift\Widget\Key;
use Drift\Widget\NavButton;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\Spinner;
use Drift\Widget\SpinnerState;
use Drift\Widget\Checkbox;
use Drift\Widget\Radio;
use Drift\Widget\IconButton;
use Drift\Widget\SegmentedControl;
use Drift\Widget\Slider;
use Drift\Dev\Smoke\App\SliderApplication;
use Drift\Widget\Option;
use Drift\Widget\Select;
use Drift\Widget\Snackbar;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;
use Drift\Widget\Widget;
use Drift\Widget\Table;
use Drift\Dev\TestApp;

require __DIR__ . '/autoload.php';

// ★ 量具自己把上限抬到 2G（2026-10-23，W8-2 Tooltip）。
//   **不是泄漏**：全量 1217 条用例会串起几十个 Application 实例，每个都持有
//   字模 + 渲染树，默认 128M 早在几百条就炸；1024M 也已经贴着边（加 Tooltip 块
//   时在 `attach()` 加载字模处 OOM，差 10MB）。
//   ★ 为什么写在脚本里而不是靠调用方 `-d memory_limit=`：量具的**运行前提**不该
//     依赖调用者的命令行 —— 忘了带参数就得到一条"内存耗尽"的假红。
ini_set('memory_limit', '2048M');

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

    // Table 的 11 个配置面 case（缺 keyOf / fixed(0) / field+cell / 字段拼错 /
    // 受控声明缺上报 ×4 / sort 未知列 / 空列 key / cell() 列可编辑）已迁出：
    //   用例 → `tests/Support/fatal_cases.php`
    //   判据 → `tests/Feature/Table/ConfigFailureTest.php`







    if ($case === 'taskerr') {
        // 后台任务失败且**没给 onError** ⇒ 必须走 Fatal 序列（§17.4.2 的错误通道）：
        // 吞掉就是"界面永远转圈"，与三态的 Error 分支（§A.2.9）矛盾。
        $app = new TaskApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 240.0)));
        $app->tasks()?->submit(new ThrowingJob('没人接的失败'))->then(function (int $v): void {
        });
        $app->frame(16.0, BoxConstraints::loose(new Size(320.0, 240.0)));
    }

    if ($case === 'allerr') {
        // `Future::all()` 里的入参是**真任务** ⇒ 聚合体也是任务：失败且没人
        // `catch()` 必须走 Fatal 序列。修复前聚合体恒被当作"值"（构造即结算的
        // 那一类），于是"组合这一层"成了吞掉失败的暗渠 —— 上层永远转圈、不报错。
        // ★ 这里用 `then` 而不是裸 `all()`：那正是"忘了写 catch"的真实形状。
        $task = Future::pending();
        Future::all([$task])->then(function (array $values): void {
        });
        $task->reject(new RuntimeException('组里的任务失败了，没人接'));
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

    if ($case === 'pathop') {
        // W1/L3-1：`CAP_PATH` 已从 Optional 提档 Extended，Raster 不再响亮失败。
        // ★ 这一例反过来证明**真的画出了像素** —— 否则"提档了但画布是空的"
        //   是同一种静默失败，只是换了个方向。
        $canvas = new RasterCanvas(20, 20, Color::white(), Scale::of(1.0));
        $canvas->drawPath(
            Path::create()->moveTo(2, 2)->lineTo(8, 2)->lineTo(8, 8)->close(),
            Paint::fill(Color::black())
        );
        // 三角形 (2,2)(8,2)(8,8) 的解析面积 = 0.5 × 6 × 6 = 18
        $px = $canvas->toRgba();
        $ink = 0;
        for ($i = 0, $len = strlen($px); $i < $len; $i += 4) {
            if (ord($px[$i]) !== 255) {
                $ink++;
            }
        }
        fwrite(STDOUT, 'ink=' . $ink . "\n");
    }

    if ($case === 'imageop') {
        // W8-1 起 Raster 支持 drawImage ⇒ 这一例从"响亮失败"改成"真出墨"。
        // ★ 只断言退出码不够：画布全白也照样 exit 0（与 drawPath 同一条教训）。
        //   2×2 全黑图铺到 4×4 ⇒ 16 个黑像素。
        // ★ 用**黑**不用红：下面的 ink 判据只看 R 通道（历史口径），纯红的
        //   R=255 会被当成"白" ⇒ ink=0 假 FAIL（本轮踩到）。
        $canvas = new RasterCanvas(20, 20, Color::white(), Scale::of(1.0));
        $canvas->drawImage(
            ImageHandle::fromRgba(str_repeat("\x00\x00\x00\xFF", 4), 2, 2),
            null,
            new Rect(0.0, 0.0, 4.0, 4.0)
        );
        $px = $canvas->toRgba();
        $ink = 0;
        for ($i = 0, $len = strlen($px); $i < $len; $i += 4) {
            if (ord($px[$i]) !== 255) {
                $ink++;
            }
        }
        fwrite(STDOUT, 'ink=' . $ink . "\n");
    }

    if ($case === 'shadowop') {
        // W8-1 之后 Raster 唯一仍不支持的 Optional op（drawShadow）—— 响亮失败照旧
        $canvas = new RasterCanvas(20, 20, Color::white(), Scale::of(1.0));
        $canvas->drawShadow(new Shadow(new Rect(2.0, 2.0, 8.0, 8.0), Color::black(), 0.0, 1.0, 1.0, 2.0));
    }

    if ($case === 'clippathunbalanced') {
        // W1/L3-2 / `05` §10 #5：只 pushClipPath 不 popClip ⇒ W8 必须响亮失败。
        // ★ 补 `DisplayList::record()` 的 `OP_PUSH_CLIP_PATH` 计数之前，这一例的
        //   `clipDepth` 停在 0 ⇒ `assertBalanced()` 放行 ⇒ 后续全被裁而无人
        //   知晓（静默失败）。走子进程：`Errors::report` 默认 FATAL。
        $list = new DisplayList();
        $list->record(
            DisplayList::OP_PUSH_CLIP_PATH,
            Path::create()->addRect(new Rect(0.0, 0.0, 4.0, 4.0))
        );
        $list->assertBalanced();
    }

    if ($case === 'layerunbalanced') {
        // W1/L3-3 / `05` §10 #6b：只 pushLayer 不 popLayer ⇒ W8 必须抓到。
        // ★ 之前 DisplayList 根本不查层深（`Canvas.php` docblock 明写不覆盖）
        //   ⇒ 没人弹掉的乘数让后续绘制整体变透，画面"莫名变淡"且无人知晓。
        $list = new DisplayList();
        $list->record(DisplayList::OP_LAYER_PUSH, 0.5);
        $list->assertBalanced();
    }

    if ($case === 'custompaint') {
        // P2：自绘的轴没给、父容器又不限制这一轴 ⇒ 响亮失败（自绘区不能无穷大）
        $el = (new CustomPaint(new ProbePainter(), 120.0))->createElement();
        $el->mount(null, smokeContext());
        $el->effectiveBox()?->layout(new BoxConstraints(0.0, 300.0, 0.0, INF));
    }

    if ($case === 'gradientbad') {
        // 色标越界 ⇒ 构造期就失败（不等长/越界的色标表在光栅化时会静默错位）
        Gradient::linear(0, 0, 10, 0, [Color::white(), Color::black()], [0.0, 1.5]);
    }

    if ($case === 'shadowtransparent') {
        // 全透明阴影 = 什么都不会留下 ⇒ 直接失败，别留一个 no-op 的调用点
        new Shadow(new Rect(0, 0, 10, 10), new Color(0, 0, 0, 0));
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
$checks = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    ++$checks;
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

/**
 * 盒的**直接孩子**盒列表（子类不是 RenderBox 的跳过）。
 *
 * ★ 与 `tests/Support/functions.php` 同名同实现。原先是卡片段里的局部闭包
 *   `$kidsOf`，却被 700 行后的「侧栏宽度」用例借去 —— 工具不留在断言段里。
 */
function childBoxes(?RenderBox $box): array
{
    $out = [];
    $box?->visitChildren(static function ($child) use (&$out): void {
        if ($child instanceof RenderBox) {
            $out[] = $child;
        }
    });

    return $out;
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

/**
 * 命令流里某一类 op 的条数（**不花像素钱**）。
 *
 * ## 与 `inkCount()` 的分工（2026-10-28）
 *
 * 判"整页排开了没有"过去用 `inkCount() > 0`，但那是**弱判据**：夹具里只要有一个字
 * 就恒真（§20 已记"有墨几乎总是弱判据"）。而"22 个导航项**各上了一次字**"只能数命令
 * —— 它同时是可证伪的：少画一项就少一条。
 *
 * 反过来，**颜色 / 覆盖率 / 抗锯齿**必须读像素（`golden/page` 的 20 张基线就是干这个
 * 的）。两者不是替代关系：
 *
 * | 问题 | 用哪个 |
 * |---|---|
 * | "画了 N 个东西吗"（结构、数量、顺序） | `drawOpCount()` / `list()->count()` |
 * | "画对了吗"（颜色、位置、叠色、AA） | `raster()` + `inkCount()` / `pixelAt()` |
 *
 * ★ 一条 op 的代价 ≈ 0；一次 `raster()` 在 1920×1017 上是 1.5s（回放）+ 1.05s
 *   （`inkCount()` 扫 195 万像素）。**能数命令就别读像素。**
 */
function drawOpCount(DisplayList $list, int $op): int
{
    $count = 0;
    $total = $list->count();
    for ($i = 0; $i < $total; $i++) {
        if ($list->opAt($i) === $op) {
            $count++;
        }
    }

    return $count;
}

/**
 * 把盒画到白底光栅上（§14.1：同输入 ⇒ 同指纹，golden 的物质基础）。
 *
 * ★ 放在工具区而不是某个断言段里：AppBar / Showcase / SegmentedControl / Slider
 *   以及已迁出的 Spinner 都在用。原先长在 Spinner 段里，靠"函数声明在编译期
 *   hoisted"才让前面几百行能用 —— 那是段边界一旦挪动就会断的隐性依赖。
 */
function spinCanvas(RenderBox $box, int $width, int $height): RasterCanvas
{
    $list = new DisplayList();
    $box->paint(new RecordingCanvas($list));

    $canvas = new RasterCanvas($width, $height, Color::white(), Scale::of(1.0));
    $list->replay($canvas);

    return $canvas;
}

/**
 * 盒在客户区的绝对原点（沿父链累加相对偏移）。
 *
 * 菜单 / 右键 / 拖放的测试要"点在某一行上"，而行的位置只能从盒树反推 ——
 * 坐标写死会在任何 padding 变动时静默失准。
 *
 * @return array{0: float, 1: float}
 */
/**
 * 深度优先找第一个满足条件的 Element（`visitChildren` 只走直接子节点 ⇒ 自己递归）。
 */
function findElement(Element $root, callable $pred): ?Element
{
    $found = null;
    $walk = function (Element $el) use (&$walk, &$found, $pred): void {
        if ($found !== null) {
            return;
        }
        if ($pred($el)) {
            $found = $el;
            return;
        }
        $el->visitChildren($walk);
    };
    $walk($root);

    return $found;
}

function boxOrigin(RenderBox $box): array
{
    // ★ 委派 `RenderBox::absoluteOrigin()`，**不要**在这里手抄一遍累加：
    //   W5 之后子盒 offset 住**内容盒**坐标，"内容原点 → 边框盒原点"那一步在
    //   `absoluteOrigin()` 里补。手抄版少补一次 ⇒ `Padding::all(24)` 的字段盒
    //   原点算成 (0,0)，7 条框选/光标断言全红（2026-10-22 实测）。
    return RenderBox::absoluteOrigin($box);
}

// ── 桥的解释器替身：已下沉到 tools/fakes/（smoke 与 PHPUnit 共用一份）────────
// ★ 为什么在 tools/ 而不是 tests/：`tests/Support/bootstrap.php` 已经 require
//   `tools/autoload.php`（方向是 tests → tools）；反过来让 tools/smoke.php 去
//   require tests/ 会造出反向依赖，而 smoke 是能独立跑的开发工具。
require_once __DIR__ . '/fakes/skia.php';
require_once __DIR__ . '/fakes/win32.php';

/**
 * 从 Paragraph 独立算出每个字形的期望跨边界参数（**正确语义**：offsetX/offsetY 施加）。
 *
 * 与 `SkiaCanvas::drawParagraph()` 的循环同公式但独立实现 —— 判据不靠被测代码自己背书。
 * 今天所有字形 offset 恒为 0.0（`DeterministicShaper` / `TextLayout` 的 new Glyph 全部
 * 显式传 0.0，grep 实证）⇒ 本算式与桥记录一致；真塑形（M4）产生非零 offset 后，
 * 本判据会**逼出** Skia 侧漏加 offset 的修复（分歧现状由 P4 钉住）。
 *
 * @return list<array{codepoint:int, penX:float, baselineY:float}>
 */
function skiaExpectedGlyphs(Paragraph $paragraph, float $originX, float $originY, Scale $scale): array
{
    $dpr = $scale->dpr;
    $out = [];
    foreach ($paragraph->lines as $line) {
        $baselineLogical = $originY + $line->baseline;
        $pen = $originX + $line->offsetX;
        foreach ($line->runs as $run) {
            foreach ($run->glyphs as $glyph) {
                $out[] = [
                    'codepoint' => $glyph->codepoint,
                    'penX' => round(($pen + $glyph->offsetX) * $dpr),
                    'baselineY' => round(($baselineLogical + $glyph->offsetY) * $dpr),
                ];
                $pen += $glyph->advance;
            }
        }
    }
    return $out;
}

/**
 * 从 Paragraph 独立算出软光栅墨迹的期望并集 bbox（含端点，房规 [l, r, t, b]）。
 *
 * 与 `RasterCanvas::blendBitmap()` 同公式但独立实现，口径与
 * `deviceInkBounds()` 的「非透明即墨」完全一致 —— **逐位图扫描实际墨区**
 * （`coverageAt() > 0`），位图内墨区偏移叠加 bearing 后再取并集。
 *
 * ★ 踩过（夹具第一次跑抓到自己）：第一版假设整张位图都有墨
 *   （`maxY = top + height − 1`）—— 位图字体字形不占满全高：
 *   U+48 @14px 位图 14 行但实际墨 13 行（底部 1 行全透明）、
 *   @28px 位图 28 行但实际墨 26 行 ⇒ 纵向 maxY 系统性差 1（dpr=2 差 3），
 *   4 条对照全线 FAIL。横向无透明边列（bearingX ≡ 0）故 penX 互比不受影响。
 * 位图在**回放时**才由 `face->rasterize(codepoint, pixelSize)` 产生（确定性）⇒
 * 夹具里再调一次拿到的是同一张；bearingY = −ascent×pixelSize（"位图即 em 盒"）。
 */
function skiaExpectedInkBounds(Paragraph $paragraph, float $originX, float $originY, Scale $scale): ?array
{
    $dpr = $scale->dpr;
    $pixelSize = $paragraph->style->size * $dpr;
    $minX = PHP_INT_MAX;
    $maxX = -1;
    $minY = PHP_INT_MAX;
    $maxY = -1;

    foreach ($paragraph->lines as $line) {
        $baselineLogical = $originY + $line->baseline;
        $pen = $originX + $line->offsetX;
        foreach ($line->runs as $run) {
            foreach ($run->glyphs as $glyph) {
                $bitmap = $run->face->rasterize($glyph->codepoint, $pixelSize);
                if ($bitmap !== null && !$bitmap->isEmpty()) {
                    // 扫描位图实际墨区（coverage > 0 即墨，与 deviceInkBounds 同口径）
                    $inkTop = null;
                    $inkBottom = null;
                    $inkLeft = null;
                    $inkRight = null;
                    for ($y = 0; $y < $bitmap->height; $y++) {
                        for ($x = 0; $x < $bitmap->width; $x++) {
                            if ($bitmap->coverageAt($x, $y) > 0) {
                                if ($inkTop === null) {
                                    $inkTop = $y;
                                }
                                $inkBottom = $y;
                                if ($inkLeft === null || $x < $inkLeft) {
                                    $inkLeft = $x;
                                }
                                if ($inkRight === null || $x > $inkRight) {
                                    $inkRight = $x;
                                }
                            }
                        }
                    }
                    if ($inkTop !== null) {
                        $baseX = (int) round(($pen + $glyph->offsetX) * $dpr);
                        $baseY = (int) round(($baselineLogical + $glyph->offsetY) * $dpr);
                        $left = $baseX + $bitmap->bearingX + $inkLeft;
                        $top = $baseY + $bitmap->bearingY + $inkTop;
                        $minX = min($minX, $left);
                        $maxX = max($maxX, $left + ($inkRight - $inkLeft));
                        $minY = min($minY, $top);
                        $maxY = max($maxY, $top + ($inkBottom - $inkTop));
                    }
                }
                $pen += $glyph->advance;
            }
        }
    }

    return $maxX < 0 ? null : [$minX, $maxX, $minY, $maxY];
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

// —— 真裁剪：`Modifier::clip` / `Overflow::Clip` 落到绘制（2026-10-23）
// ★ 装置是 `BleedBox`（盒 10×10、墨 40×40）：裁与不裁像素**不同**。
//   `SolidBox` 的墨与盒同尺寸 ⇒ 裁与不裁完全相同 = 恒真断言，验不出东西。
$bleedPlain = new BleedBox(new Size(40.0, 40.0));
$bleedPlain->layout(BoxConstraints::tight(new Size(10.0, 10.0)));
$bleedPlainRaster = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$bleedPlain->paint($bleedPlainRaster);
check(
    '裁剪 · 基准：未声明 clip 时墨画到盒外（装置真能溢出）',
    $bleedPlain->size()?->width === 10.0
        && !$bleedPlainRaster->pixelAt(20, 20)->equals(Color::white()),
    sprintf('盒宽 %s（应为 10），盒外 (20,20) %s', (string) $bleedPlain->size()?->width,
        $bleedPlainRaster->pixelAt(20, 20)->equals(Color::white()) ? '是白' : '有墨'),
);

$clipWidget = Text::make('x')->clip();
$clipOn = $clipWidget->mods()->hasClip();
$clipWidget->clip(false);
check(
    '裁剪 · Widget::clip() 写进 mods，clip(false) 可关',
    $clipOn && !$clipWidget->mods()->hasClip(),
);
check(
    '裁剪 · clip 计入 isDefault（漏了 ⇒ syncBox 的"修饰无处可去"守卫失效）',
    (new Modifier())->isDefault() && !(new Modifier())->withClip(true)->isDefault(),
);

$bleedClip = new BleedBox(new Size(40.0, 40.0));
$bleedClip->setMods((new Modifier())->withClip(true));
$bleedClip->layout(BoxConstraints::tight(new Size(10.0, 10.0)));
$bleedClipRaster = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$bleedClip->paint($bleedClipRaster);
check(
    '裁剪 · Widget::clip() 后盒外是背景',
    $bleedClipRaster->pixelAt(20, 20)->equals(Color::white()),
    sprintf('(20,20) rgb(%d,%d,%d)', $bleedClipRaster->pixelAt(20, 20)->r,
        $bleedClipRaster->pixelAt(20, 20)->g, $bleedClipRaster->pixelAt(20, 20)->b),
);
check(
    '裁剪 · 盒内仍有墨（不是整块被裁没了）',
    !$bleedClipRaster->pixelAt(5, 5)->equals(Color::white()),
);

// —— 容器声明驱动：`Overflow::Clip` 从"只是不报错"变成"真的裁"
$flexClip = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
$flexClip->addChild(new BleedBox(new Size(40.0, 40.0)));
$flexClip->layout(BoxConstraints::tight(new Size(10.0, 10.0)));
$flexClipRaster = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$flexClip->paint($flexClipRaster);
check(
    '裁剪 · Overflow::Clip 的 Flex 真的裁（注释承诺的"子项被裁掉"兑现了）',
    $flexClipRaster->pixelAt(20, 20)->equals(Color::white()),
);

// Report 不裁：子被夹到 10 ⇒ mainSize 不超、不报 Fatal，但墨仍溢出 ⇒ 默认行为不变
$flexReport = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Report);
$flexReport->addChild(new BleedBox(new Size(40.0, 40.0)));
$flexReport->layout(BoxConstraints::tight(new Size(10.0, 10.0)));
$flexReportRaster = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$flexReport->paint($flexReportRaster);
check(
    '裁剪 · Overflow::Report 不裁（默认行为与加钩子之前一致）',
    !$flexReportRaster->pixelAt(20, 20)->equals(Color::white()),
);

// 装饰（阴影）在裁剪**之外**：同一帧里"墨被裁、影子还在"
$bleedShadow = new BleedBox(new Size(40.0, 40.0));
$bleedShadow->setMods((new Modifier())->withClip(true)->withShadow(0.6));
$bleedShadow->layout(BoxConstraints::tight(new Size(20.0, 20.0)));
// ★ 不能经 `DisplayList` 回放到 Raster：录制层的 `capabilities()` 是**全能力**
//   （契约："回放时裁决"），于是它会录下 `drawShadow`（Optional，仅 Skia）⇒
//   Raster 回放时 `unsupportedOp` 响亮失败。本用例要测的是"阴影画在裁剪之外"，
//   直接在 Raster 上画同样成立（且自动走三层降级）。
$shadowRaster = new RasterCanvas(60, 60, Color::white(), Scale::of(1.0));
$shadowRaster->save();
$shadowRaster->translate(10.0, 10.0);
$bleedShadow->paint($shadowRaster);
$shadowRaster->restore();
check(
    '裁剪 · 阴影不被裁（盒 (10,10)-(30,30)，(8,20) 处仍有影子）',
    !$shadowRaster->pixelAt(8, 20)->equals(Color::white()),
    sprintf('(8,20) rgb(%d,%d,%d)', $shadowRaster->pixelAt(8, 20)->r,
        $shadowRaster->pixelAt(8, 20)->g, $shadowRaster->pixelAt(8, 20)->b),
);
check(
    '裁剪 · 同一帧里墨在盒外被裁（(35,35) 在墨内、盒外 ⇒ 白）',
    $shadowRaster->pixelAt(35, 35)->equals(Color::white()),
);

check(
    '裁剪 · clipsOverflow 口径（Flex/Dock 看声明，普通盒默认不裁）',
    $flexClip->clipsOverflow() === true
        && $flexReport->clipsOverflow() === false
        && (new RenderDock(Overflow::Clip))->clipsOverflow() === true
        && (new RenderDock(Overflow::Report))->clipsOverflow() === false
        && $bleedPlain->clipsOverflow() === false,
);

// —— chrome 在裁剪**之外**（真机抓到的：第一版把 chrome 留在 `paintContent()` 里，
//    `users` 顶条底部那条 1px 灰线整行消失，diff 983 像素）
// ★ 判据用**命令顺序**而不是像素：描边是内描边（像素 9 有墨、10 没有），
//   靠像素分不出"被裁"与"本来就不画"。`paintOwnChrome()` 必须在 `pushClip` 之前。
$bar = new RenderAppBar(
    BoxStyle::none()->withBackground(Color::black())->withBorder(Color::black(), 2.0),
    0.0,
    new EdgeInsets(),
);
$bar->layout(BoxConstraints::tight(new Size(10.0, 10.0)));
$barList = new DisplayList();
$bar->paint(new RecordingCanvas($barList));
$barClipAt = -1;
$barChromeAt = [];
for ($bi = 0; $bi < $barList->count(); $bi++) {
    $bop = $barList->opAt($bi);
    if ($bop === DisplayList::OP_PUSH_CLIP) {
        $barClipAt = $bi;
    } elseif ($bop === DisplayList::OP_DRAW_RECT) {
        $barChromeAt[] = $bi;
    }
}
check(
    '裁剪 · chrome（背景/边框）画在 pushClip 之前 ⇒ 不被自己裁掉',
    $barClipAt > 0 && count($barChromeAt) === 2 && max($barChromeAt) < $barClipAt,
    sprintf('drawRect 在 [%s]，pushClip 在 %d', implode(',', $barChromeAt), $barClipAt),
);

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

// ── P. 段落路径 conformance：drawParagraph 与软光栅逐字形同构 ──────────
// ★ drawParagraph 是 Canvas 唯一还没有跨后端覆盖的绘制方法（⑫ 只有 Raster 侧）。
//   判据 = 跨后端**几何**同构：桥收到的每个字形（penX / baselineY / codepoint /
//   family / rgba）与软光栅的墨迹位置逐一对得上 —— 与 drawRect 的"设备矩形 ==
//   墨迹 bbox"同款思路，只是比较单位从矩形换成字形。
// ★ 位图字体的 bearingX ≡ 0（"位图即 em 盒"，P1 钉住）⇒ 字形原点 = 墨迹左沿
//   ⇒ 桥收到的 penX 与软光栅墨迹左沿**可直接互比**。bearingY = −ascent×pixelSize
//   是后端特有量（位图字体 vs 真字体），不进互比，只各自钉（与两后端抗锯齿
//   差异同档）。
// ★ baseline 由排版给（size=14 实测 13.65）。修复前 Skia 发**不取整**的浮点、
//   软光栅 round 后落位图（同类分歧、两后端各自钉）；2026-10-17 修字形 offset 时
//   **一并对齐**：Skia 也改"逻辑空间叠加后一次 round"（与 blendBitmap 逐字一致）
//   ⇒ 两后端 baseline 现在**同解、可互比**（反例自检证明：换回旧版 SkiaCanvas
//   ⇒ P1/P3 的两条 baseline 断言也 FAIL，共 5 条）。
$pFontManager = DeterministicFontManager::standard();
$pMetrics = new DeterministicMetricsProvider($pFontManager->collection(), new DeterministicShaper());
$pBody = TextStyle::of(14.0);
$pUnit = Scale::of(1.0);

// ── P1. 单字形跨后端同构（dpr=1，画布平移 (7,5)）──
// 平移必须被文本吃掉（RasterCanvas 类注释：不跟 ⇒ Center 算好的偏移画不出来，
// "矩形跟着变换、文字不跟"是最难发现的半边错）。
skiaBridgeReset();
$p1Paragraph = $pMetrics->layout('A', $pBody, INF, $pUnit);
$p1List = new DisplayList();
$p1Rec = new RecordingCanvas($p1List);
$p1Rec->save();
$p1Rec->translate(7.0, 5.0);
$p1Rec->drawParagraph($p1Paragraph, Paint::fill($red), 0.0, 0.0);
$p1Rec->restore();
$rasterP1 = new RasterCanvas(40, 30, null, $pUnit);
$skiaP1 = new SkiaCanvas(40, 30, null, $pUnit);
$p1List->replay($rasterP1);
$p1List->replay($skiaP1);
$glyphsP1 = skiaGlyphCalls();
$inkP1 = deviceInkBounds($rasterP1);
$expectP1 = skiaExpectedGlyphs($p1Paragraph, 7.0, 5.0, $pUnit);
$p1Face = $p1Paragraph->lines[0]->runs[0]->face;
check(
    'Skia conformance · P1 桥恰好一次 draw_glyph（codepoint=U+41 / family=sans / rgba=红）',
    count($glyphsP1) === 1
        && $glyphsP1[0][6] === 0x41
        && $glyphsP1[0][1] === $p1Face->familyName()
        && $glyphsP1[0][7] === $red->toArgb(),
    count($glyphsP1) === 1
        ? 'cp=U+' . dechex($glyphsP1[0][6]) . ' family=' . $glyphsP1[0][1] . ' rgba=' . $glyphsP1[0][7]
        : '次数=' . count($glyphsP1)
);
check(
    'Skia conformance · P1 平移被文本吃掉：penX=(7+0)、baselineY=(5+13.65)、fontSize=14',
    count($glyphsP1) === 1 && count($expectP1) === 1
        && $glyphsP1[0][4] === $expectP1[0]['penX']
        && $glyphsP1[0][5] === $expectP1[0]['baselineY']
        && $glyphsP1[0][3] === 14.0,
    count($glyphsP1) === 1
        ? '桥 penX=' . $glyphsP1[0][4] . ' baselineY=' . $glyphsP1[0][5] . ' fontSize=' . $glyphsP1[0][3]
        : '次数=' . count($glyphsP1)
);
$p1Bitmap = $p1Face->rasterize(0x41, 14.0);
check(
    'Skia conformance · P1 前置条件：位图字体 bearingX ≡ 0（字形原点 = 墨迹左沿，跨后端才可直接互比）',
    $p1Bitmap !== null && $p1Bitmap->bearingX === 0,
    $p1Bitmap === null ? '位图为 null' : 'bearingX=' . $p1Bitmap->bearingX
);
check(
    'Skia conformance · P1 跨后端同构：桥收到的 penX == 软光栅墨迹左沿',
    count($glyphsP1) === 1 && $inkP1 !== null && (float) $inkP1[0] === $glyphsP1[0][4],
    '墨迹左沿=' . fmtInk($inkP1) . ' 桥 penX=' . (count($glyphsP1) === 1 ? (string) $glyphsP1[0][4] : '无')
);
check(
    'Skia conformance · P1 软光栅墨迹 bbox == 独立算式（含 bearingY 的纵向定位）',
    $inkP1 === skiaExpectedInkBounds($p1Paragraph, 7.0, 5.0, $pUnit),
    'ink=' . fmtInk($inkP1) . ' 期望=' . fmtInk(skiaExpectedInkBounds($p1Paragraph, 7.0, 5.0, $pUnit))
);

// ── P2. 多字形步进与并集墨迹（dpr=1）──
// 步进 = 逐字 advance 累加（不是等距）——桥的 penX 序列与墨迹并集双向钉住。
skiaBridgeReset();
$p2Paragraph = $pMetrics->layout('Hello', $pBody, INF, $pUnit);
$p2List = new DisplayList();
(new RecordingCanvas($p2List))->drawParagraph($p2Paragraph, Paint::fill($red), 0.0, 0.0);
$rasterP2 = new RasterCanvas(80, 24, null, $pUnit);
$skiaP2 = new SkiaCanvas(80, 24, null, $pUnit);
$p2List->replay($rasterP2);
$p2List->replay($skiaP2);
$glyphsP2 = skiaGlyphCalls();
$inkP2 = deviceInkBounds($rasterP2);
$expectP2 = skiaExpectedGlyphs($p2Paragraph, 0.0, 0.0, $pUnit);
$p2Pens = [];
$p2ExpectPens = [];
foreach ($glyphsP2 as $g) {
    $p2Pens[] = $g[4];
}
foreach ($expectP2 as $e) {
    $p2ExpectPens[] = $e['penX'];
}
check(
    'Skia conformance · P2 五个字形全部过桥，penX 按 advance 逐步进（不是等距）',
    count($glyphsP2) === 5 && $p2Pens === $p2ExpectPens,
    '桥 penX=[' . implode(',', $p2Pens) . '] 期望=[' . implode(',', $p2ExpectPens) . ']'
);
// ★ 判据从 "==" 放宽成 ">="（§24.2 #21）：拉丁字模改成 32 网格、位图宽取自真字体
//   advance 之后，`H` 的字形在**位图内**左边留了 1 列空白（真字体的 side bearing）。
//   `bearingX` 仍是 0（位图左沿 == pen），所以墨迹左沿 = penX + 空列 ⇒ 只会 ≥ penX。
//   "精确相等"由下一条（并集 bbox == 独立算式）保证 —— 那条会同时钉住空列宽度。
check(
    'Skia conformance · P2 跨后端同构：墨迹左沿 ≥ 首字形 penX（side bearing 在位图内）',
    $inkP2 !== null && count($glyphsP2) === 5 && $inkP2[0] >= (int) $glyphsP2[0][4],
    'ink=' . fmtInk($inkP2) . ' 首字形 penX=' . (count($glyphsP2) === 5 ? (string) $glyphsP2[0][4] : '无')
);
// ★ 期望值只算**一次**（落变量）：放进 $cond 和诊断串各算一次时，两次求值
//   在灰度降采样字模下会不一致（诊断串打印两边相同却 FAIL，最难查）
$p2ExpectInk = skiaExpectedInkBounds($p2Paragraph, 0.0, 0.0, $pUnit);
check(
    'Skia conformance · P2 软光栅并集墨迹 bbox == 独立算式（末字形右沿由步进推出）',
    $inkP2 === $p2ExpectInk,
    'ink=' . fmtInk($inkP2) . ' 期望=' . fmtInk($p2ExpectInk)
);

// ── P3. 多行基线 + dpr=2（同一份命令流，两个缩放）──
// 'Hello Drift' 在 40 逻辑宽断两行（⑪ 钉过断行形状：行0='Hello'、行1='Drift'，
// 各 5 字形，空格不成字形）；dpr=2 用**同一份** DisplayList 回放 —— 段落是
// 缩放无关的值对象，dpr 只由画布施加（⑫ 同款口径）。
skiaBridgeReset();
$p3Paragraph = $pMetrics->layout('Hello Drift', $pBody, 40.0, $pUnit);
$p3List = new DisplayList();
(new RecordingCanvas($p3List))->drawParagraph($p3Paragraph, Paint::fill($red), 0.0, 0.0);
$rasterP3 = new RasterCanvas(90, 80, null, $pUnit);
$skiaP3 = new SkiaCanvas(90, 80, null, $pUnit);
$p3List->replay($rasterP3);
$p3List->replay($skiaP3);
$glyphsP3 = skiaGlyphCalls();
$inkP3 = deviceInkBounds($rasterP3);
$expectP3 = skiaExpectedGlyphs($p3Paragraph, 0.0, 0.0, $pUnit);
check(
    'Skia conformance · P3 两行各自过桥：前 5 字形 baseline = 行0(13.65)、后 5 = 行1(30.45)',
    count($glyphsP3) === 10
        && $glyphsP3[0][5] === $expectP3[0]['baselineY']
        && $glyphsP3[4][5] === $expectP3[4]['baselineY']
        && $glyphsP3[5][5] === $expectP3[5]['baselineY']
        && $glyphsP3[9][5] === $expectP3[9]['baselineY']
        && $glyphsP3[5][5] > $glyphsP3[0][5],
    count($glyphsP3) === 10
        ? '行0 baseline=' . $glyphsP3[0][5] . ' 行1 baseline=' . $glyphsP3[5][5]
        : '次数=' . count($glyphsP3)
);
check(
    'Skia conformance · P3 软光栅并集墨迹 bbox == 独立算式（两行都在内）',
    $inkP3 === skiaExpectedInkBounds($p3Paragraph, 0.0, 0.0, $pUnit),
    'ink=' . fmtInk($inkP3) . ' 期望=' . fmtInk(skiaExpectedInkBounds($p3Paragraph, 0.0, 0.0, $pUnit))
);
// dpr=2：同一份命令流回放到 2× 画布 —— 位置 ×2、字号 ×2、墨迹随之（⑫ 同款口径）
skiaBridgeReset();
$rasterP3x2 = new RasterCanvas(180, 160, null, Scale::of(2.0));
$skiaP3x2 = new SkiaCanvas(180, 160, null, Scale::of(2.0));
$p3List->replay($rasterP3x2);
$p3List->replay($skiaP3x2);
$glyphsP3x2 = skiaGlyphCalls();
$inkP3x2 = deviceInkBounds($rasterP3x2);
$expectP3x2 = skiaExpectedGlyphs($p3Paragraph, 0.0, 0.0, Scale::of(2.0));
check(
    'Skia conformance · P3 dpr=2：fontSize=28、末位 penX 与行 baseline 全部 ×2',
    count($glyphsP3x2) === 10
        && $glyphsP3x2[0][3] === 28.0
        && $glyphsP3x2[4][4] === $expectP3x2[4]['penX']
        && $glyphsP3x2[0][5] === $expectP3x2[0]['baselineY']
        && $glyphsP3x2[5][5] === $expectP3x2[5]['baselineY'],
    count($glyphsP3x2) === 10
        ? 'fontSize=' . $glyphsP3x2[0][3] . ' 行0末 penX=' . $glyphsP3x2[4][4]
            . ' 行0 baseline=' . $glyphsP3x2[0][5] . ' 行1 baseline=' . $glyphsP3x2[5][5]
        : '次数=' . count($glyphsP3x2)
);
// ★ 同 P2：side bearing 在位图内 ⇒ 墨迹左沿 ≥ penX；精确值由 bbox 那条钉住。
//   期望值只算一次（放进 $cond 与诊断串各算一次会重复 rasterize 整段文本）。
$p3ExpectInk = skiaExpectedInkBounds($p3Paragraph, 0.0, 0.0, Scale::of(2.0));
check(
    'Skia conformance · P3 dpr=2 跨后端同构：墨迹左沿 ≥ 桥 penX（含 side bearing），且并集 bbox == 独立算式',
    $inkP3x2 !== null && count($glyphsP3x2) === 10
        && $inkP3x2[0] >= (int) $glyphsP3x2[0][4]
        && $inkP3x2 === $p3ExpectInk,
    'ink=' . fmtInk($inkP3x2) . ' 期望=' . fmtInk($p3ExpectInk)
);

// ── P4. 字形 offset 施加（2026-10-17 修复后：两后端同解）────────────────

// ★ 合成一个带 offset 的段落（offsetX=3, offsetY=2）。今天的生产者造不出非零
//   offset —— `DeterministicShaper` 与 `TextLayout` 的 new Glyph(...) 全部显式
//   传 0.0（grep 实证）⇒ 曾是与 popClip 发现前同型的**潜伏**分歧。
// ★ 历史（2026-10-17 修复）：SkiaCanvas 第一版只发 `round(pen × dpr)`，offset
//   被丢；RasterCanvas 施加 `pen + offsetX`（blendBitmap 入参，round 在函数内）。
//   SkiaCanvas 类注释"与 RasterCanvas::drawParagraph 逐行对齐"本就是承诺 ⇒ 修 Skia。
// ★ 修法：penDevice = round((pen+offsetX)×dpr)、baseline 改逻辑值后 glyph 级
//   加 offsetY 再 round —— 与 blendBitmap 的"逻辑空间叠加后一次量化"逐字一致。
//   本断言原钉"Skia 丢弃 offset"现状（桥 penX=0、baselineY=13.65、分歧差 3px），
//   修复后翻正：桥 penX=3、baselineY=16、两后端墨迹左沿差 0px。
// ★ 必须 reset（P3 dpr=2 的 10 次调用还在记录里）—— 踩过：漏 reset ⇒
//   桥次数 11（10+1）、P4 三条断言全 FAIL。夹具用例互相污染的又一例。
skiaBridgeReset();
$p4Face = $pFontManager->faces()[0];
$p4Advance = $p4Face->glyphAdvance(0x41) * $pBody->size;
$p4Glyph = new Glyph(0x41, $p4Advance, 3.0, 2.0, 0);
$p4Run = new GlyphRun([$p4Glyph], $p4Face, $pBody, $p4Advance, 0, 1);
$p4Line = new TextLine([$p4Run], 0.0, 13.65, 16.8, $p4Advance, 0.0, 0, 1);
$p4Paragraph = new Paragraph('A', [$p4Line], $p4Advance, 16.8, $pBody, INF);
$p4List = new DisplayList();
(new RecordingCanvas($p4List))->drawParagraph($p4Paragraph, Paint::fill($red), 0.0, 0.0);
$rasterP4 = new RasterCanvas(40, 30, null, $pUnit);
$skiaP4 = new SkiaCanvas(40, 30, null, $pUnit);
$p4List->replay($rasterP4);
$p4List->replay($skiaP4);
$glyphsP4 = skiaGlyphCalls();
$inkP4 = deviceInkBounds($rasterP4);
check(
    'Skia conformance · P4 Raster 施加 offset（正确语义）：墨迹左沿 = round((0+3)×1) = 3',
    $inkP4 !== null
        && $inkP4[0] === 3
        && $inkP4 === skiaExpectedInkBounds($p4Paragraph, 0.0, 0.0, $pUnit),
    'ink=' . fmtInk($inkP4) . ' 期望=' . fmtInk(skiaExpectedInkBounds($p4Paragraph, 0.0, 0.0, $pUnit))
);
check(
    'Skia conformance · P4 Skia 施加 offset（修复后）：桥 penX=3、baselineY=16（先加后 round）',
    count($glyphsP4) === 1 && $glyphsP4[0][4] === 3.0 && $glyphsP4[0][5] === 16.0,
    count($glyphsP4) === 1
        ? '桥 penX=' . $glyphsP4[0][4] . ' baselineY=' . $glyphsP4[0][5]
        : '次数=' . count($glyphsP4)
);
check(
    'Skia conformance · P4 分歧已修：两后端墨迹左沿差 0px（桥 penX 与墨迹左沿同解）',
    $inkP4 !== null && count($glyphsP4) === 1 && (float) $inkP4[0] - $glyphsP4[0][4] === 0.0,
    '墨迹左沿 − 桥 penX = ' . ($inkP4 !== null && count($glyphsP4) === 1 ? (string) ((float) $inkP4[0] - $glyphsP4[0][4]) : 'n/a')
);

// ── 段落路径量具反例自检（§20 纪律 1）───────────────────────────────
// 合成已知违规件：假桥把 draw_glyph 的 penX 报错一格（等价于"SkiaCanvas
// 算错了字形笔位"），同款互比判据必须变**假**。抓不到 = 判据恒真、空转。
// 紧接的正对照：同一输入不篡改时判据必须为**真**。
// ★ 判据的判别力来自两条独立产出路径：墨迹左沿由 RasterCanvas 出、桥 penX
//   由假桥出 ⇒ 篡改桥侧、墨迹侧不动 ⇒ 必然不等。
skiaBridgeReset();
$GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'] = true;
$listSP = new DisplayList();
(new RecordingCanvas($listSP))->drawParagraph($pMetrics->layout('A', $pBody, INF, $pUnit), Paint::fill($red), 0.0, 0.0);
$rasterSP = new RasterCanvas(40, 30, null, $pUnit);
$skiaSP = new SkiaCanvas(40, 30, null, $pUnit);
$listSP->replay($rasterSP);
$listSP->replay($skiaSP);
$inkSP = deviceInkBounds($rasterSP);
$glyphsSPBad = skiaGlyphCalls();
check(
    'Skia conformance · 段落反例自检：桥把 penX 篡改一格 ⇒ 互比判据变假（否则判据恒真、夹具空转）',
    $inkSP !== null && count($glyphsSPBad) === 1 && (float) $inkSP[0] !== $glyphsSPBad[0][4],
    'ink=' . fmtInk($inkSP) . ' 篡改后桥 penX=' . (count($glyphsSPBad) === 1 ? (string) $glyphsSPBad[0][4] : '无')
);
$GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'] = false;
skiaBridgeReset();
$skiaSP2 = new SkiaCanvas(40, 30, null, $pUnit);
$listSP->replay($skiaSP2);
$glyphsSPGood = skiaGlyphCalls();
check(
    'Skia conformance · 段落反例自检正对照：同一输入不篡改 ⇒ 判据为真',
    $inkSP !== null && count($glyphsSPGood) === 1 && (float) $inkSP[0] === $glyphsSPGood[0][4],
    'ink=' . fmtInk($inkSP) . ' 桥 penX=' . (count($glyphsSPGood) === 1 ? (string) $glyphsSPGood[0][4] : '无')
);


// ⑩ 响亮失败：颜色越界 / W8 未闭合
fresh($log);
[$code, $output] = runCase(__FILE__, 'color', null);
check('Rendering · Color 越界退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);

fresh($log);
[$code, $output] = runCase(__FILE__, 'w8', null);
check('Rendering · W8 未闭合退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Rendering · W8 未闭合写了 Errors.log', str_contains(logText($log), 'W8'));

// ⑩+1 19 op 回植 · P0 契约（2026-10-20，D2 分层进 Canvas）
fresh($log);
$capsRaster = new RasterCanvas(8, 8, Color::white(), Scale::of(1.0));
check(
    'Canvas · Raster capabilities() = Core + Extended + CAP_IMAGE（W1 三步 + W8-1 图像 blit）',
    $capsRaster->capabilities() === (CanvasCapabilities::CAP_ELLIPSE | CanvasCapabilities::CAP_LINE
        | CanvasCapabilities::CAP_GRADIENT | CanvasCapabilities::CAP_DASH
        | CanvasCapabilities::CAP_CLIP_PATH | CanvasCapabilities::CAP_PATH
        | CanvasCapabilities::CAP_LAYER | CanvasCapabilities::CAP_IMAGE
        | CanvasCapabilities::CAP_BACKDROP)
);
$capsSkia = new SkiaCanvas(8, 8, null, Scale::of(1.0));
check(
    'Canvas · Skia capabilities() = Core + Extended + Optional + CLIP_PATH（P4 全 op 走 SkShader/Skia 原语）',
    $capsSkia->capabilities() === (CanvasCapabilities::CAP_ELLIPSE | CanvasCapabilities::CAP_LINE
        | CanvasCapabilities::CAP_GRADIENT | CanvasCapabilities::CAP_DASH
        | CanvasCapabilities::CAP_PATH | CanvasCapabilities::CAP_IMAGE
        | CanvasCapabilities::CAP_SHADOW | CanvasCapabilities::CAP_LAYER
        | CanvasCapabilities::CAP_CLIP_PATH | CanvasCapabilities::CAP_BACKDROP)
);
$capsRec = RecordingCanvas::create();
// ★ 默认值在 2026-10-25 改成"回放端（Raster）的支持面"：`RecordingCanvas` 的真实
//   消费者只有离屏回放，默认 ALL_MASK 会让它录下 Optional op（drawShadow）然后
//   回放时响亮失败（smoke 的 Card / 浮层段当场全崩）。
check(
    'Canvas · Recording 默认能力 = Raster 支持面（离屏回放端，不录 Optional）',
    $capsRec->capabilities() === RasterCanvas::supportedCapabilities()
);
check(
    'Canvas · Recording 显式传 ALL_MASK ⇒ 全录（"回放裁决"模式仍可用）',
    (new RecordingCanvas(new DisplayList(), CanvasCapabilities::ALL_MASK))->capabilities()
        === CanvasCapabilities::ALL_MASK
);

// ── W8-1 图像（drawImage / PNG 解码 / ImageLoader / Image 控件）已迁出 ───────
//   · `tests/Unit/Rendering/DrawImageTest.php`（8）—— 1:1 逐像素 / 源矩形裁剪 /
//     整数放大最近邻 / 越界夹紧 / 半透明 = blend 同式 / replay 与直调等价 /
//     矩形裁剪 / 反例（全透明·源越界·0 面积）一个像素不改
//   · `tests/Unit/Rendering/PngDecoderTest.php`（8）—— 五种滤波 / 色彩类型 0·2·3（含 tRNS）/
//     16 位取高字节 / 1 位 MSB 打包 / 真文件 quad.png / 反例五条各留一条诊断
//   · `tests/Feature/Rendering/ImageLoaderTest.php`（2）—— 候选目录 + 缓存命中同一实例 /
//     绝对路径原样使用
//   · `tests/Feature/Widget/ImageTest.php`（8）—— 自然尺寸 / 只给宽按比例 / 内禀 = 实排 /
//     Contain letterbox / Cover **裁源**（量具换成了 `OP_DRAW_IMAGE` 的实参：被测对象
//     产出的是命令流，"是不是裁源"在这层就定死了）/ asset 走 Loader / 缺图 1×1 占位 /
//     内存句柄
//   ★ 真资源路径改用 `repoRoot()`：原写法 `__DIR__ . '/../assets'` 只在 `tools/` 下成立。
// ── W1/L3-1 路径（`05` §3 / §12.2 #1–#4）──────────────────────────────────
// ★ 这一波的四条夹具全都要"量与已知几何的差"，因为 drawPath 是**新算法**
//   （扫描线 + 奇偶），而新算法最贵的失败是"画出来但形状不对"。
// ★ 数的是**非背景**像素：背景是不透明白，数 alpha>0 会把背景也算成墨
//   （第一版量具就这么错的，全画布都是 40000）。
$pathInkCount = static function (RasterCanvas $canvas): int {
    $px = $canvas->toRgba();
    $n = 0;
    for ($i = 0, $len = strlen($px); $i < $len; $i += 4) {
        if (ord($px[$i]) !== 255 || ord($px[$i + 1]) !== 255 || ord($px[$i + 2]) !== 255) {
            $n++;
        }
    }

    return $n;
};

// ① 填充：三角形 (20,20)(180,20)(100,160)，解析面积 = 0.5 × 160 × 140 = 11200
$triCanvas = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$triCanvas->drawPath(
    Path::create()->moveTo(20.0, 20.0)->lineTo(180.0, 20.0)->lineTo(100.0, 160.0)->close(),
    Paint::fill(Color::black())
);
$triInk = $pathInkCount($triCanvas);
check(
    'Path · 填充的墨量 = 解析面积 ±5%（三角形 11200）',
    abs($triInk - 11200) <= 560,
    'ink=' . $triInk . ' 偏差=' . sprintf('%+.2f%%', ($triInk - 11200) / 112.0)
);

// ② 奇偶重叠：两个 80×80 错开 40 的矩形 ⇒ 交集区**不填**
//    两个矩形并集 11200，但奇偶填的是**对称差** = 11200 − 2×1600 = 9600
$evenOddCanvas = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$evenOddCanvas->drawPath(
    Path::create()
        ->addRect(new Rect(20.0, 20.0, 80.0, 80.0))
        ->addRect(new Rect(60.0, 60.0, 80.0, 80.0)),
    Paint::fill(Color::black())
);
$evenOddInk = $pathInkCount($evenOddCanvas);
check(
    'Path · 奇偶规则：两个重叠子路径的交集不填（对称差 9600）',
    abs($evenOddInk - 9600) <= 480,
    'ink=' . $evenOddInk . '（若 ≈11200 就是按并集填了，奇偶没生效）'
);
// 交集正中的像素必须是背景色
check(
    'Path · 奇偶交集正中没有墨',
    $evenOddCanvas->pixelAt(80, 80)->r === 255,
    'pixel(80,80)=' . $evenOddCanvas->pixelAt(80, 80)->r
);

// ③ 描边：斜线 (20,180)→(180,20)，宽 10 ⇒ 面积 ≈ 线长 × 宽 = 226.27 × 10 ≈ 2263
$strokeCanvas = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$strokeCanvas->drawPath(
    Path::create()->moveTo(20.0, 180.0)->lineTo(180.0, 20.0),
    Paint::stroke(Color::black(), 10.0)
);
$strokeInk = $pathInkCount($strokeCanvas);
check(
    'Path · 斜线描边的墨量 ≈ 线长 × 宽度（2263 ±10%）',
    abs($strokeInk - 2263) <= 227,
    'ink=' . $strokeInk
);
// ★ 闭合路径描边是**环**：外矩形 130² − 内矩形 110² = 4800
$ringCanvas = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$ringCanvas->drawPath(
    Path::create()->addRect(new Rect(40.0, 40.0, 120.0, 120.0)),
    Paint::stroke(Color::black(), 10.0)
);
$ringInk = $pathInkCount($ringCanvas);
check(
    'Path · 闭合描边是环（外 130² − 内 110² = 4800 ±5%）',
    abs($ringInk - 4800) <= 240,
    'ink=' . $ringInk . '（若 ≈14400 就是把矩形填满了，描边没挖洞）'
);
// 环正中心必须空
check(
    'Path · 闭合描环的中心是空的',
    $ringCanvas->pixelAt(100, 100)->r === 255,
    'pixel(100,100)=' . $ringCanvas->pixelAt(100, 100)->r
);

// ④ 成本上界：500 边 × ~1000 行 = 50 万 > 20 万 ⇒ 报 1 条 DIAGNOSTIC，画面照画
$budgetCanvas = new RasterCanvas(1200, 1200, Color::white(), Scale::of(1.0));
$budgetPath = Path::create();
for ($bi = 0; $bi < 500; $bi++) {
    $ang = $bi * 2.0 * M_PI / 500.0;
    $bx = 600.0 + 500.0 * cos($ang);
    $by = 600.0 + 500.0 * sin($ang);
    if ($bi === 0) {
        $budgetPath->moveTo($bx, $by);
    } else {
        $budgetPath->lineTo($bx, $by);
    }
}
$budgetPath->close();
$budgetBefore = count(Errors::recentDiagnostics());
$budgetCanvas->drawPath($budgetPath, Paint::fill(Color::black()));
$budgetAfter = count(Errors::recentDiagnostics());
check(
    'Path · 成本上界：边数 × 行数 > 20 万 ⇒ 1 条 DIAGNOSTIC',
    $budgetAfter - $budgetBefore >= 1,
    '+' . ($budgetAfter - $budgetBefore) . ' 条'
);
// ★ 报了但不中止：500 边形面积 = π × 500² ≈ 785398
$budgetInk = $pathInkCount($budgetCanvas);
check(
    'Path · 成本上界报完照画（500 边形 ≈ 785398 ±1%）',
    abs($budgetInk - 785398) <= 7854,
    'ink=' . $budgetInk
);

// ⑤ pushClipPath 圆形裁剪：填满屏的矩形 + 64 边形近似圆 ⇒ 只有圆内有墨，四角是白的
//    64 边形面积 = 0.5 × 64 × 60² × sin(2π/64) ≈ 11300（圆的 π×60² = 11310，差 0.08%）
$clipCircle = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$ringPath = Path::create();
for ($ci = 0; $ci < 64; $ci++) {
    $ang = $ci * 2.0 * M_PI / 64.0;
    $cx = 100.0 + 60.0 * cos($ang);
    $cy = 100.0 + 60.0 * sin($ang);
    if ($ci === 0) {
        $ringPath->moveTo($cx, $cy);
    } else {
        $ringPath->lineTo($cx, $cy);
    }
}
$ringPath->close();
$clipCircle->pushClipPath($ringPath);
$clipCircle->drawRect(new Rect(0.0, 0.0, 200.0, 200.0), Paint::fill(Color::black()));
$clipInk = $pathInkCount($clipCircle);
check(
    'Path · pushClipPath 圆形裁剪的墨量 = 64 边形面积 ±5%（11300）',
    abs($clipInk - 11300) <= 565,
    'ink=' . $clipInk . '（若 =40000 就是裁剪没生效，矩形画满了全屏）'
);
check(
    'Path · 圆形裁剪的四角是白的',
    $clipCircle->pixelAt(2, 2)->r === 255 && $clipCircle->pixelAt(197, 2)->r === 255
        && $clipCircle->pixelAt(2, 197)->r === 255 && $clipCircle->pixelAt(197, 197)->r === 255,
    '角点 r=' . $clipCircle->pixelAt(2, 2)->r . '/' . $clipCircle->pixelAt(197, 197)->r
);
check('Path · 圆形裁剪的圆心有墨', $clipCircle->pixelAt(100, 100)->r === 0);
// popClip 之后必须恢复：再画一个全屏矩形 ⇒ 全黑（40000）
//   ★ 这一条同时验证"裁剪栈存的是 [clip, rows] 一对"：只恢复 Rect 不恢复
//     行掩码的话，popClip 之后圆外仍然是白的（裁剪没弹掉）。
$clipCircle->popClip();
$clipCircle->drawRect(new Rect(0.0, 0.0, 200.0, 200.0), Paint::fill(Color::black()));
check(
    'Path · popClip 后裁剪弹掉（全屏再填 ⇒ 40000 全黑）',
    $pathInkCount($clipCircle) === 40000,
    'ink=' . $pathInkCount($clipCircle)
);

// ⑥ pushClipPath 嵌套：两个 r=60、圆心距 60 的圆求交 ⇒ 透镜形
//    透镜面积 = 2r²·arccos(d/2r) − (d/2)·√(4r²−d²) = 2400π − 30√10800 ≈ 4422
$clipNest = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$nestA = Path::create();
$nestB = Path::create();
for ($ci = 0; $ci < 64; $ci++) {
    $ang = $ci * 2.0 * M_PI / 64.0;
    if ($ci === 0) {
        $nestA->moveTo(70.0 + 60.0 * cos($ang), 100.0 + 60.0 * sin($ang));
        $nestB->moveTo(130.0 + 60.0 * cos($ang), 100.0 + 60.0 * sin($ang));
    } else {
        $nestA->lineTo(70.0 + 60.0 * cos($ang), 100.0 + 60.0 * sin($ang));
        $nestB->lineTo(130.0 + 60.0 * cos($ang), 100.0 + 60.0 * sin($ang));
    }
}
$nestA->close();
$nestB->close();
$clipNest->pushClipPath($nestA);
$clipNest->pushClipPath($nestB);
$clipNest->drawRect(new Rect(0.0, 0.0, 200.0, 200.0), Paint::fill(Color::black()));
$nestInk = $pathInkCount($clipNest);
check(
    'Path · pushClipPath 嵌套 = 两个裁剪求交（透镜 4422 ±5%）',
    abs($nestInk - 4422) <= 221,
    'ink=' . $nestInk . '（若 ≈11300 就是只生效了一个裁剪，嵌套没求交）'
);
// A 圆单独覆盖、B 圆不覆盖的一点必须是白的；两圆交集内心必须是黑的
check('Path · 嵌套裁剪：只在 A 圆内的一点是白的', $clipNest->pixelAt(30, 100)->r === 255);
check('Path · 嵌套裁剪：交集内心有墨', $clipNest->pixelAt(100, 100)->r === 0);
$clipNest->popClip();
$clipNest->popClip();
$clipNest->drawRect(new Rect(0.0, 0.0, 200.0, 200.0), Paint::fill(Color::black()));
check(
    'Path · 嵌套 popClip 两次后恢复（全屏再填 ⇒ 40000）',
    $pathInkCount($clipNest) === 40000,
    'ink=' . $pathInkCount($clipNest)
);

// ⑦ pushClipPath 不配对：只 push 不 pop ⇒ W8 抓得到（`05` §10 #5）
//    ★ 子进程：`Errors::report` 默认 FATAL。补 `DisplayList::record()` 的
//      `OP_PUSH_CLIP_PATH` 计数之前，这一例的 clipDepth 停在 0、抓不到。
fresh($log);
[$code, $output] = runCase(__FILE__, 'clippathunbalanced', null);
check(
    'Path · pushClipPath 不配对 ⇒ W8 退出码 3（补 OP_PUSH_CLIP_PATH 计数前抓不到）',
    $code === 3,
    '实际 ' . $code . ' / ' . $output
);
check('Path · pushClipPath 不配对写了 Errors.log', str_contains(logText($log), 'W8'));

// ⑧ pushLayer 单层 opacity：白底上画 50% 黑 ⇒ source-over 混合值
//    alpha = round(255 × 0.5) = 128 ⇒ dst = round((0×128 + 255×127)/255) = 127
$layerCanvas = new RasterCanvas(60, 60, Color::white(), Scale::of(1.0));
$layerCanvas->pushLayer(0.5);
$layerCanvas->drawRect(new Rect(0.0, 0.0, 60.0, 60.0), Paint::fill(Color::black()));
$layerCanvas->popLayer();
$mid = $layerCanvas->pixelAt(30, 30);
check(
    'Path · pushLayer 单层 opacity = 0.5 ⇒ 黑白混合 ≈127',
    abs($mid->r - 127) <= 2 && abs($mid->g - 127) <= 2 && abs($mid->b - 127) <= 2,
    'rgb=' . $mid->r . ',' . $mid->g . ',' . $mid->b
);

// ⑨ 重叠近似（**记录为已知近似，不是断言正确**）：层内两个重叠子各乘一次
//    ⇒ 重叠处比单层更透。这正是 Raster 乘数栈与 Skia 真离屏的唯一差异
//    （`05` §5.6 的表），夹具的价值是"把有意的差异钉住"。
$overCanvas = new RasterCanvas(60, 60, Color::white(), Scale::of(1.0));
$overCanvas->pushLayer(0.5);
$overCanvas->drawRect(new Rect(0.0, 0.0, 40.0, 60.0), Paint::fill(Color::black()));
$overCanvas->drawRect(new Rect(20.0, 0.0, 40.0, 60.0), Paint::fill(Color::black()));
$overCanvas->popLayer();
// 左段（只被第一个矩形覆盖）= 一次 50% 黑 ≈ 127；中段（两个都覆盖）= 两次 ≈ 63
$left = $overCanvas->pixelAt(5, 30);
$overlap = $overCanvas->pixelAt(30, 30);
check(
    'Path · pushLayer 重叠子：重叠处比单层更透（已知近似，05 §5.6）',
    abs($left->r - 127) <= 2 && abs($overlap->r - 63) <= 3 && $overlap->r < $left->r - 40,
    '左段=' . $left->r . ' 重叠=' . $overlap->r
);

// ⑦b pushLayer 不配对：只 push 不 pop ⇒ W8 抓得到（`05` §10 #6b）
fresh($log);
[$code, $output] = runCase(__FILE__, 'layerunbalanced', null);
check(
    'Path · pushLayer 不配对 ⇒ W8 退出码 3（补 layerDepth 计数前完全不查）',
    $code === 3,
    '实际 ' . $code . ' / ' . $output
);
check('Path · pushLayer 不配对写了 Errors.log', str_contains(logText($log), 'W8'));

// ⑩+3 19 op 回植 · P3 渐变/虚线（RasterCanvas，确定性后端；Skia 侧在 Skia 桥落地后补断言）
$fb = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$grad = Gradient::linear(0.0, 0.0, 40.0, 0.0, [Color::fromRgb(255, 0, 0), Color::fromRgb(0, 0, 255)]);
$fb->drawRect(new Rect(0.0, 0.0, 40.0, 40.0), Paint::fill(Color::black())->withGradient($grad));
$cLeft = $fb->pixelAt(2, 20);
$cRight = $fb->pixelAt(38, 20);
check(
    'P3 · 线性渐变横向铺开：左红右蓝（两端色标被真采样，不是实色）',
    $cLeft->r > 150 && $cLeft->b < 80 && $cRight->b > 150 && $cRight->r < 80,
    '左(r=' . $cLeft->r . ',b=' . $cLeft->b . ') 右(r=' . $cRight->r . ',b=' . $cRight->b . ')'
);

// 径向渐变：圆心红、外圈蓝
$fr = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$gradR = Gradient::radial(20.0, 20.0, 20.0, [Color::fromRgb(255, 0, 0), Color::fromRgb(0, 0, 255)]);
$fr->drawRect(new Rect(0.0, 0.0, 40.0, 40.0), Paint::fill(Color::black())->withGradient($gradR));
$cCenter = $fr->pixelAt(20, 20);
$cEdge = $fr->pixelAt(1, 20);
check(
    'P3 · 径向渐变：圆心红、边缘蓝（半径方向被真采样）',
    $cCenter->r > 150 && $cCenter->b < 80 && $cEdge->b > 150 && $cEdge->r < 80,
    '心(r=' . $cCenter->r . ',b=' . $cCenter->b . ') 边(r=' . $cEdge->r . ',b=' . $cEdge->b . ')'
);

// 线段虚线：on/off 交替（中段有空洞，不是实线）
$fl = new RasterCanvas(60, 8, Color::white(), Scale::of(1.0));
$fl->drawLine(2.0, 4.0, 58.0, 4.0, Paint::stroke(Color::black(), 4.0)->withDash(new DashPattern(8.0, 8.0)));
$on = 0;
$off = 0;
for ($x = 2; $x <= 58; $x += 2) {
    $p = $fl->pixelAt((int) $x, 4);
    if ($p->r < 40 && $p->g < 40 && $p->b < 40) {
        $on++;
    } else {
        $off++;
    }
}
check(
    'P3 · 线段虚线：on/off 交替（中段有空洞，不是实线）',
    $on > 0 && $off > 0,
    "on=$on off=$off"
);

// 矩形描边虚线退化为实线（R2 降级）：整条描边都画出来，无空洞。
// ★ drawRect 描边是"整宽内缩"（已知口径，椭圆才是居中）：矩形 (2,2) 的顶部描边带
//   在 y∈[2,5)（边框 y=2 向内缩 w=3）。采样 y=3 落在此带内；y=1 在框外、洞内是白。
$fr2 = new RasterCanvas(40, 20, Color::white(), Scale::of(1.0));
$fr2->drawRect(new Rect(2.0, 2.0, 36.0, 16.0), Paint::stroke(Color::black(), 3.0)->withDash(new DashPattern(6.0, 6.0)));
$gap = 0;
for ($x = 4; $x <= 36; $x += 2) {
    $p = $fr2->pixelAt((int) $x, 3);
    if ($p->r > 200 && $p->g > 200 && $p->b > 200) {
        $gap++;
    }
}
check(
    'P3 · 矩形描边虚线退化为实线（R2 降级 ⇒ 描边无空洞）',
    $gap === 0,
    "gap=$gap"
);

// DisplayList 回放：录制侧原样持有 Paint ⇒ 渐变/虚线不许在管道里被"规范化"掉
$recG = RecordingCanvas::create();
$recG->drawRect(new Rect(0.0, 0.0, 40.0, 40.0), Paint::fill(Color::black())->withGradient(
    Gradient::linear(0.0, 0.0, 40.0, 0.0, [Color::fromRgb(255, 0, 0), Color::fromRgb(0, 0, 255)])
));
$cg = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$recG->displayList()->replay($cg);
$pl = $cg->pixelAt(2, 20);
$pr = $cg->pixelAt(38, 20);
check(
    'P3 · DisplayList 回放不丢渐变（管道只搬运 Paint，属性不许被规范化掉）',
    $pl->r > 200 && $pl->b < 60 && $pr->b > 200 && $pr->r < 60,
    "左={$pl->r},{$pl->g},{$pl->b} 右={$pr->r},{$pr->g},{$pr->b}"
);

// P3 · Skia 端（生产后端，走 SkShader/SkDashPathEffect）。
// ★ 解释器面没有真 Skia（`drift_skia_read_pixels` 的假桥返回空串）⇒ 像素断言不成立。
//   按仓库既有惯例断言**桥收到了什么**（⑨+1 conformance：ABI 形状即契约）；
//   真像素由 T2 的 AOT 构建 + 真机探针兜。
$redArgb = Color::fromRgb(255, 0, 0)->toArgb();
$blueArgb = Color::fromRgb(0, 0, 255)->toArgb();

skiaBridgeReset();
$fsk = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$fsk->drawRect(new Rect(0.0, 0.0, 40.0, 40.0), Paint::fill(Color::black())->withGradient(
    Gradient::linear(0.0, 0.0, 40.0, 0.0, [Color::fromRgb(255, 0, 0), Color::fromRgb(0, 0, 255)])
));
$opsG = skiaCallOps();
check(
    'P3 · Skia 渐变：桥收到 set_gradient → draw_rect → clear_source（源不泄漏到下次 draw）',
    $opsG === ['create', 'set_gradient', 'draw_rect', 'clear_source'],
    implode(',', $opsG)
);
$callG = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
check(
    'P3 · Skia set_gradient 参数（线性 / 端点设备坐标 / 2 色标 / 补位不越界）',
    $callG === ['set_gradient', 0, 0.0, 0.0, 40.0, 0.0, 2, $redArgb, $blueArgb, $blueArgb, $blueArgb, 0.0, 1.0, 1.0, 1.0],
    var_export($callG, true)
);

// 径向：radial 位 = 1，且 x2 是**长度量**（半径只缩放不平移）⇒ (20,20) 圆心 + 半径 10
skiaBridgeReset();
$fskR = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$fskR->drawEllipse(new Rect(10.0, 10.0, 20.0, 20.0), Paint::fill(Color::black())->withGradient(
    Gradient::radial(20.0, 20.0, 10.0, [Color::fromRgb(255, 0, 0), Color::fromRgb(0, 0, 255)])
));
$callR = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
check(
    'P3 · Skia 径向渐变：radial=1 且半径只缩放不平移（(20,20) r=10）',
    $callR[0] === 'set_gradient'
        && $callR[1] === 1
        && $callR[2] === 20.0 && $callR[3] === 20.0 && $callR[4] === 10.0 && $callR[5] === 0.0,
    var_export($callR, true)
);

// 虚线：on/off/phase 原样过桥
skiaBridgeReset();
$fskD = new SkiaCanvas(60, 8, null, Scale::of(1.0));
$fskD->drawLine(2.0, 4.0, 58.0, 4.0, Paint::stroke(Color::black(), 4.0)->withDash(new DashPattern(8.0, 8.0, 2.0)));
$opsD = skiaCallOps();
check(
    'P3 · Skia 虚线：桥收到 set_dash → draw_line → clear_source',
    $opsD === ['create', 'set_dash', 'draw_line', 'clear_source'],
    implode(',', $opsD)
);
check(
    'P3 · Skia set_dash 参数（on=8 / off=8 / phase=2 原样过桥）',
    $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1] === ['set_dash', 8.0, 8.0, 2.0],
    var_export($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1], true)
);

// 反例自检：没有渐变/虚线时**一个桥调用都不多发**（热路径不为不存在的源付账，
// 也顺带守住"source 状态不跨 draw 泄漏"这条——clear 只在真挂了时才发）
skiaBridgeReset();
$fskP = new SkiaCanvas(20, 20, null, Scale::of(1.0));
$fskP->drawRect(new Rect(0.0, 0.0, 20.0, 20.0), Paint::fill(Color::black()));
$fskP->drawLine(0.0, 0.0, 20.0, 20.0, Paint::stroke(Color::black(), 2.0));
check(
    'P3 · Skia 无 source 时不发 set_*/clear_source（不多调、也不泄漏）',
    skiaCallOps() === ['create', 'draw_rect', 'draw_line'],
    implode(',', skiaCallOps())
);
skiaBridgeReset();

// ── ⑩+4 19 op 回植 · P4 Optional / Extended 复合 op（Skia 桥结构 golden）──
// ★ 解释器面没有真 Skia（read_pixels 假桥返回空串）⇒ 不像素断言；断言**桥收到了什么**
//   （ABI 形状即契约，⑨+1 conformance 惯例）。真像素由 T2 AOT 构建 + 真机探针兜。
// 路径几何走画布 translate() ⇒ 打包的 verbs/coords 是绘制时坐标系（已含偏移），
// 这里偏移 0，所以直接比对命令表即可。
skiaBridgeReset();
$fp = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$pathP = Path::create()->moveTo(2.0, 2.0)->lineTo(6.0, 2.0)->quadTo(8.0, 4.0, 6.0, 6.0)->close();
$fp->drawPath($pathP, Paint::fill(Color::black()));
$opsP = skiaCallOps();
check(
    'P4 · Skia drawPath：桥收到 draw_path（count=4，verbs=MOVE/LINE/QUAD/CLOSE）',
    $opsP === ['create', 'draw_path'],
    implode(',', $opsP)
);
$callP = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
$expectedVerbs = chr(Path::CMD_MOVE) . chr(Path::CMD_LINE) . chr(Path::CMD_QUAD) . chr(Path::CMD_CLOSE);
check(
    'P4 · Skia drawPath 打包：count=4 且 verbs 字节序正确',
    $callP[0] === 'draw_path' && $callP[1] === 4 && $callP[2] === $expectedVerbs,
    var_export($callP, true)
);
check(
    'P4 · Skia drawPath 不挂源时不发 set_*/clear_source',
    count($opsP) === 2,
    implode(',', $opsP)
);
skiaBridgeReset();

// 图像：RGBA 字节串长度必须等于 w*h*4（与 ImageHandle 构造期校验同判据）
skiaBridgeReset();
$imgBytes = str_repeat("\x80\x40\x20\xFF", 8 * 6); // 8×6 半透明橙
$fimg = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$fimg->drawImage(ImageHandle::fromRgba($imgBytes, 8, 6), null, new Rect(1.0, 1.0, 8.0, 6.0));
$opsI = skiaCallOps();
$callI = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
check(
    'P4 · Skia drawImage：桥收到 draw_image 且 rgba 长度 = w*h*4',
    $opsI === ['create', 'draw_image'] && $callI[1] === 192 && $callI[2] === 8 && $callI[3] === 6,
    var_export($callI, true)
);
check(
    'P4 · Skia drawImage 整幅缩放（hasSrc=false，dst 设备坐标命中 1,1,8,6）',
    $callI[12] === false && $callI[8] === 1.0 && $callI[9] === 1.0 && $callI[10] === 8.0 && $callI[11] === 6.0,
    var_export($callI, true)
);
skiaBridgeReset();

// 阴影：标量原样过桥（rect 经 toDevice，这里偏移 0 ⇒ 直接 10,10,20,20）
skiaBridgeReset();
$fsh = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$fsh->drawShadow(new Shadow(new Rect(10.0, 10.0, 20.0, 20.0), Color::fromRgb(0, 0, 0), 4.0, 2.0, 3.0, 5.0));
$callSh = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
$expectedShadowArgb = Color::fromRgb(0, 0, 0)->toArgb();
check(
    'P4 · Skia drawShadow：桥收到标量（rect + argb + radius + 偏移 + blur 原样过桥）',
    $callSh[0] === 'draw_shadow' && $callSh[1] === 10.0 && $callSh[2] === 10.0
        && $callSh[3] === 20.0 && $callSh[4] === 20.0 && $callSh[5] === $expectedShadowArgb
        && $callSh[6] === 4.0 && $callSh[7] === 2.0 && $callSh[8] === 3.0 && $callSh[9] === 5.0,
    var_export($callSh, true)
);
skiaBridgeReset();

// 合成层 / 路径裁剪：op 顺序与参数
skiaBridgeReset();
$fly = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$fly->pushLayer(0.5);
$fly->pushClipPath($pathP);
$fly->popLayer();
$opsL = skiaCallOps();
check(
    'P4 · Skia 层/路径裁剪：桥收到 push_layer → push_clip_path → pop_layer',
    $opsL === ['create', 'push_layer', 'push_clip_path', 'pop_layer'],
    implode(',', $opsL)
);
$callL = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
check(
    'P4 · Skia push_layer 参数（opacity=0.5 原样过桥）',
    $callL[0] === 'push_layer' && $callL[1] === 0.5,
    var_export($callL, true)
);
$callCP = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][2];
check(
    'P4 · Skia push_clip_path 打包（count=4，verbs 与 drawPath 同）',
    $callCP[0] === 'push_clip_path' && $callCP[1] === 4 && $callCP[2] === $expectedVerbs,
    var_export($callCP, true)
);
skiaBridgeReset();

// ★★ 回归闸门：路径坐标必须**叠加画布偏移并乘 dpr**（与 drawLine / toDevice 同口径）。
//   第一版照"几何走画布 translate()"的注释写 ⇒ 路径落在画布原点 ⇒ 被自绘盒裁掉
//   ⇒ 真机完全无墨，而阴影/层/图像都正常（它们各自加了偏移）。纯 PHP 缺陷，这里能抓。
skiaBridgeReset();
$foff = new SkiaCanvas(80, 80, null, Scale::of(2.0));
$foff->translate(10.0, 20.0);
$foff->drawPath(
    Path::create()->moveTo(1.0, 2.0)->lineTo(3.0, 4.0)->close(),
    Paint::fill(Color::black())
);
$callOff = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
$expectCoords = pack('f4', 22.0, 44.0, 0.0, 0.0)
    . pack('f4', 26.0, 48.0, 0.0, 0.0)
    . pack('f4', 0.0, 0.0, 0.0, 0.0);
check(
    'P4 · Skia drawPath 坐标叠加偏移并乘 dpr（(1+10)*2=22 / (2+20)*2=44）',
    $callOff[0] === 'draw_path' && $callOff[3] === $expectCoords,
    var_export($callOff[3], true)
);
skiaBridgeReset();

// Path 构造
$p19 = Path::create()->moveTo(2, 2)->lineTo(6, 2)->quadTo(8, 4, 6, 6)->close();
check('Path · 命令数与非空', $p19->commandCount() === 4 && !$p19->isEmpty());
$cmds19 = $p19->commands();
check('Path · MOVE 首命令带坐标', $cmds19[0][0] === Path::CMD_MOVE && $cmds19[0][1] === 2.0);
check('Path · QUAD 携带控制点与终点', $cmds19[2][0] === Path::CMD_QUAD && $cmds19[2][3] === 6.0 && $cmds19[2][4] === 6.0);
check('Path · addRect 四边闭合', Path::create()->addRect(new Rect(0, 0, 4, 3))->commandCount() === 5);

// Paint 的 source 正交维度（渐变/虚线是画笔属性，不是新 draw 方法）
$plain19 = Paint::fill(Color::white());
$graded19 = $plain19->withGradient(Gradient::linear(0, 0, 10, 0, [Color::white(), Color::black()]));
check('Paint · withGradient 不动原对象（值语义）', !$plain19->hasGradient() && $graded19->hasGradient());
check('Paint · withColor 保留渐变', $graded19->withColor(Color::fromRgb(255, 0, 0))->hasGradient());
$dashed19 = $plain19->withDash(new DashPattern(4.0, 2.0));
check('Paint · withDash 周期 = on+off', $dashed19->hasDash() && $dashed19->dash->period() === 6.0);
check('Paint · equals 感知渐变/虚线差异', !$graded19->equals($plain19));
check(
    'Paint · equals 对同构渐变成立（== 值语义而非实例同一）',
    $graded19->equals(Paint::fill(Color::white())->withGradient(Gradient::linear(0, 0, 10, 0, [Color::white(), Color::black()])))
);

// interning：同色 `fill()` 复用同一实例（字段全 readonly ⇒ 共享安全，`analysis/17` §6.6）
check(
    'Paint · fill() 同色 interned（同一 argb 复用实例，省每帧几十个对象）',
    Paint::fill(Color::fromRgb(0x11, 0x22, 0x33)) === Paint::fill(Color::fromRgb(0x11, 0x22, 0x33)),
);
check(
    'Paint · fill() 不同色不串（interning 的键是 argb）',
    Paint::fill(Color::fromRgb(0x11, 0x22, 0x33)) !== Paint::fill(Color::fromRgb(0x11, 0x22, 0x34)),
);
check(
    'Paint · stroke() 不进 interning（同色不同 style 不能共用实例）',
    Paint::fill(Color::fromRgb(0x11, 0x22, 0x33)) !== Paint::stroke(Color::fromRgb(0x11, 0x22, 0x33)),
);

// DisplayList 6 槽与新 opcode（LINE 是 5 操作数的命令）
$list19 = new DisplayList();
$list19->record(DisplayList::OP_DRAW_LINE, 1.0, 2.0, 3.0, 4.0, $plain19);
check('DisplayList · 6 槽 LINE 的 y2/paint 定位', $list19->count() === 1 && $list19->argAt(0, 3) === 4.0 && $list19->argAt(0, 4) === $plain19);

// W1/L3-1：drawPath 在 Raster 上从"响亮失败"翻成"真的画得出像素"
[$code, $output] = runCase(__FILE__, 'pathop', null);
check(
    'Canvas · Raster drawPath 退出码 = 0（W1/L3-1 起 CAP_PATH 已提档，不再响亮失败）',
    $code === 0,
    '实际 ' . $code . ' / ' . $output
);
preg_match('/ink=(\d+)/', $output, $pathInkMatch);
$pathInk = isset($pathInkMatch[1]) ? (int) $pathInkMatch[1] : -1;
// ★ 只断言退出码不够：画布全白也照样 exit 0。三角形解析面积 18（±3 容硬边量化）
check('Canvas · Raster drawPath 真出墨（不是"提档了但画布空"）', $pathInk >= 15 && $pathInk <= 21, 'ink=' . $pathInk);
fresh($log);

// drawImage 在 W8-1 提档：不再响亮失败，且**真出墨**（4×4 全红 = 16 像素）
[$code, $output] = runCase(__FILE__, 'imageop', null);
check(
    'Canvas · Raster drawImage 退出码 = 0（W8-1 起 CAP_IMAGE 已提档）',
    $code === 0,
    '实际 ' . $code . ' / ' . $output
);
preg_match('/ink=(\d+)/', $output, $imgInkMatch);
$imgInk = isset($imgInkMatch[1]) ? (int) $imgInkMatch[1] : -1;
check('Canvas · Raster drawImage 真出墨（4×4 = 16 像素，不是"提档了但画布空"）', $imgInk === 16, 'ink=' . $imgInk);
fresh($log);

// 仍然不支持的 Optional op：响亮失败的消息格式照旧（drawShadow 是最后一个）
[$code, $output] = runCase(__FILE__, 'shadowop', null);
check('Canvas · Raster drawShadow 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
$msg = logText($log);
check('Canvas · 失败消息含 op 名与两条修法', str_contains($msg, 'drawShadow') && str_contains($msg, '两条修法'));
fresh($log);

[$code, $output] = runCase(__FILE__, 'gradientbad', null);
check('Gradient · 色标越界退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
fresh($log);

[$code, $output] = runCase(__FILE__, 'shadowtransparent', null);
check('Shadow · 全透明阴影退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
fresh($log);

// ── ⑩+2 19 op 回植 · P1：椭圆/线段双后端墨迹（2026-10-20）──
// ★ 夹具统一 320×240（inkBounds 写死扫这个尺寸；拿 100×100 跑会把画布外
//   的越界读当墨迹 —— PHP 越界读返回 '' ⇒ ord=0 ⇒ r=0 < 200）。
// 填充：(60,40,200,120) 实心黑 ⇒ 像素中心 px∈{60..259}、py∈{40..159}
$cE = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$cE->drawEllipse(new Rect(60.0, 40.0, 200.0, 120.0), Paint::fill(Color::black()));
$bE = inkBounds($cE);
check('P1 · 椭圆填充包围盒 = 外接矩形两端含', $bE !== null && $bE === [60, 259, 40, 159], var_export($bE, true));
check('P1 · 椭圆中心实心、四角空白', $cE->pixelAt(160, 100)->r === 0 && $cE->pixelAt(5, 5)->r === 255);

// 描边：居中（外扩半宽 1、内收半宽 1）⇒ 外沿 [59,260]×[39,160]，中心是洞
$cS = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$cS->drawEllipse(new Rect(60.0, 40.0, 200.0, 120.0), Paint::stroke(Color::black(), 2.0));
$bS = inkBounds($cS);
check('P1 · 椭圆描边外沿 = 外扩半宽', $bS !== null && $bS === [59, 260, 39, 160], var_export($bS, true));
check('P1 · 椭圆描边中心是洞（白）', $cS->pixelAt(160, 100)->r === 255);
check('P1 · 椭圆描边顶边中段有墨', $cS->pixelAt(160, 40)->r === 0);

// 线段：胶囊体谓词（半宽 1.5）—— 线上像素黑、线外像素白
$cL = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$cL->drawLine(40.0, 40.0, 200.0, 120.0, Paint::stroke(Color::black(), 3.0));
check('P1 · 线段中点像素有墨', $cL->pixelAt(120, 80)->r === 0);
check('P1 · 线段端头有墨', $cL->pixelAt(40, 40)->r === 0);
check('P1 · 线段外 20px 处空白', $cL->pixelAt(120, 40)->r === 255);

// DisplayList 回放路径（ELLIPSE + LINE 两个新 opcode，LINE 走 6 槽布局）
$recL = RecordingCanvas::create();
$recL->save();
$recL->translate(20.0, 20.0);
$recL->drawEllipse(new Rect(0.0, 0.0, 60.0, 60.0), Paint::fill(Color::black()));
$recL->drawLine(0.0, 0.0, 60.0, 60.0, Paint::stroke(Color::black(), 2.0));
$recL->restore();
$listL = $recL->displayList();
$listL->assertBalanced();
$cR = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$listL->replay($cR);
check('P1 · 回放后椭圆+线段都有墨', inkCount($cR) > 100 && $cR->pixelAt(50, 50)->r === 0);

// Skia 侧：假桥记录（ABI 形状即契约）
skiaBridgeReset();
$cK = new SkiaCanvas(100, 100, null, Scale::of(1.0));
$cK->drawEllipse(new Rect(10.0, 10.0, 80.0, 80.0), Paint::fill(Color::black()));
$cK->drawLine(5.0, 5.0, 25.0, 15.0, Paint::stroke(Color::black(), 3.0));
$opsK = skiaCallOps();
check('P1 · Skia 桥收到 draw_ellipse + draw_line', $opsK === ['create', 'draw_ellipse', 'draw_line'], implode(',', $opsK));
$callsK = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'];
check(
    'P1 · draw_ellipse 参数（设备坐标/argb/非描边/宽 1）',
    $callsK[1] === ['draw_ellipse', 10.0, 10.0, 80.0, 80.0, Color::black()->toArgb(), false, 1.0],
    var_export($callsK[1], true)
);
check(
    'P1 · draw_line 参数（两端点设备坐标/argb/宽 3）',
    $callsK[2] === ['draw_line', 5.0, 5.0, 25.0, 15.0, Color::black()->toArgb(), 3.0],
    var_export($callsK[2], true)
);
skiaBridgeReset();

// ── ⑩+3 19 op 回植 · P2：CustomPaint 逃生舱（2026-10-20）──
// ★ 夹具统一 320×240（inkCount 按这个尺寸走）。
$ctxP = smokeContext();
$probeP = new ProbePainter(7);
$probeP->draw = true;
$cpEl = (new CustomPaint($probeP, 200.0, 120.0))->createElement();
$cpEl->mount(null, $ctxP);
$cpBox = $cpEl->effectiveBox();
$cpBox?->layout(BoxConstraints::loose(new Size(300.0, 200.0)));
$cpCustom = $cpBox instanceof RenderCustomPaint ? $cpBox : null;
check('P2 · effectiveBox() 是 RenderCustomPaint（类型接线正确）', $cpCustom !== null, get_debug_type($cpBox));
check(
    'P2 · 定尺寸 ⇒ 盒 (200,120)',
    $cpBox?->size() !== null && abs($cpBox->size()->width - 200.0) < 0.01 && abs($cpBox->size()->height - 120.0) < 0.01,
    sprintf('实际 %s', var_export($cpBox?->size(), true))
);
check('P2 · painterVersion() 直通画家内容戳（7）', $cpCustom !== null && $cpCustom->painterVersion() === 7);

$probeFill = new ProbePainter();
$cpFillEl = (new CustomPaint($probeFill, 160.0))->createElement();
$cpFillEl->mount(null, $ctxP);
$cpFillBox = $cpFillEl->effectiveBox();
$cpFillBox?->layout(BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'P2 · 未定的轴吃满约束上限（高 200 不是 0 —— 0 会静默不画）',
    $cpFillBox?->size() !== null && abs($cpFillBox->size()->width - 160.0) < 0.01
        && abs($cpFillBox->size()->height - 200.0) < 0.01,
    sprintf('实际 %s', var_export($cpFillBox?->size(), true))
);
check(
    'P2 · 未给定的轴自然尺寸 = 0（Row/Dock 量自然宽时不猜；要参与就给值）',
    $cpFillBox !== null && $cpFillBox->intrinsicHeight(0.0) === 0.0,
    sprintf('内禀高 %.6g', $cpFillBox?->intrinsicHeight(0.0) ?? -1.0)
);

$probeTight = new ProbePainter();
$cpTightEl = (new CustomPaint($probeTight, 200.0, 120.0))->createElement();
$cpTightEl->mount(null, $ctxP);
$cpTightBox = $cpTightEl->effectiveBox();
$cpTightBox?->layout(BoxConstraints::tight(new Size(150.0, 100.0)));
check(
    'P2 · 父更紧 ⇒ 听父（200 夹成 150，约束是父给的）',
    $cpTightBox?->size() !== null && abs($cpTightBox->size()->width - 150.0) < 0.01
        && abs($cpTightBox->size()->height - 100.0) < 0.01,
    sprintf('实际 %s', var_export($cpTightBox?->size(), true))
);

$cP = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$cpCustom?->paint($cP);
check(
    'P2 · paint 调画家一次且左上 40×40 有墨（画家直绘 Core 层图元）',
    $probeP->calls === 1 && inkCount($cP) > 100 && $cP->pixelAt(20, 20)->r === 0,
    sprintf('calls=%d ink=%d', $probeP->calls, inkCount($cP))
);
check(
    'P2 · 画家收到的尺寸 = 盒尺寸（画布已 translate 到盒原点）',
    $probeP->seen !== null && abs($probeP->seen->width - 200.0) < 0.01 && abs($probeP->seen->height - 120.0) < 0.01,
    sprintf('画家收到 %s', var_export($probeP->seen, true))
);

fresh($log);
[$code, $output] = runCase(__FILE__, 'custompaint', null);
check('P2 · CustomPaint 未定轴 + 无限约束退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
$msgP = logText($log);
check(
    'P2 · 失败消息点名 CustomPaint、带两条修法、不泄露内部编号',
    str_contains($msgP, 'CustomPaint') && str_contains($msgP, '两条修法') && !str_contains($msgP, 'C6'),
    $msgP
);
fresh($log);
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
// 期望值 55 → 45 是 §24.2 #20 的步进语义变化（拉丁 0.75em → 0.625em），不是回归
// 45 → 35：拉丁 advance 由固定 0.625em 改为真字体值（§24.2 #21）
check('Text · 行尾空白被修剪（不参与宽度）', $latinWrapped->lines[0]->width === 35.0, (string) $latinWrapped->lines[0]->width);
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
// 宽 9 → 8：'A' 的位图宽 = round(advance × 14/32)，advance 取自真字体（§24.2 #21）
check('Text · 位图尺寸随 pixelSize 缩放', $bitmap->width === 8 && $bitmap->height === 14, $bitmap->width . 'x' . $bitmap->height);
check('Text · 基线上方 = ascent × pixelSize（位图即 em 盒）', $bitmap->bearingY === -12, (string) $bitmap->bearingY);
check('Text · 位图有墨', $ink > 0);
check('Text · 未覆盖码位不画方框', $latinFace->rasterize(0x4E2D, 14.0) === null);

// ── 真字体度量面（TtfFace，2026-10-17）──
//    ★ 判据必须**能区分 TTF 与位图**：中文表外字两边都是 1.0em（分不出来），
//      所以取 'Ω'（TTF 0.758 / notdef 猜 0.5）与 '—'（TTF 0.894 / notdef 猜 0.5）。

$fontPath = FontLocator::find('NotoSansSC-Regular.ttf');
check('Text · 字体文件能定位（分发形态：fonts/ 或 cpp/fonts）', $fontPath !== null, (string) $fontPath);

$ttfFace = $fontPath === null ? null : TtfFace::fromFile($fontPath, [$latinFace, $cjkFace]);
check(
    'Text · TtfFace 从文件读出（upem 1000，不是空壳）',
    $ttfFace instanceof TtfFace && $ttfFace->unitsPerEm() === 1000,
    $ttfFace === null ? 'no face' : (string) $ttfFace->unitsPerEm(),
);
check(
    'Text · 行度量取 OS/2 sTypo（0.88 / 0.12），不是 hhea 的 1.448em',
    $ttfFace !== null
        && abs($ttfFace->metrics()->ascent - 0.88) < 1e-9
        && abs($ttfFace->metrics()->descent - 0.12) < 1e-9,
    $ttfFace === null ? 'no face' : sprintf('%.4f / %.4f', $ttfFace->metrics()->ascent, $ttfFace->metrics()->descent),
);
check(
    'Text · 步进来自 hmtx（Ω = 0.758em —— notdef 会猜 0.5）',
    $ttfFace !== null && abs($ttfFace->glyphAdvance(0x03A9) - 0.758) < 1e-9,
    $ttfFace === null ? 'no face' : (string) $ttfFace->glyphAdvance(0x03A9),
);
check(
    'Text · 覆盖判定查真字体 cmap（表外字「馈」有、emoji 无）',
    $ttfFace !== null && $ttfFace->hasGlyph(0x9988) && !$ttfFace->hasGlyph(0x1F600),
);
check(
    'Text · 字形委托位图面（TTF 有 + 位图也有 ⇒ 画得出）',
    $ttfFace !== null && $ttfFace->rasterize(0x4E2D, 14.0) !== null,
);
//    ★★ **判据字符必须"位图永远不会收录"**：扫描口径 = 所有 cp≥0xA0 的**字符串字面量**
//       ⇒ 任何写进夹具名/诊断串的字符，下一次重跑生成链就被补进位图 —— 'Ω' 就这样
//       假绿过一次（本轮加 Card 夹具时重跑生成链，Ω 进了位图，这条当场 FAIL）。
//       ⇒ 用**运行时构造的码点**（纯 int，不落进任何字面量），扫描器看不见它，判据才稳定。
$ttfOnlyCodepoint = 0x0416;   // 西里尔大写 Zhe：真字体有、位图从未收录（实测 ttf=true bitmap=false）
check(
    'Text · TTF 有但位图没有 ⇒ 不画（不静默画方框）',
    $ttfFace !== null
        && $ttfFace->hasGlyph($ttfOnlyCodepoint)
        && $ttfFace->rasterize($ttfOnlyCodepoint, 14.0) === null,
    $ttfFace === null ? 'no ttf' : sprintf('hasGlyph=%s', var_export($ttfFace->hasGlyph($ttfOnlyCodepoint), true)),
);
check(
    'Text · FontLocator 找不到 ⇒ null（不抛、不猜）',
    FontLocator::find('NoSuchFont.ttf', ['/nonexistent-dir']) === null,
);

// —— 集成：Application::metrics() 真的接了真字体（不是静默退回位图）
$ttfApp = new ModalApplication();
$ttfAdvance = $ttfApp->metrics()->advance('Ω', $body, $unit);
//    ★ 期望值要按**量化后**写：`DeterministicShaper` 把步进 round 到设备像素
//      （§8.2 坑 4），0.758 × 14 = 10.612 ⇒ 11。notdef 对 Ω 猜 0.5em ⇒ 7 —— 两者可分。
check(
    'Text · Application::metrics() 已接真字体（Ω 宽 11px，不是 notdef 猜的 7px）',
    abs($ttfAdvance - 11.0) < 1e-9,
    sprintf('%.4f（notdef 会是 7.0）', $ttfAdvance),
);

// ── 降采样灰度（§24.2 #17）：小字抗锯齿不倒在函数出口 ──
// pixelSize < unitsPerEm(16) ⇒ 降采样路径。判据：coverage 值域必须**超出** {0,255}
// ——0<v<255 的中间值就是 blend() 的 alpha。反例自检天然：本修复前全页 0/255 两值，
// 这两条 FAIL（去阈值化即绿）。灰阶像素数作诊断串。
$grayFace = $registry->faceFor(0x4E2D, FontFamily::sans(), FontWeight::Regular, FontSlant::Normal);
$grayBmp = $grayFace->rasterize(0x4E2D, 13.0);
$grayValues = [];
for ($i = 0; $i < strlen($grayBmp->coverage); $i++) {
    $grayValues[ord($grayBmp->coverage[$i])] = true;
}
$grayMid = 0;
foreach ($grayValues as $v => $_) {
    if ($v > 0 && $v < 255) {
        $grayMid++;
    }
}
check(
    'Text · 降采样产出连续灰度（0<coverage<255 存在，不二值化）',
    $grayMid > 0,
    '不同值=' . count($grayValues) . ' 中间灰度值=' . $grayMid
);
check('Text · 降采样尺寸仍随 pixelSize 缩放（灰度不改几何）', $grayBmp->width === 13 && $grayBmp->height === 13, $grayBmp->width . 'x' . $grayBmp->height);

// 端到端：灰度字模经 blendBitmap 上屏 ⇒ 画布出现"非字色非底色"的混合像素。
// 这条钉**消费链**（blend 真的按 alpha 混合了），不是钉实现。
$grayCanvas = new RasterCanvas(24, 24, null, $unit);
$grayCanvas->clear(Color::fromRgb(255, 255, 255));
$grayPara = $textMetrics->layout('中', $body, INF, $unit);
$grayCanvas->drawParagraph($grayPara, Paint::fill(Color::fromRgb(0, 0, 0)), 2.0, 2.0);
$grayPixels = 0;
for ($gy = 0; $gy < $grayCanvas->height(); $gy++) {
    for ($gx = 0; $gx < $grayCanvas->width(); $gx++) {
        $gp = $grayCanvas->pixelAt($gx, $gy);
        if ($gp->r > 0 && $gp->r < 255) {
            $grayPixels++;
        }
    }
}
check('Text · 端到端：黑字白底出现混合像素（blend 按覆盖率插值）', $grayPixels > 0, '混合像素=' . $grayPixels);

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

// ⑪-b 子表配对的**复用语义**与**复杂度**（2026-10-27）
//   ★ 复用语义用"元素实例是不是同一个"断言；复杂度用 `Pipeline::current()->pairChecks()`
//     —— 后者是"退回平方扫描"的**唯一可观察症状**（行为全对、只是慢）。
$mountColumn = static function (Column $column): Element {
    $element = $column->createElement();
    $element->mount(null, smokeContext());

    return $element;
};

// 顺序不变 ⇒ 走前缀同步：200 子只做 200 次配对（旧实现是 20,100 次）
$kidsOld = [];
$kidsNew = [];
for ($i = 0; $i < 200; $i++) {
    $kidsOld[] = Text::make('n' . $i);
    $kidsNew[] = Text::make('n' . $i);   // 新实例：真实 build 每帧都造新 Widget
}
$pairRoot = $mountColumn(Column::make()->children($kidsOld));
$kidFirst = $pairRoot->childAt(0);
$kidLast = $pairRoot->childAt(199);
$checksBefore = Pipeline::current()->pairChecks();
$pairRoot->update(Column::make()->children($kidsNew));
$checksDelta = Pipeline::current()->pairChecks() - $checksBefore;
check(
    'Element · 顺序不变的 200 子 ⇒ 配对次数 = 子数（不是平方）',
    $checksDelta === 200,
    'delta=' . $checksDelta . '（旧实现 20100）',
);
check(
    'Element · 顺序不变的 200 子 ⇒ 元素全部复用（首尾同一实例）',
    $pairRoot->childAt(0) === $kidFirst && $pairRoot->childAt(199) === $kidLast,
);

// 跨位置搬家（带 Key）⇒ 元素跟着 Key 走 —— 走的是第二段（建桶）
$pairRoot = $mountColumn(Column::make()->children([
    Text::make('a')->key(1),
    Text::make('b')->key(2),
]));
$kidA = $pairRoot->childAt(0);
$kidB = $pairRoot->childAt(1);
$pairRoot->update(Column::make()->children([
    Text::make('b')->key(2),
    Text::make('a')->key(1),
]));
check(
    'Element · 带 Key 换序 ⇒ 元素跟着 Key 走（0 号是原来的 1 号）',
    $pairRoot->childAt(0) === $kidB && $pairRoot->childAt(1) === $kidA,
);

// 中间删一个 ⇒ 前缀同步只覆盖前半段，后半段由桶接手
$pairRoot = $mountColumn(Column::make()->children([
    Text::make('a')->key(1),
    Text::make('b')->key(2),
    Text::make('c')->key(3),
]));
$kidC = $pairRoot->childAt(2);
$kidB = $pairRoot->childAt(1);
$pairRoot->update(Column::make()->children([
    Text::make('a')->key(1),
    Text::make('c')->key(3),
]));
check(
    'Element · 中间删一个 ⇒ 后面的元素上移复用、被删的那个已卸载',
    $pairRoot->childCount() === 2
        && $pairRoot->childAt(1) === $kidC
        && !$kidB->isMounted(),
    'count=' . $pairRoot->childCount(),
);

// 重复 Key ⇒ 按**旧表升序**取槽位（与旧实现逐字相同的语义）
//   ★ 首子换成另一种类型 ⇒ 前缀同步在 0 号就断，**强制走建桶那条路**
//     （否则同类同键的两个子会被前缀同步按位置配掉，桶根本没被走到）。
$pairRoot = $mountColumn(Column::make()->children([
    Text::make('a')->key(9),
    Text::make('b')->key(9),
]));
$kid9a = $pairRoot->childAt(0);
$kid9b = $pairRoot->childAt(1);
$pairRoot->update(Column::make()->children([
    SizedBox::make(),
    Text::make('c')->key(9),
    Text::make('d')->key(9),
]));
check(
    'Element · 重复 Key ⇒ 按旧表升序取槽位（前缀断了也升序）',
    $pairRoot->childAt(1) === $kid9a && $pairRoot->childAt(2) === $kid9b,
    'c1=' . ($pairRoot->childAt(1) === $kid9a ? 'a' : 'b') . ' c2=' . ($pairRoot->childAt(2) === $kid9b ? 'b' : 'a'),
);

// Key 类型不互转：`1` 与 `'1'` 分属两个桶（桶键带类型前缀）
$pairRoot = $mountColumn(Column::make()->children([Text::make('a')->key(1)]));
$kidInt = $pairRoot->childAt(0);
$pairRoot->update(Column::make()->children([Text::make('a')->key('1')]));
check(
    'Element · Key 1 与 "1" 不同桶 ⇒ 不复用（元素换新）',
    $pairRoot->childAt(0) !== $kidInt,
);

// 类型变了 ⇒ 换新元素，旧元素卸载
$pairRoot = $mountColumn(Column::make()->children([Text::make('a')]));
$kidText = $pairRoot->childAt(0);
$pairRoot->update(Column::make()->children([SizedBox::make()]));
check(
    'Element · 类型变了 ⇒ 换新元素且旧元素已卸载',
    $pairRoot->childAt(0) !== $kidText && !$kidText->isMounted(),
);

fresh($log);
[$code, $output] = runCase(__FILE__, 'key', null);
check('Key · 封存后再给 Key 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Key · 封存后再给 Key 写了 Errors.log', str_contains(logText($log), 'Widget 已封存'));

// ⑫ 盒样式：**唯一存储**（S1，2026-10-28）
// ★★ 此前是双轨：`BoxWidget` 子类把 `->background()` 写进自己的 `private ?BoxStyle`，
//   其余控件写 `$mods`。同一个 API 名两个落点，而 `Element::syncBox()` 那条"声明了
//   修饰但修饰无处可去"的守卫**只看得到 `$mods`** —— 另一半静默失效。
//   S1 删掉 `BoxWidget`：产盒控件一律写 `$mods`，`BoxStyle` 降为 Layout 层的渲染
//   参数、需要时由 `Modifier::boxStyle()` **派生**（`Button` / `PopupPanel` 这么用）。
fresh($log);
$boxProbe = new BoxProbe();
check('盒样式 · 不用样式 ⇒ 派生结果为 null（零成本）', $boxProbe->mods()->boxStyle() === null);

// 结构判据：产盒控件上**没有** `BoxStyle` 类型的字段 ⇒ 不可能有第二份存储。
$strayStyleFields = [];
foreach ([Button::class, PopupPanel::class, AppBar::class] as $cls) {
    foreach ((new \ReflectionClass($cls))->getProperties() as $prop) {
        if ((string) $prop->getType() === BoxStyle::class) {
            $strayStyleFields[] = $cls . '::$' . $prop->getName();
        }
    }
}
check(
    '盒样式 · 产盒控件上没有 BoxStyle 字段（唯一存储是 $mods）',
    $strayStyleFields === [],
    \implode(', ', $strayStyleFields)
);

// 裸 Widget 与产盒控件**走同一条路**
$plainProbe = new SealProbe();
$plainProbe->background(Color::white());
check('盒样式 · 裸 Widget 的 background() 进 mods', $plainProbe->mods()->hasVisual());

$boxProbe->background(Color::white());
check('盒样式 · 产盒控件的 background() 也进 mods（不再有第二条存储）', $boxProbe->mods()->hasVisual());
check('盒样式 · boxStyle() 从 mods 派生出来', $boxProbe->mods()->boxStyle()?->hasBackground() === true);
$boxProbe->border(Color::black(), 2.0);
check(
    '盒样式 · 链式叠加是整体替换，不丢前一项',
    $boxProbe->mods()->boxStyle()?->hasBackground() === true
        && $boxProbe->mods()->boxStyle()?->borderWidth === 2.0
);
$boxProbe->radius(8.0);
check('盒样式 · radius 独立改写', $boxProbe->mods()->boxStyle()?->radius === 8.0);
check('盒样式 · 链式返回自身（不产生新实例）', $boxProbe->radius(4.0) === $boxProbe);

// 派生判据的边界：只有圆角、没有底也没有边 ⇒ 仍要有 BoxStyle（不能让 radius 丢在半路）
$radiusOnly = new BoxProbe();
$radiusOnly->radius(6.0);
check(
    '盒样式 · 只设 radius（无底无边）也派生得出来',
    $radiusOnly->mods()->boxStyle()?->radius === 6.0
);

// ⑫b 修饰是字段（arch-v2 `02` §11，M2-1）—— 值对象 + 15 个 Widget setter
$modProbe = new SealProbe();
$mods0 = $modProbe->mods();
check(
    'Modifier · 默认：无内距 / 无视觉 / 无合成 / 尺寸与 basis 未设',
    !$mods0->hasPadding() && !$mods0->hasVisual() && !$mods0->hasCompose()
        && \is_nan($mods0->flexBasis())
);
check('Modifier · 每个 Widget 都有（构造器里建好，永远非空）', $mods0 instanceof Modifier);

$modProbe->paddingAll(8.0);
check(
    'Modifier · paddingAll 写四边',
    $modProbe->mods()->padLeft() === 8.0 && $modProbe->mods()->padTop() === 8.0
        && $modProbe->mods()->padRight() === 8.0 && $modProbe->mods()->padBottom() === 8.0
);
check(
    'Modifier · 值对象换新：旧对象不动',
    $mods0->hasPadding() === false && $modProbe->mods()->hasPadding()
);
$modProbe->paddingOnly(null, null, 2.0);
check(
    'Modifier · paddingOnly 只动给了的那一边',
    $modProbe->mods()->padLeft() === 8.0 && $modProbe->mods()->padRight() === 2.0
);

// 尺寸：NAN = 未设，与"设为 0"必须是两件事（`02` §3.2）
$sizeProbe = new SealProbe();
$sizeProbe->boxSize(120.0);
$loose = new BoxConstraints(0.0, 400.0, 0.0, 300.0);
$tightW = $sizeProbe->mods()->tighten($loose);
check(
    'Modifier · boxSize 只定宽 ⇒ 宽变紧、高透传',
    $tightW->minWidth === 120.0 && $tightW->maxWidth === 120.0
        && $tightW->minHeight === 0.0 && $tightW->maxHeight === 300.0
);
$tightNarrow = $sizeProbe->mods()->tighten(new BoxConstraints(0.0, 50.0, 0.0, 300.0));
check(
    'Modifier · 父更紧时听父的（不造 min > max）',
    $tightNarrow->minWidth === 50.0 && $tightNarrow->maxWidth === 50.0
);
$zeroProbe = new SealProbe();
$zeroProbe->boxSize(0.0, 0.0);
$tightZero = $zeroProbe->mods()->tighten($loose);
check(
    'Modifier · boxSize(0) 是"0 尺寸"，不是"未设"',
    $tightZero->minWidth === 0.0 && $tightZero->maxWidth === 0.0
        && $tightZero->minHeight === 0.0 && $tightZero->maxHeight === 0.0
);

$m8 = (new Modifier())->withPadding(8.0, 4.0, 8.0, 4.0);
$deflated = $m8->deflate(new BoxConstraints(0.0, 100.0, 0.0, 100.0));
check('Modifier · deflate 减内边距', $deflated->maxWidth === 84.0 && $deflated->maxHeight === 92.0);
$inflated = $m8->inflate(new Size(84.0, 92.0));
check('Modifier · inflate 加内边距', $inflated->width === 100.0 && $inflated->height === 100.0);

$modProbe->opacity(0.5)->translate(3.0, -2.0);
check(
    'Modifier · opacity / translate 进合成维',
    $modProbe->mods()->hasCompose()
        && $modProbe->mods()->composeOpacity() === 0.5
        && $modProbe->mods()->composeDx() === 3.0
        && $modProbe->mods()->composeDy() === -2.0
);

$modProbe->grow(2)->shrink(1)->basis(40.0)->alignSelf(RenderBox::ALIGN_CENTER);
check(
    'Modifier · 弹性意图四项（Expanded / Flexible 的字段形态）',
    $modProbe->mods()->flexGrow() === 2 && $modProbe->mods()->flexShrink() === 1
        && $modProbe->mods()->flexBasis() === 40.0
        && $modProbe->mods()->flexAlign() === RenderBox::ALIGN_CENTER
);

// ★★ 合成初值落到盒上。
$flexKeep = new RenderProxy();
$flexKeep->setMods((new Modifier())->withOpacity(0.25));
check('Modifier · setMods 把合成初值落到盒上', $flexKeep->composeOpacity() === 0.25);

// ★★ M2-1 时这条是**反向**的（"setMods 不能清掉 `RenderFlexible` 的份额"）——
//   那时份额住在 `RenderFlexible` 的构造器里，按 mods 默认值再写一次会清零、
//   `Expanded` 全失效且不报错。M2-4 删掉那个类之后，`mods.grow` 成了份额的
//   **唯一**来源 ⇒ 断言反转：`setMods()` 必须把份额写到盒上。
$flexKeep->setMods((new Modifier())->withGrow(2)->withShrink(1)->withBasis(40.0)->withAlignSelf(RenderBox::ALIGN_CENTER));
check(
    'Modifier · setMods 把 mods 的弹性意图写到盒上（M2-4 起份额只有一个来源）',
    $flexKeep->grow() === 2 && $flexKeep->shrink() === 1
        && $flexKeep->basis() === 40.0 && $flexKeep->alignSelf() === RenderBox::ALIGN_CENTER,
    sprintf('grow=%d shrink=%d basis=%.1f align=%d', $flexKeep->grow(), $flexKeep->shrink(), $flexKeep->basis(), $flexKeep->alignSelf())
);
$flexKeep->setMods(new Modifier());
check(
    'Modifier · mods 里没有份额 ⇒ 盒上的份额归零（没有第二个来源可以"留着"）',
    $flexKeep->grow() === 0 && \is_nan($flexKeep->basis())
);

$plainBox = BoxStyle::none()->withBackground(Color::white());
$roundedBox = $plainBox->withRadius(4.0);
check(
    'BoxStyle · with- 返回新值，原值不变',
    $plainBox->radius === 0.0 && $roundedBox->radius === 4.0 && $roundedBox->hasBackground()
);

fresh($log);
[$code, $output] = runCase(__FILE__, 'box', null);
check('盒样式 · 封存后改样式退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('盒样式 · 封存后改样式写了 Errors.log', str_contains(logText($log), 'Widget 已封存'));

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

// ── ⑮ 布局：基类契约 + 契约违反的失败路径 已迁出 ─────────────────────────────
//   · `tests/Unit/Layout/RenderBoxContractTest.php`（21 条 / 9 方法）—— C1 纯函数**两侧**
//     （不纯盒必须不同）/ 自校验开关真在守且纯盒不误报 / 尺寸是 layout 的产物 /
//     C3 内禀读另一轴 / 约束由父定（不是"父自己那份"）/ 父定位子 + absoluteOrigin
//     沿父链累加 / visitChildren 深度优先 / 父的平移落到设备像素 / 默认 overflow 与
//     clipsOverflow **故意相反**
//   · `tests/Feature/Layout/LayoutFailureTest.php`（10 条 / 5 方法）—— C5 Report / shrink
//     压到 0 仍放不下 / 容器上 Scroll 不支持（指路 ScrollView）/ flex 份额 < 1 / C6 超上界；
//     每条都是"退出码 = 3 + 人话日志且不含内部条款编号"
//   ★ 失败路径改走 `runFatalCase()`（用例在 `tests/Support/fatal_cases.php`）：
//     原来的 `runCase(__FILE__, ...)` 要求 `__FILE__` 是 smoke 入口，搬不动。

// ── ⑯ 三树 Element（挂载 / diff / 卸载）已迁出 ───────────────────────────────
//   · tests/Unit/Widget/ElementTreeTest.php（10 条，纯 Element 机制 Unit 层）
// ── 结构控件：Column / Row / Padding / Center / Spacer / Expanded（⑱）已迁出 ──────
//   · `tests/Unit/Widget/StructureControlsTest.php` —— 建盒/子盒挂接 / 端到端 flex 分配 /
//     Row 横主轴 / 弹性子拿全部剩余（A·B·C）/ Padding 进盒 / Center 正中·松约束·带 padding /
//     不是盒控件 · 无 build() · update 换盒（16 条）
//   ★ 纯布局，不依赖帧驱动，放 Unit 层。

// ── ⑲ Text 控件 / ㉑ Each：声明层与列表 diff 已迁出 ─────────────────────────────
//   · `tests/Unit/Widget/TextTest.php` —— 哨兵三档（enum case / 三态 bool / -1.0 域外）/
//     角色经主题解析（title 18 · caption 12 · 显式 size 盖过角色）/ 端到端排版 ·
//     收窄折行 · maxLines 进 TextStyle（12 条）
//   · `tests/Unit/Widget/EachTest.php` —— keyFn 落位 / 挂载铺子 / key 相同复用 /
//     重排 key 跟随 item / 空列表 0 子（5 条）
//   ★ 重复 key 与 int·字符串 key 撞车必须**子进程**验（`Errors::report` 走 `abort()`，
//     进程内接不住）：用例 → `tests/Support/fatal_cases.php` 的 `eachdup` / `eachkeytag`，
//     判据 → `tests/Unit/Widget/EachTest.php`（第 39 批，2026-10-28）。

// ── ㉒ Button / NavButton / IconButton / 盒级 hover / 滚动条淡出 已迁出 ─────────
//   · `tests/Unit/Widget/ButtonTest.php` —— 变体薄壳 / onPressed 原样递 / 换肤取色 /
//     盒尺寸 = 内边距 + 标签 / 图标加宽 / 标签排图标右侧 / 像素居中 / NavButton 左对齐 /
//     IconButton 正方形 · 不建 RenderText · 可访问名（17 条）
//   · `tests/Unit/Layout/HoverAnimationTest.php` —— progress 起点 0 / 首帧自报在跑 /
//     24 帧单调逼近 / 收口返回 false / 浮起把影子画到盒外（6 条）
//   · `tests/Unit/Widget/ScrollBarFadeTest.php` —— 恒亮 · 不建槽 / 900ms 空闲线 /
//     淡到 0.35 不是 0 / 收口后能重新起飞（7 条）
//   ★ Dock C5 Report 的失败路径 → `fatal_cases.php` 的 `dockoverflow`，
//     判据 → `tests/Unit/Layout/DockTest.php`（第 39 批，2026-10-28）。

// ── ㉓ TextField 已迁出 ───────────────────────────────────────────────────
//   · `tests/Feature/Widget/TextFieldTest.php` —— 声明层（make 链式 · label 三态 ·
//     onChanged 原样递）/ 盒几何与换肤（三段分工 · Clip · 占位符不塌 · 明暗底色 ·
//     描边 · 有 label 根是 Column）/ 编辑态序列（码位删与吸附 · onChanged 只在真改动时
//     触发）/ 插入符几何与墨（caretRect 需先 settle · 编辑态真多出墨 · 受控回推）/
//     表单示例页无头出图（§20 纪律 4）—— 共 26 条
//   ★ 落 Feature 层：判据含光栅取墨与无头 SVG 出图。
// ── ㉔ 输入层最小集 / 坐标链 / IME 定位 已迁出 ──────────────────────────────
//   · `tests/Unit/Input/KeyModelTest.php`（11）—— KeyChord 值语义 · F5 · character vs
//     down · FocusNode 链式与 claims · Widget 侧 focusOrder/claimKeys → focusNode()
//   · `tests/Feature/Input/FocusRoutingTest.php`（21）—— 文档序收集 · 没焦点不吃键 ·
//     Tab 前进 / Shift+Tab 后退 / 末尾停（不环绕）· 焦点落下即编辑态 · 离开即退出 ·
//     打字 / 退格 / 方向键 / Home / End · 命中测试最深优先 → 焦点 · 帧末重建换新盒 ·
//     skipFocus / focusOrder / claimKeys
//   · `tests/Feature/Input/CoordinateChainTest.php`（6）—— hitTest 命中起点 == 墨迹起点
//     （padding 移到布局期施加的判据）· 墨迹起点 == 24px padding · imeCaretRect 含容器
//     padding 且沿父链累积 · 无焦点 ⇒ null · 带 label 穿 Column 找真实字段盒
//   ★ 夹具复用 `Drift\Dev\Smoke\App\{TfApplication,TfLabeledApplication}` 与
//     `Frame\SurfaceProbe`（composer autoload 已覆盖）。

// ── 修饰落在"不产盒"的控件上 已迁出 ────────────────────────────────────────
//   → `tests/Unit/Rendering/ModifierTest.php`（4 方法 / 5 条）——
//     全默认 ⇒ isDefault() 真 · 表覆盖**全部** `with*()`（反射比方法名，新增一个
//     不补一行就红）· 每个 `with*()` 都改 isDefault() · 不产盒控件上声明修饰 ⇒
//     子进程退出码 3 + 日志含"不产渲染盒"。
//   ★ 原判据标题写"21 个字段都在判据里"，实际只抽了 4 个 —— **过度承诺**。
//     改成表驱动 + 完整性自检，顺带查出真缺口：`isDefault()` 不读 `repaintBoundary`
//     ⇒ 在不产盒的控件上声明它会被静默丢掉（其余修饰都 Fatal）。测试把现状钉住，
//     补字段时那一行会红，逼一次明确决定。
//   ★ `modstateful` 用例搬到 `tests/Support/fatal_cases.php`（`runFatalCase()`）——
//     `runCase(__FILE__)` 依赖 `__FILE__` 是入口脚本，用例搬进 tests 之后起不了子进程。
// ── 选区模型 / 选区渲染 已迁出 ───────────────────────────────────────────────
//   · `tests/Unit/Widget/TextFieldSelectionTest.php`（23）—— selectAll 两端 / 锚点模型
//     （Shift+Left·Right·Home·End 只挪活动端）· 不带 Shift 移动折叠 · Ctrl+A 经 handleKey ·
//     Esc 吃掉选区并消费按键（无选区不吞）· 键入与退格/Delete 吃整段 · 无选区退格仍按码位 ·
//     多字节端点恒字符边界 · 外部换值作废选区 · extendSelectionTo 吸附与夹取 · endEditing 清选区
//   · `tests/Feature/Widget/TextFieldTest::testSelectionGeometryAndPaint`（5）—— selectionRect
//     非 null / 右边缘与同偏移插入符左边缘同源 / offsetAt 真实往返 / 全选真的多出墨 /
//     无选区为 null
//   ★ `fieldInk()` 已随段删除 —— 测试侧用 TextFieldTest 的同名私有方法（同一量具）。
// ── 拖动 resize / 零视口 / AppBar 窄窗 / 预览期降级 / 主题切换 已迁出 ────────
//   · `tests/Feature/App/WindowFrameTest.php`（7）—— repaintForResize 按新约束重排且
//     只跑一帧·时间戳复用上一帧 · frame(tight(0,0)) 跳过布局且根盒保持旧尺寸 ·
//     repaintForResize 同判据 · 还原后按新尺寸重排 · AppBar 窄于自然宽 ⇒ 裁剪无 Fatal ·
//     `LayoutTolerance::$windowDriven` 期间极小尺寸降级为裁剪
//   · `tests/Feature/Theme/ThemeSwitchTest.php`（4）—— 派发前是浅色（对照）/
//     onSystemThemeChanged 换源 / 根 context 换深色 / **子层也换**（递归下发才叫换表）
// 帧末重建（adoptBoxes）/ skipFocus / focusOrder / claimKeys 已迁出
//   · `tests/Feature/Input/FocusRoutingTest.php` —— 父容器换上重建后的新盒（量具
//     双向自检：`Element::settle()` 去掉 `adoptBoxes()` 这条必须变红）/
//     skipFocus 整棵子树不进 Tab 序 / focusOrder 稳定排序 / claimKeys 独占 Ctrl+A
// ── ⑳ 控件与结构节点混用：**已删，不迁** ───────────────────────────────────
//   原判据是 `width > 0 && height > 0`（"Column 包 Text + Row(Text/Spacer/Text)
//   能一路排到底"）—— 把 spacing / padding 全写成 0 它也绿，**证伪不了任何语义**。
//   而它想验的东西已被 `tests/Unit/Widget/StructureControlsTest.php` 覆盖得更硬：
//   Column/Row/Padding/Center/Spacer/Expanded 各自的几何 + 端到端 flex 分配
//   （Spacer 1:3 ⇒ 15/45）。留着只是把"能建树"再数一遍。
// ⑰ State 生命周期 已迁出
//   → `tests/Unit/State/StateLifecycleTest.php`（4 方法 / 13 条）——
//     挂载后就位（多次取到同一个 / build() 收到渲染环境 / 建出一层子）·
//     setState **只标脏**（bump 前 needsBuild=false ⇒ 之后 true 且 builds 仍 1 ⇒
//     帧末 rebuild 后清脏且 builds=2）· 重建不换 State · 换描述时 State 不换且
//     手里换成整份新 Widget · unmount 调 dispose（**卸载前先断 disposed=false**）。
//   ★ 六条判据共享同一个实例才成立：拆开各建一个夹具，"只标脏"就退化成
//     "新建的实例 builds=1" —— 恒真。
//   ★ 三处"写入了"都补了"写入前是另一个值"（needsBuild / widget() / disposed）。
// ── ㉓ State 层：订阅生命周期 / Cell 门控 / Computed 切片 已迁出 ─────────────
//   · `tests/Unit/State/SubjectBuilderTest.php`（8）—— 首帧就拿到 select 值 / 同值 notify
//     被门控挡住 / 改字段不 notify 收不到 / notify 且变才标脏 / 重建拿新值 / unmount 退订 /
//     无 select ⇒ builder 收 0 个参数（不是 null）
//   · `tests/Unit/State/CellTest.php`（7）—— setIfChanged 换值标脏·同值不标脏（RT-4）/ 构造
//     私有 / makeFor 绑对 Owner
//   · `tests/Unit/State/ComputedTest.php`（7）—— 无脏不重算 / 依赖版本变则重算 / 自进一版 /
//     嵌套 A→B→C 一次算完整链
//   · `tests/Feature/State/SubscriptionIsolationTest.php`（4）—— RT-3 一个订阅者抛异常其余
//     仍收到（a,b,c 都跑）· 不静默（写 Errors，级别 Recoverable）· unsubscribeAllOf 整属主退订
//   · `tests/Feature/State/SettleDriftTest.php`（1）—— 100 帧 settle 后 pendingDirty 回基线
//   ★ ⑦c／⑦d／⑧（build 期标脏 / 跨 State 写 / build 期订阅）与 Computed 自引用都是
//     **子进程**判据（`Errors::report` 走 `abort()`）⇒ 第 39 批搬走：
//     用例 → `fatal_cases.php` 的 `flushcycle` / `chainwrite` / `watchbuild` / `computedcycle`；
//     判据 → `tests/Feature/State/FlushCycleTest.php` 与 `tests/Unit/State/ComputedTest.php`。
//   ★★ 搬的时候发现 ⑦d 一直是**假绿**：`ChainPage`/`InnerPage` 漏 `parent::__construct()`
//     ⇒ 子进程崩在"未初始化属性"（255），而旧判据 `$code !== 3` 把崩溃也放过。
//     夹具已修，新判据是 `code === 0` + "走到末尾"。

// ── 下面两块已迁出 ───────────────────────────────────────────────
//   · `tests/Unit/State/CellTest.php` —— new Cell(1) 抛可捕获的 Error（构造私有）/
//     State::cell() 可用且绑对 Owner（setIfChanged(8) 标脏到本 State）
fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉔ Commands：参数 / 注册表 / 冒泡链第 ① 环 已迁出 ────────────────────────
//   · `tests/Unit/Commands/CommandTest.php` —— CommandArg 双槽（i + s）/ on() 链式 /
//     dispatch 原样递参 / handler 返回 false ⇒ 视为没接 / 未注册 ⇒ Diagnostic 环形缓冲 /
//     State 默认不接 / 接管自己的命令且其余放行
//   ★ 同名重复注册 ⇒ Fatal → `fatal_cases.php` 的 `cmdup`，
//     判据 → `tests/Unit/Commands/CommandTest.php`（第 39 批）。

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉕ App.Frame：flush → layout → paint → present 已迁出 ────────────────────
//   · `tests/Feature/App/FrameLoopTest.php` —— setState 延后到帧末（builds 1→2）/
//     时间戳注入不读环境时钟 / beginFrame·endFrame 各一次 / 没脏不重建（脏边界）/
//     layout → paint → present 一路到表面（画布有墨）/ 帧外 update 换盒照样排过
//     （2026-10-24 缺陷：settle 的"帧内重建差"为 0 ⇒ 跳过 layout ⇒ size 恒 null）

// ── RouteTable（纯数据）已迁出 ──────────────────────────────────────────────
//   · `tests/Unit/Routing/RouteTableTest.php` —— 链式登记 + names 可枚举 / 工厂造页 /
//     未命中 ⇒ null / has 与 names 一致
//   ★ 同名路由重复注册 ⇒ Fatal → `fatal_cases.php` 的 `routedup`，
//     判据 → `tests/Unit/Routing/RouteTableTest.php`（第 39 批）。

// ── Offstage（保留档 / 丢弃档）已迁出 ──────────────────────────────────────
//   · `tests/Feature/Widget/OffstageTest.php` —— 可见在树 / 隐藏摘掉但不 dispose /
//     隐藏期间 Frame 走不到（脏标记留着）/ 切回同一 State 且脏标记立即兑现 /
//     整个 Offstage 被卸载时 State 走 dispose
fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉗ ThemeScope / ㉘ PageHost / ㉙ Application / ㉚ Preview / ㉛ hello 已迁出 ──
//   · `tests/Feature/Theme/ThemeScopeTest.php`（12）—— 覆盖不外溢 · 嵌套最内层赢 ·
//     有状态子层只标脏、帧末兑现且 State 不换 · 无状态控件立即换盒（叶子与容器两种，
//     后者验 §13.2 顺序）· 同一按钮两份表取不同底色
//   · `tests/Feature/App/PageHostTest.php`（9）—— 首帧两页都挂 · 换页旧页进 Offstage ·
//     切回同一实例（工厂不二次调）· 同值 notify 被门控 + 通知仍到达（成对）·
//     未命中页名 ⇒ 末槽可见 NoPage
//   · `tests/Feature/App/ApplicationTest.php`（12）—— attach 即首帧 · frame() 一调一帧 ·
//     命令四环（注册表 / 树内优先于注册表 / 谁都没接写环形缓冲）· 组合根无盒仍排到
//     视口且画面有墨
//   · `tests/Feature/Preview/RendererTest.php`（4）—— SVG 有 run · 根盒排满视口 ·
//     组合节点对渲染树透明 · dpr=2 设备像素翻倍
//   · `tests/Feature/App/HelloAppTest.php`（7）—— title/挂载/有墨/排满 · --app 出 SVG ·
//     文本吃画布平移 · 文字真居中（看包围盒而不是墨点数）
// ── ㊷ apps/tutorial：从零写一个应用（`docs/tutorial.md` 的配套代码）───────
//
// ★ 这一节的作用不是"证明 tutorial 能跑"，是**给教程绑一条闸门**：
//   `docs/tutorial.md` 讲的那几条（四段结构 / overlayPanel 零接线 / after 要接线）
//   一旦被后续改动打破，这里先红，而不是等下一次有人照抄教程时才发现。
// ★ 断言全部走**公开面**（`dispatchInput` + `findElement`），不碰 State 私有字段
//   —— 教程要教的就是"从外面看得见的行为"。

$tutConstraints = BoxConstraints::tight(new Size(720.0, 900.0));
$tutApp = new TutorialApplication();
$tutSurface = new SurfaceProbe();
$tutSurface->resize(720, 900, 1.0);
$tutApp->attach($tutSurface, $tutConstraints);

check('apps/tutorial · 应用只写 buildRoot/title（§4.5）', $tutApp->title() === 'Drift 教程');
// ★ 同 `㊷b`：判"四段都排开了"用**命令条数**（四段标题各一条 drawParagraph，实测 19 条），
//   不用 `inkCount()`（一个墨点就恒真，且 720×900 的扫像素 + 回放要 1.1s）。
$tutList = $tutSurface->list();
$tutTextOps = drawOpCount($tutList, DisplayList::OP_DRAW_PARAGRAPH);
$tutRectOps = drawOpCount($tutList, DisplayList::OP_DRAW_RECT);
check(
    'apps/tutorial · 四段都排开了（四段标题各上了一次字，不是静默空白）',
    $tutTextOps >= 4 && $tutRectOps >= 3,
    'drawParagraph ' . $tutTextOps . ' / drawRect ' . $tutRectOps . ' / 命令 ' . $tutList->count() . ' 条'
);

// 四段标题都在树上 —— 少一段 = 那段建树失败（它自己**不会报错**，只是不出现）
$tutTitles = ['① 最小应用', '② 计数器', '③ 浮层（声明式缝，零接线）', '④ 异步（唯一需要接线的一段）'];
$tutFound = 0;
foreach ($tutTitles as $tutTitle) {
    if (findElement(
        $tutApp->root(),
        static fn (Element $e): bool => $e->widget() instanceof Text && $e->widget()->content() === $tutTitle
    ) !== null) {
        $tutFound++;
    }
}
check('apps/tutorial · 四段标题都在树上', $tutFound === 4, '找到 ' . $tutFound . ' / 4');

/** 按控件标签点一下（盒中心）并走一帧；找不到盒 ⇒ 返回 false（断言会因此红） */
$tutClick = static function (
    Application $app,
    callable $pred,
    float $timestamp,
    BoxConstraints $constraints,
): bool {
    $element = findElement($app->root(), $pred);
    $box = $element?->renderObject();
    $size = $box?->size();
    if ($box === null || $size === null) {
        return false;
    }
    $origin = boxOrigin($box);
    $x = $origin[0] + $size->width / 2;
    $y = $origin[1] + $size->height / 2;
    $app->dispatchInput(PointerEvent::down($x, $y), $timestamp);
    $app->dispatchInput(PointerEvent::up($x, $y), $timestamp + 1.0);
    $app->frame($timestamp + 2.0, $constraints);

    return true;
};

/** 树上有没有这段文字 */
$tutHasText = static function (Element $root, string $needle): bool {
    return findElement(
        $root,
        static fn (Element $e): bool => $e->widget() instanceof Text
            && str_contains($e->widget()->content(), $needle)
    ) !== null;
};

/**
 * 浮层（任一 entry）里有没有这个标签。
 *
 * ★ 不取 `top()`：浮层栈顶可能是遮罩那一层，面板在它下面 —— 扫全部 entry
 *   才不会"看着开了、内容却找不到"。
 * ★ 同时认 `Text` 与 `Button`：面板里的选项是 `Button::make($city)`，
 *   **不是** `Text`（第一版只找 `Text` ⇒ 假红一次）。
 */
$tutOverlayHasLabel = static function (Application $app, string $needle): bool {
    foreach ($app->overlays()->all() as $entry) {
        $element = $entry->element;
        if ($element === null) {
            continue;
        }
        if (findElement(
            $element,
            static function (Element $e) use ($needle): bool {
                $widget = $e->widget();
                if ($widget instanceof Button) {
                    return $widget->label() === $needle;
                }

                return $widget instanceof Text && str_contains($widget->content(), $needle);
            }
        ) !== null) {
            return true;
        }
    }

    return false;
};

// ── ② 计数器：点 +1 ⇒ 文本真的变（State + setState 全链）──
check(
    'apps/tutorial · ② 计数器初始为 0',
    $tutHasText($tutApp->root(), 'count = 0')
);
$tutClicked = $tutClick(
    $tutApp,
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '+1',
    100.0,
    $tutConstraints
);
check('apps/tutorial · ② 找得到 +1 按钮并点中（盒中心命中）', $tutClicked);
check(
    'apps/tutorial · ② 点一次 ⇒ count = 1（setState 帧末统一重建）',
    $tutHasText($tutApp->root(), 'count = 1'),
    '树上仍是 ' . ($tutHasText($tutApp->root(), 'count = 0') ? 'count = 0' : '别的值')
);

// ── ③ 浮层：`overlayPanel()` 是声明式缝 ⇒ 激活即开，不需要组合根接线 ──
$tutOverlayBase = $tutApp->overlays()->count();
$tutOpened = $tutClick(
    $tutApp,
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '选择城市',
    200.0,
    $tutConstraints
);
check('apps/tutorial · ③ 找得到「选择城市」并点中', $tutOpened);
check(
    'apps/tutorial · ③ 激活 ⇒ 浮层多一层（overlayPanel 缝真的通）',
    $tutApp->overlays()->count() === $tutOverlayBase + 1,
    '层数 ' . (string) $tutApp->overlays()->count() . ' / 基线 ' . (string) $tutOverlayBase
);
// ★ 再走一帧：`placeOverlays()` 在 settle **之前**跑 ⇒ 面板内容是**下一帧**才建出来的
//   （真机上靠 `panelsLagBehind()` 把帧时钟上膛，用例里得自己多跑一帧）。
$tutApp->frame(210.0, $tutConstraints);
check(
    'apps/tutorial · ③ 浮层里是那三个选项（面板内容真建出来了）',
    $tutOverlayHasLabel($tutApp, '上海'),
    '浮层层数 ' . (string) $tutApp->overlays()->count()
);

// ★★ 浮层暴露的**框架缺陷**回归（2026-10-25，`RenderButton` 内禀宽漏 insets）：
//   `PopupPanel` 按 `child->intrinsicWidth(INF)` 定面板宽 ⇒ 按钮内禀宽若只算标签、
//   不算自己的左右内边距，面板就会比标签还窄 ⇒ **中文逐字竖排**（实测面板 48 宽、
//   按钮 28 宽、"北京"两行）。修前 `intrinsicWidth(INF)` 是 28 而实际排出 60。
//
//   ★ 判据取**「自然排出来的宽 === 内禀宽」**这条不变量，不写死像素：
//     它是"内禀宽表达的是真尺寸"的定义本身，改字号 / 换主题都不会假红。
//   ★ 用 `+1` 按钮（在 `Row` 里按自然宽排，没被 `Spacer` 挤）而不是面板里的按钮 ——
//     面板里那个正是被压的对象，拿它判会两边一起变小、看着"相等"。
$tutPlusBox = findElement(
    $tutApp->root(),
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '+1'
)?->renderObject();
$tutPlusSize = $tutPlusBox?->size();
check(
    'Layout · Button 内禀宽 = 自然排出来的宽（含内边距；漏了 ⇒ PopupPanel 挤成竖排）',
    $tutPlusBox !== null && $tutPlusSize !== null
        && $tutPlusBox->intrinsicWidth(INF) === $tutPlusSize->width,
    '内禀 ' . (string) ($tutPlusBox?->intrinsicWidth(INF) ?? -1.0)
        . ' / 实排 ' . (string) ($tutPlusSize?->width ?? -1.0)
);

// 再点一次 ⇒ 收回去（"激活 = 开/关"）；关掉之后 ④ 的点击才不会被遮罩吞掉
$tutClosed = $tutClick(
    $tutApp,
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '选择城市',
    220.0,
    $tutConstraints
);
check('apps/tutorial · ③ 再激活一次 ⇒ 浮层收回（开/关是同一个入口）', $tutClosed && $tutApp->overlays()->count() === $tutOverlayBase);

// ── ④ 异步：`after()` 由组合根递下来；700ms 后结果到达 ──
$tutLoad = $tutClick(
    $tutApp,
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '开始加载',
    300.0,
    $tutConstraints
);
check('apps/tutorial · ④ 找得到「开始加载」并点中', $tutLoad);
check(
    'apps/tutorial · ④ 点了 ⇒ 进加载态（loading 一帧都不能少）',
    $tutHasText($tutApp->root(), '加载中')
);
check(
    'apps/tutorial · ④ 还没到点 ⇒ 结果不出现（防"当场触发"）',
    !$tutHasText($tutApp->root(), '结果到了')
);
$tutApp->frame(1200.0, $tutConstraints);
check(
    'apps/tutorial · ④ 700ms 后 ⇒ 结果到达（after 的回调在 UI 线程 setState）',
    $tutHasText($tutApp->root(), '结果到了')
);

// ── ㊷b apps/showcase：示例台无头挂载（2026-10-25 补的闸门）─────────────────
// ★★ **为什么必须有这一段**：composer.json 早就注册了 `App\Showcase\`，但 smoke
//   从来没挂过这个应用 ⇒ 应用侧代码是"只有真机能证伪"的盲区。2026-10-25 真机
//   实测：`ShowcaseRoot` 里写了一个**不存在的** `IconName::Refresh`
//   （解释器 = Fatal "Undefined constant"，AOT = **Segmentation fault**、
//   没有任何文件名行号），而 smoke 1461 全绿。
//   ⇒ 这一段把"应用能挂起来"变成 smoke 断言，未定义的 enum case / 构造期错误
//     在解释器面就红，不等真机。
// ★ 视口取 **1920×1017**（真机客户区的实测值）：示例台左导航有 22 项，
//   800 高会 `RenderFlex 放不下` 直接 FATAL（那是视口不够，不是缺陷）——
//   夹具必须用真实尺寸，否则断言红得没有意义。
$sc2App = new ShowcaseApplication(new FakeDialog());
$sc2Surface = new SurfaceProbe();
$sc2Surface->resize(1920, 1017, 1.0);
$sc2Constraints = BoxConstraints::loose(new Size(1920.0, 1017.0));
$sc2App->attach($sc2Surface, $sc2Constraints);
$sc2App->frame(16.0, $sc2Constraints);
check('apps/showcase · 应用只写 buildRoot/title/commands（§4.5）', $sc2App->title() === 'Drift 示例台');
check('apps/showcase · 无平台也能挂载（表面是注入的，§7.2 裁决 4）', $sc2App->root() !== null);
// ★ 判据是**命令条数**不是像素（2026-10-28）：示例台左导航有 22 项，每项一行字
//   ⇒ `drawParagraph >= 22` 就是"每一项都上字了"，少画一项就红；而 `inkCount() > 0`
//   只要有**一个**字就恒真。真像素由 `golden/page` 的 20 张整页基线守着。
//   ★ 换成命令判据顺带把这一段的 1.5s 回放 + 1.05s 扫像素省掉了（原先 12.19s）。
$sc2List = $sc2Surface->list();
$sc2Text = drawOpCount($sc2List, DisplayList::OP_DRAW_PARAGRAPH);
$sc2Rect = drawOpCount($sc2List, DisplayList::OP_DRAW_RECT);
check(
    'apps/showcase · 首屏 22 个导航项都上了字（顶条/侧栏/内容都排开了，不是静默空白）',
    $sc2Text >= 22 && $sc2Rect >= 10,
    'drawParagraph ' . $sc2Text . ' / drawRect ' . $sc2Rect . ' / 命令 ' . $sc2List->count() . ' 条'
);

// AppBar::actions()（2026-10-25 新增的右动作位）：两个图标按钮必须在树上。
// ★ 按**可访问名**找（`IconButton` 的 label 不进视觉，只作 UIA Name）——
//   这正是"纯图标按钮必须有两个参数"的理由在测试面的兑现。
$sc2Actions = 0;
findElement(
    $sc2App->root(),
    static function (Element $e) use (&$sc2Actions): bool {
        if ($e->widget() instanceof IconButton && \in_array($e->widget()->label(), ['搜索', '刷新'], true)) {
            $sc2Actions++;

            return false;
        }

        return false;
    }
);
check(
    'apps/showcase · AppBar::actions() 的两个图标按钮在树上（按可访问名）',
    $sc2Actions === 2,
    '找到 ' . $sc2Actions . ' / 2'
);

// 命令缝（§11.4.3）：`showcase.refresh` 必须真有注册的 handler。
// ★ 未注册命令会走 `Errors::DIAGNOSTIC`（CommandRouter）—— 那种失败**不抛**，
//   所以"点了没反应"只能靠"handler 在表里"这条正断言来堵。
$sc2HasRefresh = false;
foreach ($sc2App->commands() as $sc2Name => $sc2Handler) {
    if ($sc2Name === 'showcase.refresh' && $sc2Handler instanceof CommandHandler) {
        $sc2HasRefresh = true;
    }
}
check(
    'apps/showcase · showcase.refresh 有注册的 handler（不是死命令）',
    $sc2HasRefresh,
    'commands=' . implode(',', array_keys(iterator_to_array($sc2App->commands())))
);

// ★ Feedback 页的模态演示（2026-10-25）：`Modal` 在此前**没有任何应用侧消费者**，
//   关闭按钮落地后补的入口。这条断言盯的是"组合根 → 页面 → showModal"这条链
//   真的通（缺一环就是"按钮点了没反应"，而那种失败不抛错）。
// ★ 必须**先切到 Feedback 页**：showcase 的页是按需建的（`pageFor($selected)()`），
//   首屏只有第 0 页 ⇒ 不点导航的话 Feedback 页的按钮根本不在树上。
//   （第一版直接 findElement ⇒ 假红。）
$sc2NavFeedback = static fn (Element $e): bool => $e->widget() instanceof NavButton
    && $e->widget()->label() === '反馈 Feedback';
$sc2NavEl = findElement($sc2App->root(), $sc2NavFeedback);
$sc2NavBox = $sc2NavEl?->renderObject();
check('apps/showcase · 左导航找得到「反馈 Feedback」', $sc2NavBox !== null && $sc2NavBox->size() !== null);
if ($sc2NavBox !== null && $sc2NavBox->size() !== null) {
    [$sc2NavX, $sc2NavY] = boxOrigin($sc2NavBox);
    $sc2NavW = $sc2NavBox->size()->width;
    $sc2NavH = $sc2NavBox->size()->height;
    $sc2App->dispatchInput(PointerEvent::down($sc2NavX + $sc2NavW / 2.0, $sc2NavY + $sc2NavH / 2.0), 100.0);
    $sc2App->frame(102.0, $sc2Constraints);
}
check(
    'apps/showcase · 切到 Feedback 页（标题出现在树上）',
    $tutHasText($sc2App->root(), '反馈 Feedback')
);
$sc2ModalBtn = findElement(
    $sc2App->root(),
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '打开对话框'
);
check(
    'apps/showcase · Feedback 页有「打开对话框」入口（Modal 有应用侧消费者）',
    $sc2ModalBtn !== null,
);
// 端到端：点它 ⇒ `showModal` 闭包被调 ⇒ 模态真的开着
if ($sc2ModalBtn !== null) {
    $sc2BtnBox = $sc2ModalBtn->renderObject();
    if ($sc2BtnBox !== null && $sc2BtnBox->size() !== null) {
        [$sc2BtnX, $sc2BtnY] = boxOrigin($sc2BtnBox);
        $sc2BtnW = $sc2BtnBox->size()->width;
        $sc2BtnH = $sc2BtnBox->size()->height;
        $sc2App->dispatchInput(PointerEvent::down($sc2BtnX + $sc2BtnW / 2.0, $sc2BtnY + $sc2BtnH / 2.0), 200.0);
        $sc2App->frame(202.0, $sc2Constraints);
    }
}
check(
    'apps/showcase · 点「打开对话框」⇒ 模态真的开着（组合根接线通）',
    $sc2App->isModalOpen() && $sc2App->modal()?->showsCloseIcon() === true,
    'open=' . ($sc2App->isModalOpen() ? 'yes' : 'no'),
);
// 关掉它（走刚注入的那条 dismiss），验证"开 → 关"闭环
$sc2App->closeModal();
check('apps/showcase · closeModal 关掉（闭环，不残留）', !$sc2App->isModalOpen());

// ── ㉜ 光标形状 / ㉗ Spinner / ㉗b 帧时钟 / ㉗c 有态产盒 已迁出 ─────────────────
//   · `tests/Feature/Input/CursorShapeTest.php`（15）—— 形状表 = 桥的 ABI / 声明落盒 /
//     命中链取最近 / 应用出口四态
//   · `tests/Unit/Widget/SpinnerTest.php`（14）—— 盒与内禀 / 紧约束两向 / 墨不拉伸 /
//     纯函数 / 8 档渐隐 / tick 只标脏 / 帧末换盒
//   · `tests/Feature/App/AnimationClockTest.php`（4）—— attach 首帧即推进 / 每帧一格 /
//     wantsFrame 两向（有动画要帧、无动画不空转）
//   · `tests/Unit/Widget/StateProducedBoxTest.php`（7）—— 叶子路径有盒无子且 build 零调用 /
//     修饰落自己 / Spinner 走叶子 / `State::build()` 默认零尺寸 / **反向自检**（不覆写
//     钩子 ⇒ build 真被调，证明计数器有牙 —— 原先在 smoke 起子进程，其实进程内就够）
//
// ── ㉘ 多页外壳：Dock / AppBar / NavButton + 命令激活 已迁出 ──────────────
//   → `tests/Unit/Layout/DockTest.php`（15）—— 五槽几何（top/bottom 全宽贴边、
//     左右中带全高、center 填满剩余）+ 无界主轴取内容自然高（自身高 112 /
//     bottom offsetY 97 / 中带三槽铺满 77 / 有界行为不动）+ 元素层挂接与全树封存
//     + 条拉满全宽、轨铺满中带高 + `expectBox` 量具自检（三种失败路径各一条）。
//   → `tests/Unit/Widget/AppBarTest.php`（10）—— 标题与按钮一行 + chrome 底铺满
//     整盒且颜色 = 主题 `Chrome`（**无子项**夹具：有文字时"有墨"恒真，见下）
//     + `windowButtons()` 三条命令名与顺序（比 smoke 多验了中间那个是 IconButton）
//     + `skipFocus()` 成对（跳过 / 照常进 Tab 序）。
//   → `tests/Feature/App/ShellCommandTest.php`（2）—— NavButton 的 Enter ⇒ 命令带
//     arg（`nav.users#7/`）/ Button 的 Space ⇒ `onPressed`（纯 UI 回调）。
//   ★ 原段 198 行混了五个主题（五槽布局 / 无界主轴 / 元素挂接 / AppBar 契约 /
//     命令激活），按"坏法不同"拆三处。
//   ★ 量具自检从"跑子进程数 FAIL 行"改成 `SmokeTestCase::takeFailures()` 当场
//     读回 —— `check()` 只记录不抛，故意制造的失败能在同方法内取走、不污染 flush。
//   ★ `expectBox()` 在 smoke 侧本段是**最后一个使用者** ⇒ 函数与 `expectbox` case
//     一并删除（死代码）。tests 侧同名实现与自检在 `DockTest` 里。
//   ★ 迁移中修掉一处假绿：原"chrome 底有墨"用 `inkCount()`（数非白像素），而夹具里
//     有标题文字 —— **文字自己就有墨**，底色整段没画也绿（变异 M4 实测）⇒ 换成
//     无子项夹具 + "Chrome 色 ≥ 90% + 中心像素 = 主题 Chrome"。

// —— 语义节点激活（§9 / M5 无障碍的"可操作"半边 = UIA `InvokePattern` 的 PHP 侧）
//   ★ 实现落成"在节点中心派发一次合成 tap"，所以判据必须落在**效果**上
//     （`pressed` 变了），不是"方法返回 true" —— 后者在"点歪了 / 点空了"
//     的时候照样绿，等于没测。
//   ★ 这条同时锁住"激活语义只有一处"：读屏点与鼠标点走同一条
//     输入路由 → 命中 → `Activatable::activate()`。
$invApp = new NavApplication();
$invApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$invTree = SemanticsBuilder::build($invApp->rootElement(), null);
$invIdx = $invTree->findByRoleAndName(SemanticsRole::Button->value, '取消');
check(
    '语义激活 · 按节点索引点中「取消」⇒ onPressed 被调（Invoke 走合成 tap）',
    $invIdx >= 0 && $invApp->invokeSemanticsNode($invIdx) === true && $invApp->pressed === ['cancelled'],
    'idx=' . $invIdx . ' pressed=' . implode(',', $invApp->pressed),
);
check(
    '语义激活 · 越界索引 ⇒ false（壳据此回 UIA_E_ELEMENTNOTAVAILABLE，不能假装成功）',
    $invApp->invokeSemanticsNode(-1) === false && $invApp->invokeSemanticsNode(9999) === false,
    'ok',
);
// ★ 事件缝本身：壳把 UIA `Invoke` 翻成 `SemanticsInvokeEvent` 塞进输入队列，
//   `dispatchInput()` 必须接住并激活。**这条测的是"平台→框架"的接缝**，
//   上一条测的是"框架内部动作"—— 少了本条，接缝断了（事件被 `dispatch()` 的
//   `else` 兜底吞掉）也只有真机才发现，而真机现象是"命令执行了、画面不动"。
$invEvtApp = new NavApplication();
$invEvtApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$invEvtIdx = SemanticsBuilder::build($invEvtApp->rootElement(), null)
    ->findByRoleAndName(SemanticsRole::Button->value, '取消');
$invEvtApp->dispatchInput(new SemanticsInvokeEvent($invEvtIdx));
check(
    '语义激活 · SemanticsInvokeEvent 经 dispatchInput 落地（壳的接缝，不是框架内部直调）',
    $invEvtIdx >= 0 && $invEvtApp->pressed === ['cancelled'],
    'idx=' . $invEvtIdx . ' pressed=' . implode(',', $invEvtApp->pressed),
);

// —— 语义写值（§4.4 提交 2 = UIA `ValuePattern` / `RangeValuePattern` 的 PHP 侧）
//   ★ 判据落在**回写值**上（`onChanged` 真收到），不是"方法返回 true"——
//     后者在"顺着父链找到了控件但没人接值"时照样绿，等于没测。
//   ★ 反例两条：受控但没人接 onChanged（TfApplication）⇒ false；越界 ⇒ false。
$svApp = new SemValueApplication();
$svApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$svIdx = SemanticsBuilder::build($svApp->rootElement(), null)
    ->findByRoleAndName(SemanticsRole::TextField->value, '搜索');
check(
    '语义写值 · TextField 按节点索引 ⇒ onChanged 收到新值（ValuePattern 回写路径）',
    $svIdx >= 0 && $svApp->setSemanticsValue($svIdx, 'hi') === true && $svApp->got === 'hi',
    'idx=' . $svIdx . ' got=' . var_export($svApp->got, true),
);
check(
    '语义写值 · 越界索引 ⇒ false（壳据此回 UIA_E_ELEMENTNOTAVAILABLE，不假装成功）',
    $svApp->setSemanticsValue(-1, 'x') === false && $svApp->setSemanticsValue(9999, 'x') === false,
    'ok',
);
$tfNoCb = new TfApplication();
$tfNoCb->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$tfNoCbIdx = SemanticsBuilder::build($tfNoCb->rootElement(), null)
    ->findByRole(SemanticsRole::TextField->value);
check(
    '语义写值 · TextField 没接 onChanged ⇒ false（受控值没人接，不假装写成功）',
    $tfNoCbIdx >= 0 && $tfNoCb->setSemanticsValue($tfNoCbIdx, 'x') === false,
    'idx=' . $tfNoCbIdx,
);
// 事件接缝：壳把 UIA `SetValue` 翻成 `SemanticsSetValueEvent`，`dispatchInput` 必须接住。
// 少了本条，接缝断了（事件被 `dispatch()` 兜底吞掉）也只有真机才发现。
$svEvtApp = new SemValueApplication();
$svEvtApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$svEvtIdx = SemanticsBuilder::build($svEvtApp->rootElement(), null)
    ->findByRoleAndName(SemanticsRole::TextField->value, '搜索');
$svEvtApp->dispatchInput(new SemanticsSetValueEvent($svEvtIdx, '派发来的'));
check(
    '语义写值 · SemanticsSetValueEvent 经 dispatchInput 落地（壳的接缝）',
    $svEvtIdx >= 0 && $svEvtApp->got === '派发来的',
    'idx=' . $svEvtIdx . ' got=' . var_export($svEvtApp->got, true),
);

// Slider 的 Application 层：与 TextField 同一条 `SemanticsValueEditable` 路，
// 但回写走 `emit()`（夹区间）。这条同时证明"按节点索引找到的就是 Slider 本身"。
$slSvApp = new SliderApplication();
$slSvApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 400.0)));
$slSvIdx = SemanticsBuilder::build($slSvApp->root(), null)
    ->findByRole(SemanticsRole::Slider->value);
check(
    '语义写值 · Slider 按节点索引 ⇒ onChanged(77)（RangeValuePattern 回写路径）',
    $slSvIdx >= 0 && $slSvApp->setSemanticsValue($slSvIdx, '77') === true
        && $slSvApp->events === [['a', 77.0]],
    'idx=' . $slSvIdx . ' events=' . json_encode($slSvApp->events),
);

// ── 交互状态层 hover / 指针按下激活 / Activatable 能力 已迁出 ────────────────
//   → `tests/Feature/Input/HoverTest::testHoverStateGoesToTheAcceptingBox`（6）——
//     悬停**视觉态**。与同文件 `testHoverNotifiesOnlyOnTargetChange` 判的 `hover()`
//     **回调**是 `FocusManager::hoverTo()` 里**两条独立判据**（一条只认
//     `acceptsHover()` 的盒，一条只认声明了 `hover()` 的祖先）。
//     判据：`acceptsHover()` 成对（按钮接受 / 容器不接受）+ 起点未悬停（否则下面
//     两条"复位"恒真）+ 推给按钮 + 悬停容器复位 + `hoverTo(null)` 复位。
//   → `tests/Feature/Input/ActivationTest.php`（6 方法 / 19 条）——
//     ① 指针按下即激活：按下列表 ⇒ 命令带 arg / 点空白 ⇒ **不**重复触发（落点由条高
//        推出来并复核"不在任何 Button 盒内"，且先证"焦点没被移走"）/ 按下取消 ⇒ onPressed；
//     ② 激活键的边界：无焦点 Enter 无事、方向键不激活（各带前置，否则"不该发生"恒真）；
//     ③ **结构性**判据：扫 `src/Widget` 找所有声明了 `activate()` 的类，断言都
//        implements `Activatable` —— 比白名单强：smoke 原表只写 4 个，漏了
//        `IconButton` / `NavButton` / `MenuItem` / `Radio` / `Toggle`；
//     ④ `Menu::activate` 覆写只开面板（命令与 onOpen **同记一个数组**）；
//     ⑤ Button 命令优先 / 无命令回落 onPressed（成对）；
//     ⑥ ToggleBase 家族按 kind 分支（开关布尔 / 单选钮给自己的值 / 已选不重复通知）。
//   ★ 一个段号混了三个主题（悬停视觉态 / 指针出口 / 能力声明）⇒ 按宿主重新归类：
//     悬停并入既有 `HoverTest`，其余进 `ActivationTest`。
// —— `RenderObject::panelRect()` 能力方法 + 面板限高 已迁出 ——
//   → `tests/Feature/Overlay/PopupPanelTest.php`（13）—— 普通盒不表态 + 面板盒表态
//     （**成对**：只测一边等于没测）/ 宽 = max(minWidth, 最宽项) / 长菜单高 ==
//     PANEL_MAX_HEIGHT 且自然高超上限、末项落在面板下沿外（三条一起才算验到限高）/
//     滚一格 48、滚两格 96（真实滚轮通道）/ 短菜单贴着内容、maxScroll = 0。

// ── ㉝ 错误边界容器 + AcceleratorTable：主体已迁出，余子进程判据 ────────────
//   · `tests/Unit/Input/AcceleratorTableTest.php` —— 绑定后可查 / 未绑定 ⇒ null /
//     修饰键不同即不同组合 / count 与 isEmpty
//   · `tests/Feature/Input/KeyRoutingPriorityTest.php` —— 加速键第 ① 环在焦点之前 /
//     未绑定的键落到焦点链 / 让位规则 ② / TextField 自带 Ctrl+A 独占且全选真的发生
//   · `tests/Feature/Input/EditingCommandLoopTest.php` —— 编辑命令环四道门控的回落语义
//     （无焦点 / 无剪贴板端口 / 剪贴板空 / 无选区），每条都成对验"该吞的吞、该放的放"
//   · `tests/Feature/Input/PointerSelectionTest.php` —— 指针框选 down/move/up/leave
//     与反向拖动 + IBeam 光标
//   ★ 下面 2 条是**子进程**判据（无边界 Fatal），依赖 `runCase(__FILE__)`。
//   ★ 冲突 Fatal 那 2 条已并入 `tests/Unit/Input/AcceleratorTableTest.php`
//     （走 `runFatalCase()`，用例在 `tests/Support/fatal_cases.php`）。
// ── B/C/D 三层兜底（构造期 / build 期 / 渲染期）已迁出 ─────────────────────
//   · `tests/Feature/Widget/ErrorBoundaryTest.php` —— 构造期（rebuild 捕获 · 换占位 ·
//     带原文 · 占位出墨）· build 期（settle 捕获 · 多轮收敛仍在边界内）· 渲染期
//     （layout 兜底 160×48 / tight 被提到上限 / paint 不冒泡且兜底有墨）+ CJK 上屏
//   ★ 第 ④ 层（无边界 ⇒ 帧循环 Fatal）→ `fatal_cases.php` 的 `noboundary`，
//     判据 → `tests/Feature/Widget/ErrorBoundaryTest.php`（第 39 批）。


// ── ㉞ 菜单 / 面板 / 模态 / 浮层栈 / 拖放 已迁出（110 条）──────────────────
//   · `tests/Feature/Overlay/MenuTest.php`（26）—— 键盘开 + 锚点钉左下 + 夹进
//     客户区 / 关闭四来源（点外吞按下 · 分发后才关 · ESC 先拦 · 点内不关）/
//     右键最小集 / 指针点项 / Menu·MenuItem·MenuBar·ContextMenu 四件形体 /
//     菜单项盒两端分布 + 分隔线有墨
//   · `tests/Feature/Overlay/ModalTest.php`（27）—— 盖满客户区 / 面板居中 /
//     遮罩半透明且四角一致 / 面板在遮罩之上 / dismissible 两向（关 vs 吞）/
//     点面板内按钮 ⇒ 分发但不自关 / 关闭 X 由框架注入 / 模态叠在菜单之上 /
//     build = ModalHost → Center → PopupPanel
//   · `tests/Feature/Overlay/OverlayStackTest.php`（10）—— 三层同屏 / 上层 scrim
//     盖下层面板（**像素**，不是 SVG 字符串）/ 锚点卸载即关 / 贴底翻转 / 嵌套
//     base / barrier 两向 / 焦点回锚 / 焦点陷阱
//   · `tests/Feature/Input/PointerCaptureTest.php`（9）—— 捕获期不命中测试 /
//     抬起与卸载与失活三条释放路径 / 浮层优先于捕获 / 失活不关浮层（反向守卫）/
//     失活幂等且未挂载也安全
//   · `tests/Feature/Input/DragDropTest.php`（9）—— 进入/离开/不重复通知/drop
//     交接 / 拖空与 ESC 都不派发 / 三个声明位（draggable·contextMenu·dragTarget）
//   ★ 切分判据是**被测对象**不是段号：菜单段里其实混着拖放（主题是 `Drag`）、
//     浮层栈段里混着指针捕获与窗口失活（主题是"手势什么时候放手"）。
//   ★ 面板限高与 `panelRect()` 两条另见下一处指针（面板盒本身）。
// ── ㉟ 合成与跳过 layout 已迁出 ─────────────────────────────────────────────
//   → `tests/Unit/Frame/ComposeSkipLayoutTest.php`（2 方法 / 7 条）——
//     首帧 layout 1 / paint 1（**量具自检**：计数器恒 0 时后面全恒真）·
//     纯动画帧 layout 仍 1 而 paint 涨到 2 · `setCompose()` 不触发 layout 且
//     opacity 读回 0.5 · 合成参数**真进命令流**（`OP_LAYER_PUSH` + `OP_TRANSLATE`）·
//     `settle()` 有脏 ⇒ true / 干净 ⇒ false（成对：恒 true 永远不跳过 layout，
//     恒 false 改了尺寸也不重排）。
//   ★ 判据必须是**调用次数**：画面在"重排了但结果一样"和"没重排"下逐像素相同。
//   ★ 与 `Feature/App/WindowFrameTest` 分工：那边判**换尺寸**时的重排，这边判
//     **不换尺寸**时该省的工作有没有真的省掉。

// ── ㉞c 卡片 / Flex 交叉轴拉伸 已迁出 ──────────────────────────────────────
//   · `tests/Unit/Widget/CardTest.php`（13）—— 样式落在子控件 mods 上 / 内边距
//     真进盒尺寸 / 内容原点 = padding / 内禀宽含 padding / 空卡片只占内边距 /
//     默认边框 + 圆角 / 显式 background 让位（边框与圆角**两条分开**）/ 阴影
//     三层由外向内加深 + 不越过第 3 层 / shadow 默认 0 盒外纯白 / 紧宽下内容撑满
//   · `tests/Unit/Layout/FlexTest::testCrossAxisStretchFillsCrossOnly`（5）——
//     ★ 这 5 条原本夹在卡片段中间，但主题是 `FlexAlign::Stretch`（主轴与交叉轴
//       曾写反 ⇒ 同级 Expanded 被挤成 0 宽），归 Flex 不归 Card。
//   ★ `$kidsOf` 是段内局部闭包：它的第二个使用者出现在 700 行外（侧栏宽度），
//     已下沉成 `childBoxes()`（`tests/Support/functions.php` 与 `tools/smoke.php`
//     各一份，同名同实现）。


// ── ㉞e 定尺寸 / 定比例 / 扁平按钮 已迁出 ──────────────────────────────
//   → `tests/Unit/Layout/SizingTest.php`（11）—— `SizedBox` 定宽（盒与孩子
//     一起拉满、未定轴透传、内禀宽 == 定宽、父更紧听父、无子盒 = 定值）+
//     `AspectRatio` 表驱动 5 口径（loose / 高度不够回退 / 显式 width 优先 /
//     两轴定死无自由度 / 比例作用于含 padding 的盒）+ 侧栏集成（200 − 2×10 = 180）。
//   → `tests/Unit/Widget/ButtonTest.php::testFlatVariantDropsBoxButKeepsItsFootprint`（3）
//     —— `ButtonVariant::Flat` 盒样式 isNone / Default 仍有边框 / Flat 仍占位。
//   ★ 迁出时发现原段（412 行）混了**四个不相干主题**：定尺寸+比例 / 扁平按钮 /
//     RichText / 焦点环。只迁前两个；后两个留在下面各自成段。

// —— W8-5 RichText 已迁出 ─────────────────────────────────────────────
//   → `tests/Unit/Text/RichTextTest.php`（10）—— 两段各自成 run（前段默认色
//     / 后段显式色）+ 后段 cluster 是全文字节偏移 + 行高按最大 span 字号 +
//     缓存键不收颜色/背景色（两个不同颜色 ⇒ 同一个键）+ tag 与下划线同 run +
//     tagAt 字节偏移（链接中点命中 / 普通文本空串）+ cursorAt 跟 tagAt 走 +
//     background 与 argb 分开。
//   → `tests/Feature/Input/RichTextKeyboardTest.php`（3）—— onSpanTap ⇒ 进 Tab 序
//     / firstTag 是 Enter 的落点 / Enter 真的把 tag 交出去且键被消费。
//   ★ 拆两层而不是一个文件：排版与缓存键只要一次 `layout()`（Unit）；
//     键盘可达要过 `FocusManager`（应用级路由，Feature）。
//   ★ 缓存键那两条是**性能判据**（改颜色不重排），失败时功能全对、只是慢 ——
//     所以它们和排版断言分开成方法，不能混在一起。
// —— W8-5 续作五：焦点环 已迁出 ───────────────────────────────────────
//   → `tests/Feature/App/FocusRingTest.php`（7）—— Tab 聚焦：wantsFrame 第七判据
//     为真 / 无焦点时不画 / 下一帧画出 Accent 描边（(19,19) 外扩 1px）/ 画完回落；
//     指针点击：环消失（`:focus-visible`）/ "隐藏"本身也请求一帧。
//   ★ 拆两个方法（键盘出现 / 指针消失），并给后者补一条**前置断言**"点击前是
//     描边"：smoke 里"点击后不是 Accent"隐式依赖 200 行外的"Tab 后是 Accent"，
//     独立成方法后那个前提没了 ⇒ 环从没画出来过时它是恒真的。
//   ★ 第七判据是**真机现象**（Tab 后环要等下次鼠标移动才出现），smoke 因为显式
//     跑两帧看不见 ⇒ "跑两帧后像素对"与"框架自己知道该跑一帧"必须分开断言。
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

// ★★ 这里断言的是"**正在**刷新…"，不是"已刷新"。
//   `UserCommands::refresh()` 只**发起**（`AsyncCommand::run()` 立刻返回），而
//   `CommandHandler::handle()` 是**同步**的 —— 它不可能知道异步结果。以前在这里
//   接一句 `say('已刷新')`，于是转圈还在转、snackbar 却说完成了 —— 那是**谎言**。
//   "完成了没有"由 `AsyncValue` 那四态回答（页面读 `$store->page()->value()` 画
//   loading / data / error）。⇒ 判据跟着改成诚实形态。
check(
    'apps/users · 跨页共享态：刷新 ⇒ 状态栏播报"正在刷新…"（发起，不是完成）',
    $usersApp->appStore()->statusLine() === '正在刷新…',
);
$usersApp->dispatch('nav.signup');
check('apps/users · 跨页共享态：nav.signup ⇒ currentPage=signup', $usersApp->appStore()->currentPage() === 'signup');
check(
    'apps/users · 跨页共享态：切页后状态栏仍在（共享态不随页销毁）',
    $usersApp->appStore()->statusLine() === '正在刷新…',
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

// ── ㊱ TaskHost 端口：`Job` / `TaskHost` / `SyncTaskHost`（§17.2 后端 B）──
//
// ★★ 2026-10-18 **P0 的语义变更**：从"同步直调 `$onDone`"改为"**入队 → 每帧 drain**"。
//   同步后端仍在 `submit()` 时跑完 `run()`，但回调**不在** `submit()` 的调用栈上 ——
//   它由 `drain()` 在 UI 线程的下一帧取出执行（§17.4.2 的两条不变量）。
//   ★ 这条改动是**有意的**，理由见 `SyncTaskHost` 的类注释：换异步后端时
//     "提交 → 某一帧拿到结果"的语义不变，业务代码不用动。**改回直调就是破坏它。**
//   端口层判据（八条）：① 当帧不回调 ② drain 后回调/值正确/恰好一次 ③ drain 取走即清空
//   ④ FIFO ⑤ 搬得动对象 ⑥ 签名收窄仍生效 ⑦ swap（drain 中新入队留到下一帧）
//   ⑧ 错误通道（run 抛 ⇒ onError，不调 onDone）。应用层四条在 ㊲。
//
// ★ **诚实边界**：端口的**生产消费者**仍是 `HttpUserSource`（§A.2.8，归 M4）；
//   本段是"端口契约"的判据，`apps/users` 走的是 `MemoryUserSource`（同步）。

// ★ **统一结算器**（N3）：与 `PumpHost::complete()` 逐字同构 —— 端口层自己搬结果，
//   不借框架。`Future` 是唯一的结果形状 ⇒ 这里只需 resolve/reject 两个分支。
$settle = static function (Completion $c): void {
    $error = $c->error;
    if ($error !== null) {
        $c->future->reject($error);

        return;
    }

    $c->future->resolve($c->value);
};

$tasks = new SyncTaskHost();
check('TaskHost · `SyncTaskHost` 是端口的实现', $tasks instanceof TaskHost);
check('TaskHost · `SumJob` 是 `Job` 的实现（类，不是闭包）', new SumJob(1) instanceof Job);

// ① 当帧不回调：submit 只入队
$delivered = [];
$tasks->submit(new SumJob(10))->then(function (int $sum) use (&$delivered): void {
    $delivered[] = $sum;
});
check(
    'TaskHost · submit() 之后回调**未**执行（结果只在队列里，不在调用栈上）',
    $delivered === [],
    'delivered=' . implode(',', $delivered),
);

// ② drain 取出并回调：值正确 + 恰好一次
$tasks->drain($settle);
check(
    'TaskHost · drain() 把结果交给 then()（1+…+10 = 55）',
    $delivered === [55],
    'delivered=' . implode(',', $delivered),
);
check('TaskHost · 回调恰好一次（不是 0 次也不是 2 次）', count($delivered) === 1);

// ③ drain = 取走：队列已清空 ⇒ 再 drain 不重复回调
$tasks->drain($settle);
check('TaskHost · drain() 取走即清空（再 drain 不重复回调）', count($delivered) === 1, (string) count($delivered));

// ④ 顺序：连提交 ⇒ FIFO
$order = [];
$tasks->submit(new SumJob(1))->then(function (int $n) use (&$order): void { $order[] = 'a' . $n; });
$tasks->submit(new SumJob(3))->then(function (int $n) use (&$order): void { $order[] = 'b' . $n; });
$tasks->submit(new SumJob(6))->then(function (int $n) use (&$order): void { $order[] = 'c' . $n; });
$tasks->drain($settle);
check(
    'TaskHost · 多任务按提交序回贴（FIFO）',
    $order === ['a1', 'b6', 'c21'],
    'order=' . implode(',', $order),
);

// ⑤ 搬对象：闭包签名把 `mixed` 收窄成 `TaskRow` ⇒ 回调拿到的就是 Job 造的那个实例
$gotRow = null;
$tasks->submit(new TaskRowJob('ada'))->then(function (TaskRow $r) use (&$gotRow): void {
    $gotRow = $r;
});
$tasks->drain($settle);
check(
    'TaskHost · 端口搬得动类实例（`mixed` 由调用点闭包签名收窄）',
    $gotRow instanceof TaskRow && $gotRow->name === 'ada' && $gotRow->len === 3,
    $gotRow instanceof TaskRow ? $gotRow->name . '/' . $gotRow->len : 'null',
);

// ⑥ 反向自检：收窄不是"随便什么都收" —— 换类型就该被 PHP 拦下。
//   ★ 抛点在 **drain()**（不是 submit）：队列把"回调时机"挪了，类型检查跟着挪 ——
//     这正是"回调真的在 drain 里跑"的第二重证据。
// ★ N3 之后**异常不再逃出 drain**：`Future::then()` 的回调抛错会变成下游 Future
//   的失败（`catch()` 能接住），没人接才走 Fatal。所以判据从"catch 到 TypeError"
//   改成"catch 里拿得到 TypeError" —— 证明的仍是**收窄在 drain 时才生效**。
$typeError = null;
$tasks->submit(new SumJob(1))->then(function (TaskRow $r): void {
})->catch(function (\Throwable $e) use (&$typeError): void {
    $typeError = $e;
});
check('TaskHost · 收窄在 drain 之前没有触发（回调确实等到 drain 才跑）', $typeError === null);
$tasks->drain($settle);
check(
    'TaskHost · 闭包签名收窄在 drain 时生效（传错类型 ⇒ TypeError，不是静默）',
    $typeError instanceof \TypeError,
    $typeError instanceof \Throwable ? $typeError->getMessage() : 'null',
);

// ⑦ swap 语义：drain 期间新入队的留到下一帧（否则"回调里再 submit"会让一帧无限长）
$waves = [];
$tasks->submit(new SumJob(1))->then(function (int $n) use (&$waves, $tasks): void {
    $waves[] = 'a' . $n;
    $tasks->submit(new SumJob(2))->then(function (int $m) use (&$waves): void { $waves[] = 'b' . $m; });
});
$tasks->drain($settle);
check(
    'TaskHost · drain 期间新入队的留到下一帧（一帧的工作量有界）',
    $waves === ['a1'],
    'waves=' . implode(',', $waves),
);
$tasks->drain($settle);
check('TaskHost · 下一帧取到上一帧入队的（a1, b3 —— SumJob(2) = 3）', $waves === ['a1', 'b3'], 'waves=' . implode(',', $waves));

// ⑧ 错误通道：`run()` 抛异常 ⇒ 走 onError（两条通道**互斥**，不是都调）
$boom = null;
$okCalled = 0;
$tasks->submit(new ThrowingJob('设备离线'))
    ->then(function (int $v) use (&$okCalled): void { $okCalled++; })
    ->catch(function (\Throwable $e) use (&$boom): void { $boom = $e; });
$tasks->drain($settle);
check(
    'TaskHost · run() 抛异常 ⇒ 走 catch()（异常原文带出）',
    $boom instanceof \RuntimeException && str_contains($boom->getMessage(), '设备离线'),
    $boom instanceof \Throwable ? $boom->getMessage() : 'null',
);
check('TaskHost · 失败任务**不**调 then()（两条通道互斥）', $okCalled === 0, (string) $okCalled);

// ── ㊲ P0 接线：结果队列接进帧循环（§17.4.2 的帧驱动 + 同帧重建）──────────
//
// ★ 判据只有**应用层**测得出：端口层的 drain 是手调的，看不出"帧有没有驱动它"、
//   也看不出"drain 排在 settle 之前"。
//   ★ 反例自检（T1 后手做）：把 `Application::frame()` 里的 `pumpTasks()` 挪到
//     `$frame->run(...)` 之后 ⇒ ②"同帧重建"必红（builds 不增、seen 是旧值）。

$taskApp = new TaskApplication();
$taskApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 240.0)));
$taskState = $taskApp->watcherState();
check(
    'P0 · 应用默认装上后台端口（`taskHost()` 默认 SyncTaskHost）',
    $taskApp->tasks() instanceof TaskHost,
);
check(
    'P0 · 挂载时 watcher 只建一次',
    $taskState !== null && $taskState->builds === 1,
    'builds=' . ($taskState?->builds ?? -1),
);

// ① 帧驱动：submit 之后当帧不回调，frame() 才回调
$hit = 0;
$taskApp->tasks()?->submit(new SumJob(4))->then(function (int $sum) use (&$hit): void {
    $hit = $sum;
});
check('P0 · submit() 之后回调未执行（帧还没来）', $hit === 0, (string) $hit);
$taskApp->frame(16.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P0 · frame() 的 drain 段执行了回调（1+2+3+4 = 10）', $hit === 10, (string) $hit);

// ② 同帧重建：`onDone` 里改 Store ⇒ 本帧 settle 就重建（drain 在 settle **之前**）
$buildsBefore = $taskState?->builds ?? -1;
$taskApp->tasks()?->submit(new SumJob(3))->then(function (int $sum) use ($taskApp): void {
    $taskApp->store->setStatus($sum * 10); // 6 ⇒ 60
});
$taskApp->frame(32.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check(
    'P0 · then() 里改 Store ⇒ **同帧**重建（builds +1 且读到新值）',
    $taskState !== null && $taskState->builds === $buildsBefore + 1 && $taskState->seen === 60,
    sprintf('builds %d→%d seen=%d', $buildsBefore, $taskState?->builds ?? -1, $taskState?->seen ?? -1),
);

// ③ 队列空了就不再回调（下一帧不重建）—— "结果不会被重复交付"
$buildsAfter = $taskState?->builds ?? -1;
$taskApp->frame(48.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check(
    'P0 · 队列空了就不再回调（下一帧不重建）',
    $taskState !== null && $taskState->builds === $buildsAfter,
    'builds=' . ($taskState?->builds ?? -1),
);

// ④ 失败走 onError（应用层）：回调抛错不许拖垮帧循环
$caught = null;
$taskApp->tasks()?->submit(new ThrowingJob('服务不可达'))
    ->then(function (int $v): void {
    })
    ->catch(function (\Throwable $e) use (&$caught): void {
        $caught = $e;
    });
$taskApp->frame(64.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check(
    'P0 · 应用层失败走 catch()（帧照常走完）',
    $caught instanceof \RuntimeException && $caught->getMessage() === '服务不可达',
    $caught instanceof \Throwable ? $caught->getMessage() : 'null',
);

// ⑤ 不装端口：`taskHost()` 返回 null ⇒ 不建端口、frame() 照常
$noTaskApp = new TaskApplication();
$noTaskApp->withoutTasks();
$noTaskApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 240.0)));
check('P0 · taskHost() 返回 null ⇒ 不装端口', $noTaskApp->tasks() === null);
$noTaskApp->frame(16.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P0 · 无端口时 frame() 行为不变（drain 段整段跳过）', $noTaskApp->root() !== null);

// ⑦ 一帧交付**有预算**（2026-10-30 P1）—— "一次回来几百条"到底会怎样
//
// ★ 这是 `docs/UI-THREAD-MODEL-2026-10-30.md` §7 承认"没做过"的那个量具。
//   场景不是编的：`Future::all()` 聚合几百个请求、批量上传几百个文件，都会
//   让 `drain()` 一次面对几百条 `Completion`。
// ★ 判据用**条数**而不是耗时：CI 机器上毫秒数会抖，而"交了几条 / 分了几个帧"
//   是确定的。三条合起来刚好覆盖三个实现契约：
//   ① `delivered === 500` ⇒ 一条不漏（剩余没被丢掉）
//   ② `frames > 1`        ⇒ 预算真的生效（否则 500 条一帧全交）
//   ③ `frames <= 上限`    ⇒ **每帧至少推进一条**（有帧零推进 ⇒ 帧数跑满上限）
//      —— 这一条把"首条无条件放行"从"不可证"变成"可证"：那是唯一会让
//      `pending()` 不下降的形态。
$burstHost = new SyncTaskHost();
for ($i = 0; $i < 500; $i++) {
    $burstHost->submit(new SumJob(1));
}
check('P1 · 500 条等交付', $burstHost->pending() === 500, (string) $burstHost->pending());

$burstDelivered = 0;
$burstFrames = 0;
$burstWorstMs = 0.0;

// ★ 回调里的活必须用**忙等**模拟，不能用 `usleep`：Windows 上 `usleep(200)`
//   会被量化到系统时钟中断（~15.6ms）⇒ 每条回调都远超 4ms 预算 ⇒ 每帧只交 1 条
//   ⇒ 跑满上限也交不完（本量具第一版正是这么红的：400 帧只交 400 条）。
//   那测的是"睡眠精度"，不是预算。
$burstSpinNs = 200_000;   // 0.2ms ≈ 一次 JSON 解析 / 一次 diff 的量级

// ★ 上限 400 帧：预算 4ms、每条 0.2ms ⇒ 理论约 25 帧完成；跑到 400 只可能是
//   实现坏了（零推进 / 丢了剩余），那时 `$burstDelivered === 500` 自然不成立。
while ($burstHost->pending() > 0 && $burstFrames < 400) {
    $t0 = hrtime(true);
    $burstHost->drain(
        static function (Completion $c) use (&$burstDelivered, $burstSpinNs): void {
            $burstDelivered++;
            $until = hrtime(true) + $burstSpinNs;
            while (hrtime(true) < $until) {
            }
        },
        4,
    );
    $burstWorstMs = max($burstWorstMs, (hrtime(true) - $t0) / 1e6);
    $burstFrames++;
}

check('P1 · 500 条一条不漏（剩余没被丢掉）', $burstDelivered === 500, (string) $burstDelivered);
check('P1 · 分了多帧（预算真的生效，不是一帧全交）', $burstFrames > 1, '帧数 ' . $burstFrames);
check(
    'P1 · 每帧至少推进一条（没有零推进的帧 ⇒ 不会活锁）',
    $burstFrames < 400,
    '帧数 ' . $burstFrames . '（上限 400）',
);
check(
    'P1 · 单帧不超预算太多（预算 + 一条回调的量级）',
    $burstWorstMs < 20.0,
    sprintf('最差 %.1fms（预算 4ms）', $burstWorstMs),
);

// ⑥ 未给 onError ⇒ Fatal 序列（三态的 Error 分支不能被静默吞掉）
fresh($log);
[$code, $taskErrOut] = runCase(__FILE__, 'taskerr', null);
check(
    'P0 · 任务失败且没挂 catch() ⇒ Fatal（响亮，不静默）',
    $code === 3,
    '实际 ' . $code . ' / ' . $taskErrOut,
);
check('P0 · 没挂 catch() 时写了 Errors.log', str_contains(logText($log), '没有 catch()'));
fresh($log);

// ★ 聚合体同样是"任务"：G2 不能只保护单条 `submit()`。
[$allCode, $allErrOut] = runCase(__FILE__, 'allerr', null);
check(
    'P0 · `Future::all()` 链上漏 catch() 同样 Fatal（聚合体继承了"任务"）',
    $allCode === 3,
    '实际 ' . $allCode . ' / ' . $allErrOut,
);
check('P0 · 聚合体失败也写了 Errors.log', str_contains(logText($log), '没有 catch()'));
fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㊴ P2：`IoPoller` 非阻塞 IO（§17.4.4）────────────────────────────────
//
// ★ 前提已实测（probes §12.3 [J3]）：`stream_socket_pair` + `stream_set_blocking(false)`
//   + `stream_select(..., 0)` 在 AOT 产物里全部可用。
// ★ 为什么不是"线程里阻塞读"：线程起价 30ms、进程 275ms，而 `select(0)` 是微秒级
//   （§17.2.6 的实测）。★ 与 Swoole 的差别：它的 `Event::dispatch()` 阻塞到下一个事件。
// 判据：① 无数据不回调且不阻塞 ② 写入后回调一次且读得到 ③ unwatch 后不再回调
//   ④ 多监听只回调就绪的那个 ⑤ IO 回调改 Store ⇒ 同帧重建

$pollApp = new TaskApplication();
$pollApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 240.0)));
check('P2 · 应用挂载后带轮询器（attach 建一次）', $pollApp->io() instanceof IoPoller);

// Windows 没有 AF_UNIX ⇒ 用 PF_INET(2) / SOCK_STREAM(1) / IPPROTO_IP(0)
[$ia, $ib] = stream_socket_pair(2, 1, 0);
stream_set_blocking($ia, false); // ★ 必须：poll 只保证"问一下就走"

$hits = 0;
$got = '';
$token = $pollApp->io()?->watch($ia, true, function ($stream) use (&$hits, &$got): void {
    $hits++;
    $chunk = fread($stream, 16);
    $got .= $chunk === false ? '' : $chunk;
});
check('P2 · watch 返回非零 token 且监听数 +1', $token !== 0 && $pollApp->io()?->watchCount() === 1);

$t0 = microtime(true);
$pollApp->frame(16.0, BoxConstraints::loose(new Size(320.0, 240.0)));
$pollMs = (microtime(true) - $t0) * 1000.0;
check('P2 · 无数据 ⇒ 不回调', $hits === 0, (string) $hits);
// ★ 阈值宽松（50ms）是有意的：误写成阻塞版（超时 null）会**直接挂住整轮**，
//   不会"慢一点"——那样 smoke 是超时失败，比断言失败更响。
check('P2 · poll 不阻塞（无数据的一帧仍很快返回）', $pollMs < 50.0, sprintf('%.1fms', $pollMs));

fwrite($ib, 'hi');
$pollApp->frame(32.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P2 · 写入后 ⇒ 回调恰好一次', $hits === 1, (string) $hits);
check('P2 · 回调里读得到数据（非阻塞 fread）', $got === 'hi', $got);

// ③ unwatch：取消后不再回调（数据还在流里也不调）
$pollApp->io()?->unwatch((int) $token);
check('P2 · unwatch 之后监听数归零', $pollApp->io()?->watchCount() === 0, (string) $pollApp->io()?->watchCount());
fwrite($ib, 'x');
$pollApp->frame(48.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P2 · unwatch 后不再回调（即使流里有数据）', $hits === 1, (string) $hits);

// ④ + ⑤ 多监听：只有就绪的那个被回调；就绪回调里改 Store ⇒ 同帧重建
[$ic, $idd] = stream_socket_pair(2, 1, 0);
stream_set_blocking($ic, false);
stream_set_blocking($ia, false);
fread($ia, 16); // 读掉上一步残留的 'x'，避免它干扰"只有就绪的那个"

$order = [];
$pollApp->io()?->watch($ia, true, function () use (&$order): void { $order[] = 'a'; });
$pollApp->io()?->watch($ic, true, function () use (&$order, $pollApp): void {
    $order[] = 'c';
    $pollApp->store->setStatus(42);
});

$pollState = $pollApp->watcherState();
$buildsBeforePoll = $pollState?->builds ?? -1;

fwrite($idd, 'y'); // 只有 $ic 就绪
$pollApp->frame(64.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P2 · 多监听：只有就绪的那个被回调', $order === ['c'], implode(',', $order));
check(
    'P2 · IO 回调改 Store ⇒ **同帧**重建（poll 在 settle 之前）',
    $pollState !== null && $pollState->builds === $buildsBeforePoll + 1 && $pollState->seen === 42,
    sprintf('builds %d→%d seen=%d', $buildsBeforePoll, $pollState?->builds ?? -1, $pollState?->seen ?? -1),
);

fclose($ia);
fclose($ib);
fclose($ic);
fclose($idd);

// ── ㊵ P3：`ProcessTaskHost` 常驻子进程（§17.4.5）────────────────────────
//
// ★ **真进程、真管道、真 JSON Lines**：worker 用 php.exe 起（生产形态是同一份 AOT 产物的
//   `--drift-worker` 分支，协议与 `ProcessWorker` 完全一致 ⇒ 测的是生产路径）。
// ★ 为什么不是"每任务一进程"：spawn 实测 **275ms/次**（probes §12 ⑧）⇒ 懒启动 + 常驻。
// ★ 为什么不是线程：worker 线程跑不了 AOT 代码（探针 K/L，§17.2.5）。
// 判据：① 懒启动 ② 真进程算对 ③ 串行（在途 ≤ 1）④ 任务失败 ⇒ onError 且 worker 活着
//   ⑤ 崩溃 ⇒ onError 且 worker 停 ⑥ 崩溃后自动重启 ⑦ shutdown 收工

$workerScript = __DIR__ . '/Smoke/App/worker.php';
$procHost = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make());

// ★ `$until` 必须**收参数**（不能靠闭包捕获 `$sink`）：`static fn() => $sink !== []`
//   按值捕获的是**创建时**的空数组 ⇒ 永远 false ⇒ 每次 pump 等满 deadline
//   （本段第一版就是这样，smoke 从 10s 涨到 78s）。
$pump = static function (ProcessTaskHost $host, array &$sink, callable $until) use ($settle): void {
    $deadline = microtime(true) + 10.0;
    while (!$until($sink) && microtime(true) < $deadline) {
        usleep(5000);
        // ★ 走**生产路径**（`drain` 结算 Future，回调由 `then()` / `catch()` 挂），
        //   不直接读 `Completion->value` —— 否则测的是"队列里有值"，测不出
        //   "回调真的被调了"（本段第一版就栽在这：断言期望 `a1` 却拿到 `1`
        //   —— `then()` 根本没被调）。
        // ★ `$settle` 必须显式 `use`：闭包是 `static`，没有自动捕获。
        $host->drain($settle);
    }
};

// ① 懒启动 + 真进程算对
$sink = [];
$procHost->submit(new NamedSumJob(10))->then(function ($v) use (&$sink): void {
    $sink[] = $v;
});
check('P3 · submit 之后未回调（任务在 worker 里跑）', $sink === [], json_encode($sink));
check('P3 · 懒启动：第一次 submit 才起 worker', $procHost->isRunning());
check('P3 · 在途 1 条', $procHost->inFlightCount() === 1, (string) $procHost->inFlightCount());

$pump($procHost, $sink, static fn(array $s): bool => $s !== []);
check('P3 · 真进程算对了（跨进程跑 AOT 类：1+…+10 = 55）', $sink === [55], json_encode($sink));

// ② 串行：第二条排队（在途恒 ≤ 1）
$sink = [];
$procHost->submit(new NamedSumJob(1))->then(function ($v) use (&$sink): void { $sink[] = 'a' . $v; });
$procHost->submit(new NamedSumJob(3))->then(function ($v) use (&$sink): void { $sink[] = 'b' . $v; });
check(
    'P3 · 串行：第二条在排队（在途仍 1、队列 1）',
    $procHost->inFlightCount() === 1 && $procHost->queuedCount() === 1,
    'inflight=' . $procHost->inFlightCount() . ' queued=' . $procHost->queuedCount(),
);
$pump($procHost, $sink, static fn(array $s): bool => count($s) >= 2);
check('P3 · 两条都跑完且按提交序（a1, b6）', $sink === ['a1', 'b6'], json_encode($sink));

// ②b 并发：池里 3 个 worker **各接一条**（`maxWorkers` 是 opt-in；默认 1 就是上面那条串行）
//   ★ 判据是**结构**不是墙钟：任务睡 200ms 保证「提交第二条时第一条还在跑」，
//     于是「3 条同时在途 + 3 个 worker + 0 排队」只有在真的并行时才成立。
//     不拿墙钟当断言 —— 机器一忙就假红（量具纪律：别让判据依赖负载）。
$poolSink = [];
$pool = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make(), 3);
foreach ([200, 200, 200] as $ms) {
    $pool->submit(new NamedSleepJob($ms))->then(function ($v) use (&$poolSink): void { $poolSink[] = $v; });
}
check(
    'P3 · 池：3 条同时在途 + 3 个 worker + 0 排队（真并行）',
    $pool->inFlightCount() === 3 && $pool->workerCount() === 3 && $pool->queuedCount() === 0,
    'inflight=' . $pool->inFlightCount() . ' workers=' . $pool->workerCount() . ' queued=' . $pool->queuedCount(),
);
$pump($pool, $poolSink, static fn (array $s): bool => count($s) >= 3);
check(
    'P3 · 池：3 条都回来了（各睡 200ms，并行跑完）',
    count($poolSink) === 3 && $poolSink === [200, 200, 200],
    json_encode($poolSink),
);
$pool->shutdown();
check(
    'P3 · 池：shutdown 收掉所有 worker',
    $pool->workerCount() === 0 && $pool->isRunning() === false,
    'workers=' . $pool->workerCount(),
);

// ②c 池上限：**满载排队**，不无界起进程（一个 worker = 一整套 AOT 运行时）
$cappedSink = [];
$capped = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make(), 2);
foreach ([300, 300, 300] as $ms) {
    $capped->submit(new NamedSleepJob($ms))->then(function ($v) use (&$cappedSink): void { $cappedSink[] = $v; });
}
check(
    'P3 · 池上限：第 3 条排队（2 在途 + 1 排队 + 2 个 worker）',
    $capped->inFlightCount() === 2 && $capped->queuedCount() === 1 && $capped->workerCount() === 2,
    'inflight=' . $capped->inFlightCount() . ' queued=' . $capped->queuedCount() . ' workers=' . $capped->workerCount(),
);
$pump($capped, $cappedSink, static fn (array $s): bool => count($s) >= 3);
check('P3 · 池上限：排队那条最后也跑完了', count($cappedSink) === 3, json_encode($cappedSink));
$capped->shutdown();

// ②c-2 取消通道：**排队中**的能真停，**在途**的只能"不交付"
//
// ★ 两条语义必须分开判，否则测出来的是一团：
//   - 排队中（还没发给 worker）⇒ 摘出队列 ⇒ `queuedCount()` 少 1，worker 根本不会跑它；
//   - 在途（已经发给 worker）⇒ **停不了**：杀进程要重拉（一整套 AOT 运行时），
//     还会丢在途状态 ⇒ `inFlightCount()` 不变，靠 `Future` 忽略迟到结果。
// ★ 判据选"那条任务**从未执行**"（sink 空），不选"pending 变 0" —— 后者只证明记账变了，
//   证明不了 worker 没跑它。
$cancelPool = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make(), 1);
$blockerSink = [];
$cancelPool->submit(new NamedSleepJob(200))->then(function ($v) use (&$blockerSink): void { $blockerSink[] = $v; });

$droppedSink = [];
$dropped = $cancelPool->submit(new NamedSleepJob(50));
$dropped->then(function ($v) use (&$droppedSink): void { $droppedSink[] = $v; });
check(
    'N3 · 取消前：1 在途 + 1 排队（worker 只有一个）',
    $cancelPool->inFlightCount() === 1 && $cancelPool->queuedCount() === 1,
    'inflight=' . $cancelPool->inFlightCount() . ' queued=' . $cancelPool->queuedCount(),
);

$dropped->cancel();
check(
    'N3 · 排队中取消 ⇒ 真的摘出队列（worker 不会跑它）',
    $cancelPool->queuedCount() === 0 && $cancelPool->pending() === 1,
    'queued=' . $cancelPool->queuedCount() . ' pending=' . $cancelPool->pending(),
);

$pump($cancelPool, $blockerSink, static fn (array $s): bool => $s !== []);
// 再转几轮：它要是没被摘掉，就会在 blocker 跑完之后冒出来（50ms 足够）
$dropDeadline = microtime(true) + 0.3;
while (microtime(true) < $dropDeadline) {
    usleep(5000);
    $cancelPool->drain($settle);
}
check(
    'N3 · 被取消的排队任务从未执行（sink 空 + 永不结算）',
    $droppedSink === [] && $dropped->isDone() === false,
    json_encode($droppedSink) . ' done=' . var_export($dropped->isDone(), true),
);
$cancelPool->shutdown();

// ②c-2b 派生链上的取消：`submit()->then()` 的产物也必须能摘掉**排队项**
//
// ★ 这是"取消只做对了一半"的真机判据：`all`/`race` 上一轮就传播了，而 `then()`
//   —— 最常用的那种派生 —— 之前是断的。断了的表现是**静默**：排队项照发、worker
//   白跑一趟、结果回来没人要（界面看不出任何异常，只是白烧一次 1.2s 的活）。
// ★ 判据选"排队项真的没了"（`queuedCount()`），不选"派生那条 isCancelled()"：
//   后者在断掉的实现里**也为真**（自己标一下而已），测不出任何东西。
$derivedPool = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make(), 1);
$derivedPool->submit(new NamedSleepJob(120))->then(static function ($v) { return $v; });
$derivedSink = [];
$derived = $derivedPool->submit(new NamedSleepJob(50))
    ->then(static function ($v) use (&$derivedSink) {
        $derivedSink[] = $v;

        return $v;
    });
check(
    'N7 · 派生链取消前：1 在途 + 1 排队',
    $derivedPool->inFlightCount() === 1 && $derivedPool->queuedCount() === 1,
    'inflight=' . $derivedPool->inFlightCount() . ' queued=' . $derivedPool->queuedCount(),
);

$derived->cancel();
check(
    'N7 · 取消派生 Future ⇒ 排队项真的被摘掉（取消沿链回到根）',
    $derivedPool->queuedCount() === 0 && $derivedSink === [],
    'queued=' . $derivedPool->queuedCount() . ' sink=' . json_encode($derivedSink),
);
$derivedPool->shutdown();

// ②c-3 在途取消：**停不了，但结果不交付**（`pending()` 记账不变，避免帧时钟落闸后
//   管道里的回包永远读不到 ⇒ worker 写满管道阻塞成僵尸）
$inflightPool = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make(), 1);
$victimSink = [];
$victim = $inflightPool->submit(new NamedSleepJob(150));
$victim->then(function ($v) use (&$victimSink): void { $victimSink[] = $v; });
$victim->cancel();
check(
    'N3 · 在途取消：记账不变（仍算 pending，否则回包读不到）',
    $inflightPool->inFlightCount() === 1 && $inflightPool->pending() === 1,
    'inflight=' . $inflightPool->inFlightCount() . ' pending=' . $inflightPool->pending(),
);
$inflightPoolDeadline = microtime(true) + 3.0;
while (microtime(true) < $inflightPoolDeadline && $inflightPool->pending() > 0) {
    usleep(5000);
    $inflightPool->drain($settle);
}
check(
    'N3 · 在途取消：结果不交付（then 一次都不跑），但队列真的清空了',
    $victimSink === [] && $inflightPool->pending() === 0,
    json_encode($victimSink) . ' pending=' . $inflightPool->pending(),
);
$inflightPool->shutdown();

// ②d `Future::all` 跑在**真并发后端**上 —— N3「多接口并发」的落地判据
//
// ★★ 为什么不是 `curl_multi`（册子里的 A1）：本仓 php.exe 的 `curl` 扩展**残缺**
//   —— `curl_global_init()` 都不存在，且 `curl_multi_init()` **一句**就会让进程在
//   模块收尾段 `exit(139)`（不是异常，`try/catch` 接不住）。AOT 产物里它是好的
//   （探针实测 5×600ms → 622.5ms、退出码 0），但**进不了闸门的东西不进 `src/`**
//   ⇒ A1 判为不可行，并发落在已有的进程池上。详见 `PITFALLS.md`。
// ★ 判据选「顺序」而不是「墙钟」：入参序 200,100,300 与**完成序** 100,200,300
//   不同 ⇒ 顺序断言能证伪"按完成序拼结果"；墙钟会被机器负载打红（量具纪律）。
$allSink = [];
$allPool = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make(), 3);
$allFuture = Future::all([
    $allPool->submit(new NamedSleepJob(200)),
    $allPool->submit(new NamedSleepJob(100)),
    $allPool->submit(new NamedSleepJob(300)),
]);
$allThenCount = 0;
$allFuture->then(function (array $values) use (&$allSink, &$allThenCount): void {
    $allThenCount = $allThenCount + 1;
    $allSink = $values;
});
check(
    'N3 · Future::all 在 3 worker 池上：3 条同时在途（真并发，不是排队）',
    $allPool->inFlightCount() === 3 && $allPool->workerCount() === 3 && $allPool->queuedCount() === 0,
    'inflight=' . $allPool->inFlightCount() . ' workers=' . $allPool->workerCount() . ' queued=' . $allPool->queuedCount(),
);
$pump($allPool, $allSink, static fn (array $s): bool => $s !== []);
check(
    'N3 · Future::all：结果按**入参**顺序（200,100,300 ≠ 完成序 100,200,300）',
    $allSink === [200, 100, 300],
    json_encode($allSink),
);
check('N3 · Future::all：then() 只跑一次（不是每条各跑一次）', $allThenCount === 1, (string) $allThenCount);
$allPool->shutdown();

// ②e `all` 里一条失败 ⇒ 整条失败（兄弟请求被 cancel，错误原文带出）
$failThen = 0;
$failErr = null;
$failPool = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make(), 3);
Future::all([
    $failPool->submit(new NamedSleepJob(400)),
    $failPool->submit(new NamedBoomJob('接口 B 挂了')),
])->then(function (array $values) use (&$failThen): void {
    $failThen = $failThen + 1;
})->catch(function (\Throwable $e) use (&$failErr): void {
    $failErr = $e->getMessage();
});

$failDeadline = microtime(true) + 10.0;
while ($failErr === null && microtime(true) < $failDeadline) {
    usleep(5000);
    $failPool->drain($settle);
}

check(
    'N3 · Future::all：一条失败 ⇒ 整条失败（错误原文带出）',
    is_string($failErr) && str_contains($failErr, '接口 B 挂了'),
    is_string($failErr) ? $failErr : 'null',
);
check('N3 · Future::all：失败时 then() 一次都不跑（两条通道互斥）', $failThen === 0, (string) $failThen);
$failPool->shutdown();

// ②f `Future::allSettled` 在真池上：一条失败**不连坐**，其余照常交付
//
// ★ 与 ②e 是**同一条场景的相反判据**（`all` vs `allSettled`）。两个并排放，
//   读者一眼能看出"选错聚合器"的代价：`all` 会把兄弟任务 cancel 掉 ⇒ 拼看板的
//   页面只剩一块错误页，而症状看起来像一次普通失败，没人会去查。
// ★ 判据不用墙钟：看的是"三份结果都回来了 + 失败那条带原因 + 成功的带值"。
$settledSink = null;
$settledPool = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make(), 3);
Future::allSettled([
    'a' => $settledPool->submit(new NamedSleepJob(120)),
    'b' => $settledPool->submit(new NamedBoomJob('接口 B 挂了')),
    'c' => $settledPool->submit(new NamedSleepJob(60)),
])->then(function (array $values) use (&$settledSink): void {
    $settledSink = $values;
});

$settledDeadline = microtime(true) + 10.0;
while ($settledSink === null && microtime(true) < $settledDeadline) {
    usleep(5000);
    $settledPool->drain($settle);
}

check(
    'N3 · allSettled：一条失败 ⇒ 聚合体仍交付三份结果（不是 reject）',
    is_array($settledSink) && array_keys($settledSink) === ['a', 'b', 'c'],
    json_encode($settledSink, JSON_UNESCAPED_UNICODE) ?: 'null',
);
check(
    'N3 · allSettled：失败那条 ok=false 带原因，另外两条照常带值（兄弟没被取消）',
    ($settledSink['b']['ok'] ?? null) === false
        && str_contains((string) ($settledSink['b']['error'] ?? ''), '接口 B 挂了')
        && ($settledSink['a']['ok'] ?? null) === true
        && ($settledSink['c']['ok'] ?? null) === true
        && ($settledSink['a']['value'] ?? null) === 120
        && ($settledSink['c']['value'] ?? null) === 60,
    json_encode($settledSink, JSON_UNESCAPED_UNICODE) ?: 'null',
);
$settledPool->shutdown();

// ③ 任务失败（worker 里抛）⇒ onError，且**不打死 worker**
$sink = [];
$procHost->submit(new NamedBoomJob('设备离线'))
    ->then(function ($v): void {
    })
    ->catch(function (\Throwable $e) use (&$sink): void {
        $sink[] = $e->getMessage();
    });
$pump($procHost, $sink, static fn(array $s): bool => $s !== []);
check(
    'P3 · worker 里的异常 ⇒ catch()（原文带出）',
    str_contains($sink[0] ?? '', '设备离线'),
    $sink[0] ?? 'null',
);
check('P3 · 一个任务失败不打死 worker', $procHost->isRunning());

// ④ 崩溃隔离：worker 真的死（`exit 9`，catch 拦不住）⇒ onError + worker 停
$sink = [];
$procHost->submit(new NamedExitJob())
    ->then(function ($v): void {
    })
    ->catch(function (\Throwable $e) use (&$sink): void {
        $sink[] = $e->getMessage();
    });
$pump($procHost, $sink, static fn(array $s): bool => $s !== []);
check(
    'P3 · worker 崩溃 ⇒ 在途任务 catch()（不是静默挂住）',
    str_contains($sink[0] ?? '', '后台进程退出'),
    $sink[0] ?? 'null',
);
check('P3 · 崩溃后 worker 已停', !$procHost->isRunning());

// ⑤ 崩溃后自动重启（同一实例继续用）
$sink = [];
$procHost->submit(new NamedSumJob(4))->then(function ($v) use (&$sink): void { $sink[] = $v; });
$pump($procHost, $sink, static fn(array $s): bool => $s !== []);
check('P3 · 崩溃后自动重启并跑完新任务（1+…+4 = 10）', $sink === [10], json_encode($sink));

// ⑥ 收工
$procHost->shutdown();
check('P3 · shutdown 之后不再有 worker', !$procHost->isRunning());

// ── ㊶ apps/users 的**真异步取数**（真 HTTP + 真子进程，§17.4.5 的落地）────────
//
// ★ 这条链就是 M4 `HttpUserSource` 的预演：`UserStore::load()` → `ProcessTaskHost`
//   → 常驻 worker → **真 HTTP**（`php -S` 的 mock，故意慢 1.2s）→ JSON 回程 → hydrate。
// ★ 它同时兑现了 §11.5 那条结论："`loading` 只在同步 IO 下不可见" —— 有了后台，
//   三态第一次真的对用户有意义。
// 判据：① attach 之后仍 Loading（没被 HTTP 阻塞）② 若干帧后 Ready + 15 行
//   ③ 数据真的来自 HTTP（首行姓名）④ 同步路径（host=null）行为不变（既有 18 条已覆盖）

// ★★ 端口不能"算一个就用"：原写法 `18000 + (pid % 1000)` 会撞上**别的进程**已占用的
//   端口，症状是 `php -S` 报 WSAEACCES 而 `/healthz` 探到**别人**的 404 ⇒ 判据假红。
//   2026-10-30 真撞过一次（18488 被宿主进程占着，gate 停在 smoke）。
//   ⇒ 先自己 bind 一次探空闲。`stream_socket_server` 成功即说明能绑（有极小的
//   TOCTOU 窗口，但比"撞上了就红"好得多）。
$mockPort = 0;
$mockBase = 18000 + (getmypid() % 1000);
for ($try = 0; $try < 30; $try++) {
    $candidate = $mockBase + $try * 7;
    $probeSock = @stream_socket_server('tcp://127.0.0.1:' . $candidate, $probeErrno, $probeErrstr);
    if ($probeSock !== false) {
        fclose($probeSock);   // 只是探一下，真监听交给 `php -S`
        $mockPort = $candidate;

        break;
    }
}

$mockProc = $mockPort > 0
    ? proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, dirname(__DIR__) . '/tools/app-workers/users-mock.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $mockPipes,
    )
    : false;
$mockReady = false;
for ($i = 0; $i < 120 && $mockProc !== false; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $mockPort . '/healthz');
    if ($probe !== false) {
        $mockReady = true;

        break;
    }
    usleep(50000);
}
check(
    '异步 · mock 后端起来了（php -S）',
    $mockReady,
    $mockPort > 0 ? 'port=' . $mockPort : '18000 起 30 个候选端口全被占用（不是代码问题，换台机器或关掉占端口的程序）',
);

if ($mockReady) {
    $asyncHost = new ProcessTaskHost(
        [PHP_BINARY, dirname(__DIR__) . '/tools/app-workers/users-worker.php'],
        JobRegistry::make(),
    );
    $asyncApp = new UsersApplication(new MemoryUserSource(), $asyncHost, 'http://127.0.0.1:' . $mockPort);
    $asyncApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(900.0, 640.0)));

    check(
        '异步 · attach 之后仍是 Loading（取数没占 UI 线程）',
        $asyncApp->userStore()->page()->value()->isLoading(),
        $asyncApp->userStore()->page()->value()->stateName(),
    );

    $asyncT0 = microtime(true);
    $asyncFrames = 0;
    while ($asyncApp->userStore()->page()->value()->isLoading() && microtime(true) - $asyncT0 < 10.0) {
        usleep(16000);
        $asyncApp->frame((microtime(true) - $asyncT0) * 1000.0, BoxConstraints::loose(new Size(900.0, 640.0)));
        $asyncFrames++;
    }

    $asyncStore = $asyncApp->userStore();
    check(
        '异步 · 后台取数完成 ⇒ Ready 且 15 行',
        $asyncStore->page()->value()->hasData() && count($asyncStore->rows()) === 15,
        $asyncStore->page()->value()->stateName() . ' rows=' . count($asyncStore->rows()) . ' frames=' . $asyncFrames,
    );
    check(
        '异步 · 数据真的来自 HTTP（首行「张伟」经 worker 往返）',
        ($asyncStore->rows()[0]->name ?? '') === '张伟',
        $asyncStore->rows()[0]->name ?? '(空)',
    );
    check(
        '异步 · 耗时说明它真的等了网络（≥ 1.2s）',
        (microtime(true) - $asyncT0) >= 1.2,
        sprintf('%.2fs', microtime(true) - $asyncT0),
    );

    $asyncHost->shutdown();

    // ── ㊶c `Drift\App\Io\Http` 的真往返（同一条 mock 后端，不另起服务器）────────
    //
    // ★ 为什么必须有这一段：`Http` 是**唯一**一条 HTTP 路径（2026-10-31 把 `apps/users`
    //   与 `apps/showcase` 各自那 30 行 curl 收成一套）。它里面全是 curl 的选项映射
    //   —— 映射错了**不报错**，只是请求发出去的东西不对（body 没带、动词变成 GET、
    //   头没设）。单测 (`tests/Unit/App/HttpTest.php`) 只能判参数面与失败面，
    //   "字节真的发出去了吗"只能靠一条真 socket。
    // ★ 判据：① 200 + JSON + 耗时 > 0 ② POST 的动词/body/Content-Type 都到了对端
    //   ③ 非 2xx 默认抛且带状态码 ④ `expectOk => false` 时把 404 当数据
    //   ⑤ 跟随重定向之后 `url()` 是**最后一跳**。

    $httpBase = 'http://127.0.0.1:' . $mockPort;

    $health = Http::get($httpBase . '/healthz');
    check(
        'Http · GET 200：状态码 / ok / JSON / 耗时都为真',
        $health->status() === 200 && $health->ok() && ($health->json()['ok'] ?? null) === true && $health->elapsedMs() > 0.0,
        'status=' . $health->status() . ' ms=' . sprintf('%.2f', $health->elapsedMs()),
    );
    check(
        'Http · 响应头按名字取得着（大小写不敏感）',
        is_string($health->header('Content-Type')) && str_contains((string) $health->header('Content-Type'), 'application/json'),
        var_export($health->header('Content-Type'), true),
    );

    $echo = Http::post(
        $httpBase . '/echo',
        '{"hello":"世界"}',
        ['headers' => ['Content-Type' => 'application/json']],
    )->json();
    check(
        'Http · POST：动词真的变成了 POST（不是被 curl 悄悄改成 GET）',
        ($echo['method'] ?? '') === 'POST',
        (string) ($echo['method'] ?? '(空)'),
    );
    check(
        'Http · POST：body 原样到达对端（中文未损坏）',
        ($echo['body'] ?? '') === '{"hello":"世界"}',
        (string) ($echo['body'] ?? '(空)'),
    );
    check(
        'Http · POST：Content-Type 真的发出去了',
        ($echo['type'] ?? '') === 'application/json',
        (string) ($echo['type'] ?? '(空)'),
    );

    $bad = null;
    try {
        Http::get($httpBase . '/no-such-page');
    } catch (HttpException $e) {
        $bad = $e;
    }
    check('Http · 非 2xx 默认抛 HttpException', $bad instanceof HttpException, $bad === null ? '没抛' : '抛了');
    check('Http · 异常带状态码 404（与传输层失败分得开）', $bad?->status() === 404, var_export($bad?->status(), true));

    $notFound = Http::get($httpBase . '/no-such-page', ['expectOk' => false]);
    check(
        'Http · expectOk=false ⇒ 404 当数据返回（自己看 ok()）',
        $notFound->status() === 404 && !$notFound->ok() && str_contains($notFound->body(), 'not found'),
        'status=' . $notFound->status(),
    );

    $redirected = Http::get($httpBase . '/redirect', ['followRedirects' => 2]);
    check(
        'Http · 跟随重定向之后 status=200 且 url() 是最后一跳',
        $redirected->status() === 200 && str_ends_with($redirected->url(), '/healthz'),
        'status=' . $redirected->status() . ' url=' . $redirected->url(),
    );

    // ── ㊶d multipart 的真往返（2026-11-02，第一个消费者 `apps/watermark`）────────
    //
    // ★ 为什么单测不够：单测直测 `buildMultipart()` 的字节（结构对不对），但那**证明
    //   不了**这些字节被 curl 原样发出去、也没被 `Content-Type` 覆盖掉。中间隔着一层
    //   libcurl，"我们拼对了"与"服务端收到了"是两件事。
    // ★ 判据：① 服务端按 multipart 解析出了字段（含中文键值）② 文件的**字节**一致
    //   （md5）③ 客户端给的 boundary 与解析用的一致 ④ 总长度对得上。
    // ★ 故意带 `.jpg` 扩展名：`buildMultipart()` 的 MIME 是**按扩展名查表**得到的
    //   （不用 `mime_content_type()`，那是 fileinfo 扩展，AOT 运行时没启）。
    //   扩展名不认识时退回 `application/octet-stream` —— 那条路在单测里判。
    $mpFile = tempnam(sys_get_temp_dir(), 'drift-mp-') . '.jpg';
    $mpBytes = random_bytes(200 * 1024);
    file_put_contents($mpFile, $mpBytes);

    // ★ 上传进度（2026-11-02）：`onProgress` 的**唯一**判据在这里。
    //   单测直测 `MultipartBody::bytesSent()`，但"Http 真的把回调调起来"那一跳
    //   只有真往返能证明 —— 变异「进度回调从不被调用」在单测里是绿的。
    $progressSeen = [];

    $mp = Http::request('POST', $httpBase . '/multipart', [
        'fields' => [
            'key' => '***REMOVED***/2026/11/2/abc/1234.jpg',
            'policy' => 'eyJleHBpcmF0aW9uIjoiMjAyNi0xMS0wMlQwMzowMToxNC4wMDhaIn0=',
            'OSSAccessKeyId' => 'LTAI5t9NGBH5zAdeTBhxcQvR',
            'success_action_status' => '200',
            '中文键' => '中文值',
        ],
        'files' => ['file' => $mpFile],
        // ★ 带一个中文头：`shuiyin.html` 用 XHR 而不用 fetch 的唯一原因就是这个
        //   （fetch 的 Header 不允许非 ISO-8859-1）。本仓走 curl，没有那条限制。
        'headers' => ['uc-userName' => '11'],
        'onProgress' => static function (float $fraction) use (&$progressSeen): void {
            $progressSeen[] = $fraction;
        },
    ])->json();

    check(
        'Http · multipart：服务端解析出了 5 个字段 + 1 个文件',
        ($mp['partCount'] ?? 0) === 6,
        'partCount=' . ($mp['partCount'] ?? '(空)'),
    );
    check(
        'Http · multipart：中文键值原样到达（UTF-8 未损坏）',
        ($mp['fields']['中文键'] ?? '') === '中文值',
        var_export($mp['fields']['中文键'] ?? null, true),
    );
    check(
        'Http · multipart：OSS 签名那几个字段名与值都对（直接照抄 shuiyin.html 的形状）',
        ($mp['fields']['key'] ?? '') === '***REMOVED***/2026/11/2/abc/1234.jpg'
            && ($mp['fields']['OSSAccessKeyId'] ?? '') === 'LTAI5t9NGBH5zAdeTBhxcQvR'
            && ($mp['fields']['success_action_status'] ?? '') === '200',
        'key=' . ($mp['fields']['key'] ?? '(空)'),
    );
    check(
        'Http · multipart：文件字节**逐字节**一致（md5，200KB 二进制）',
        ($mp['files']['file']['md5'] ?? '') === md5($mpBytes),
        ($mp['files']['file']['md5'] ?? '(空)') . ' vs ' . md5($mpBytes),
    );
    check(
        'Http · multipart：文件大小对 + MIME 按扩展名映射（.jpg ⇒ image/jpeg，不靠 fileinfo）',
        (int) ($mp['files']['file']['size'] ?? -1) === strlen($mpBytes)
            && ($mp['files']['file']['mime'] ?? '') === 'image/jpeg',
        'size=' . ($mp['files']['file']['size'] ?? '(空)') . ' mime=' . ($mp['files']['file']['mime'] ?? '(空)'),
    );
    check(
        'Http · multipart：客户端给的 boundary 就是服务端解析用的那个',
        ($mp['boundary'] ?? '') !== '' && str_contains((string) ($mp['type'] ?? ''), (string) $mp['boundary']),
        'boundary=' . ($mp['boundary'] ?? '(空)'),
    );
    check(
        'Http · multipart：总长度 = 文件字节 + 表单头尾（说明没有多发也没有少发 part）',
        (int) ($mp['length'] ?? 0) > strlen($mpBytes) && (int) ($mp['length'] ?? 0) < strlen($mpBytes) + 4096,
        'length=' . ($mp['length'] ?? 0) . ' 文件=' . strlen($mpBytes),
    );

    // ── 上传进度：回调**真的被调起来了** ────────────────────────────────
    // ★ 这一条只能在这里判：单测能证明 `bytesSent()` 单调到 1.0，但证明不了
    //   `Http` 那个 `CURLOPT_READFUNCTION` 闭包里有调 `$onProgress` 那两行
    //   （变异「回调从不被调用」在单测里全绿）。真往返才看得见。
    check(
        'Http · 进度：真往返里回调至少被调了 1 次（0 次 ⇒ 进度条永远停在 0%）',
        count($progressSeen) >= 1,
        '次数=' . count($progressSeen),
    );
    check(
        'Http · 进度：回调值全在 0..1（越界 ⇒ 进度条画出格）',
        $progressSeen !== [] && min($progressSeen) >= 0.0 && max($progressSeen) <= 1.0,
        $progressSeen === [] ? '(没有回调)' : sprintf('min=%.4f max=%.4f', min($progressSeen), max($progressSeen)),
    );
    check(
        'Http · 进度：回调单调不减（倒退 ⇒ 进度条往回跳）',
        (static function (array $xs): bool {
            $prev = -1.0;
            foreach ($xs as $x) {
                if ($x < $prev) {
                    return false;
                }
                $prev = $x;
            }

            return true;
        })($progressSeen),
        'count=' . count($progressSeen),
    );
    check(
        'Http · 进度：最后一次是 1.0（停在 0.99 ⇒ 用户以为没传完）',
        $progressSeen !== [] && abs((float) $progressSeen[count($progressSeen) - 1] - 1.0) < 1e-9,
        $progressSeen === [] ? '(没有回调)' : sprintf('%.6f', $progressSeen[count($progressSeen) - 1]),
    );

    @unlink($mpFile);
}

if (is_resource($mockProc)) {
    proc_terminate($mockProc);
    foreach ($mockPipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($mockProc);
}

// ── ㊷ 陈旧响应：连发两次取数，**先发的慢、后发的快** ──────────────────────────
//
// ★ 真实触发路径：刷新按钮连点两次 / 翻页太快 / 注册成功后重载。两个请求同时在途时
//   "第 1 页有缓存、第 2 页要现算"是常态 ⇒ 迟到的旧结果盖掉新数据，而状态是
//   `Ready`，**用户看不出任何异常**。成熟框架都在这一层收口（RxJS `switchMap` /
//   `useEffect` cleanup / `CancellationTokenSource`），本仓的收口是 `Future::cancel()`。
// ★ 用**手动结算的 TaskHost** 而不是真 HTTP：判据要的是"**顺序**"（旧的后到），
//   不是"耗时" —— 拿真并发测这一条只能靠 sleep 排序，机器一忙就假红（量具纪律）。
$manualHost = new class implements TaskHost {
    /** @var list<Future> */
    public array $futures = [];

    public function submit(Job $job): Future
    {
        $future = Future::pending();
        $this->futures[] = $future;

        return $future;
    }

    public function pending(): int
    {
        return \count($this->futures);
    }

    /** 手动结算的后端：没有第二线程，只能靠 `drain()` 拉 */
    public function needsPolling(): bool
    {
        return true;
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
    }
};

$staleStore = new UserStore(new MemoryUserSource(), $manualHost);
$staleStore->load(1);
$staleStore->load(2); // ⇒ 第 1 条当场被取消
check(
    'N3 · 连发两次取数：两个请求都在途，旧的已被取消',
    \count($manualHost->futures) === 2 && $manualHost->futures[0]->isCancelled() === true,
    'futures=' . \count($manualHost->futures)
        . ' oldCancelled=' . var_export($manualHost->futures[0]->isCancelled(), true),
);

// 新的先回来，旧的迟到（这就是真实世界里的顺序）
$manualHost->futures[1]->resolve([['id' => 2, 'name' => '第二页', 'email' => 'b@example.com']]);
$manualHost->futures[0]->resolve([['id' => 1, 'name' => '第一页', 'email' => 'a@example.com']]);

check(
    'N3 · 迟到的旧响应不覆盖新数据（陈旧响应胜出正是这条要杀的 bug）',
    \count($staleStore->rows()) === 1 && $staleStore->rows()[0]->name === '第二页',
    json_encode(array_map(static fn (\App\Users\Store\User $u): string => $u->name, $staleStore->rows())),
);
check(
    'N3 · 旧响应到达后三态仍是 Ready（没有被搅成 Loading/Error）',
    $staleStore->page()->value()->hasData(),
    $staleStore->page()->value()->stateName(),
);

// ── ㊸ 刷新时保留上一次的行（2026-10-31，`AsyncValue` 两维模型）────────────────
//
// ★ 判据落在**两件事同时成立**上：`isLoading()`（请求在途）且行还在。
//   上一版这两件事不可能同时为真 —— `loading` 把载荷扔了 ⇒ 列表只能闪空。
//   ★ 也不能只测 `rows()`：`UserStore` 在失败路径上本来就保留行（那是另一条语义）。
//     这里必须证明"值真的跨状态活下来了"，所以读 `isRefreshing()` + `value()`。
$staleStore->load(3);
$refreshing = $staleStore->page()->value();
check(
    'N6 · 刷新中旧行还在（loading 带上一次的载荷 ⇒ 列表不闪空）',
    $refreshing->isRefreshing()
        && $refreshing->dataOr(null) !== null
        && \count($staleStore->rows()) === 1
        && ($staleStore->rows()[0]->name ?? '') === '第二页',
    'state=' . $refreshing->stateName()
        . ' refreshing=' . ($refreshing->isRefreshing() ? '1' : '0')
        . ' rows=' . \count($staleStore->rows()),
);

// ── ㊷ N5 · 「能不能真睡着」的唯一开关（`needsPolling()`）─────────────────────
//
// ★★ 判据的形状是"**同一夹具、只翻一个布尔**"：三台应用除了 `needsPolling()` 的
//   返回值（以及第三台多一个分片任务）之外完全一样 ⇒ 结论相反只可能来自那个布尔。
//   ★ 反例自检：把 `Application::wantsFrameRhythm()` 的 `hasPolledAsync()` 改回
//     `hasAsync()` ⇒ 第一条立刻红（`wait=-1` 变成 `wait=15`）。
//   ★ 为什么必须有这一条：N5 的全部收益就是"一条 600ms 的任务从 40 次空唤醒降到
//     0 次"。没有这条判据，"零轮询"只是注释里的说法。
//
// ★ 夹具**不造 Future**：判据只关心"有没有活在途"（`pending() > 0`）与
//   "能不能叫醒"，造一个没人接的 `Future` 只会把"失败没人接"的 Fatal 判据拖进来。

/** @internal 冒烟夹具：永远"有 1 条在途"，`needsPolling()` 可翻 */
$inFlightHost = static fn (bool $needsPolling): TaskHost => new class ($needsPolling) implements TaskHost {
    public function __construct(private readonly bool $needsPolling)
    {
    }

    public function submit(Job $job): Future
    {
        throw new \LogicException('夹具不跑任务 —— 判据只看 pending() 与 needsPolling()');
    }

    public function pending(): int
    {
        return 1;
    }

    public function needsPolling(): bool
    {
        return $this->needsPolling;
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
    }
};

$rhythmConstraints = BoxConstraints::loose(new Size(320.0, 240.0));

// ① 能叫醒的后端在途 ⇒ **无限等**（真睡着）
$wakeApp = new TaskApplication();
$wakeApp->scope()->setTaskHost($inFlightHost(false));
$wakeApp->attach(new SurfaceProbe(), $rhythmConstraints);
$wakeApp->frame(16.0, $rhythmConstraints);   // 先清掉"一次性欠账"（焦点环 / 面板落后）
$wakeWait = (new FrameClock())->nextWaitMs($wakeApp, 16.0);

check(
    'N5 · 能叫醒的后端在途 ⇒ 帧时钟 -1（一觉睡到 worker 叫醒，零空转）',
    $wakeWait === -1.0,
    'wait=' . $wakeWait,
);

// ② 同一形状，只把 needsPolling() 翻成 true ⇒ 回到帧节奏
$pollApp2 = new TaskApplication();
$pollApp2->scope()->setTaskHost($inFlightHost(true));
$pollApp2->attach(new SurfaceProbe(), $rhythmConstraints);
$pollApp2->frame(16.0, $rhythmConstraints);
$pollWait = (new FrameClock())->nextWaitMs($pollApp2, 16.0);

check(
    'N5 · 只能轮询的后端在途 ⇒ 帧时钟 = 帧间隔（同一夹具，只翻一个布尔）',
    $pollWait === (float) FrameClock::FRAME_MS,
    'wait=' . $pollWait . '（期望 ' . FrameClock::FRAME_MS . '）',
);

// ③ 有一路**只能轮询**的活（`IoPoller` watcher）⇒ 即使后端能叫醒，也回到帧节奏
//
// ★ 2026-10-31 换过承载物：这条原先用分片任务（`submitSliced`）验，分片删掉后
//   改用 `IoPoller` 的 watcher —— **守的不变量一模一样**："能推的后端也压不住
//   另一路拉模型的活"（`hasPolledAsync()` 是"任一路在轮询"的或，不是"后端轮询"
//   的或）。换成 watcher 反而更贴近真实用法：分片那一路本来就没有消费者。
$ioApp = new TaskApplication();
$ioApp->scope()->setTaskHost($inFlightHost(false));   // 后端仍然"能叫醒"
$ioApp->attach(new SurfaceProbe(), $rhythmConstraints);
$ioApp->frame(16.0, $rhythmConstraints);
[$ioRead] = stream_socket_pair(2, 1, 0);
stream_set_blocking($ioRead, false);
$ioApp->io()?->watch($ioRead, true, function (): void {
    // 判据只看"有没有一路在轮询"，回调体不参与
});
$ioWait = (new FrameClock())->nextWaitMs($ioApp, 16.0);

check(
    'N5 · 有轮询源在 ⇒ 仍按帧节奏（与后端能否叫醒无关）',
    $ioWait === (float) FrameClock::FRAME_MS,
    'wait=' . $ioWait . ' watches=' . $ioApp->io()?->watchCount(),
);

// ── 可滚动容器 / 滚动条绘制 / 滚轮全链 / 拖条 / UI 线程身份 已迁出 ─────────────
//   → `tests/Feature/Rendering/ScrollViewBoxTest.php`（16 条）
//     盒层：视口 = 约束上限 / maxScroll / ★偏移进子盒 offset 而非 paint translate /
//     命中按视口 / 越界夹取 / 父无界不滚 / 横向 / 6 条滚动条像素。
//   → `tests/Feature/App/ScrollWheelTest.php`（11 条）
//     应用层全链：格→像素→命中→scrollBy→帧末重建→盒偏移；上下界夹取；视口外不滚；
//     ★轴来自事件（双轴宿主）；装得下的轴不标脏；双轴不回落；单轴不回落横划。
//   → `tests/Feature/App/ScrollBarDragTest.php`（2 条）
//     thumb 下拖按 maxScroll/thumbTravel 放大；内容区拖动不是拖条。
//   → `tests/Feature/App/UiThreadTest.php`（4 条）
//     UI 线程身份（§17.4.2.1）：未挂载 = 0 / 挂载后 = 当前线程 / 非 UI 线程 ⇒ Fatal。

// ── 指针相位 / 悬停 / 拖放 / 手势消歧 已迁出 ────────────────────────────────
//   → `tests/Unit/Input/PointerPhaseTest.php`（3）—— 相位枚举 = 桥的 ABI /
//     fromBridge 四相位 + 未知值兜 Down（登记：与 G2 有张力的宽松兜底）。
//   → `tests/Feature/Input/HoverTest.php`（5）—— 目标变化才通知 / 光标出口。
//   → `tests/Feature/Input/PointerDragTest.php`（6）—— 阈值两侧 / 放下交 payload /
//     状态清空 / 拖出客户区取消（不卡死）。
//   → `tests/Feature/Input/GestureArenaTest.php`（4）—— 过阈值 drag 胜 /
//     松手仍不激活 / 未过阈值 tap 胜 / 非可拖控件 down 即激活。
//   ★ 下面留着的是**声明的让位规则**（ownsPress / keyboard 两条缝 + 顶条
//     当标题栏）：主题是"谁该接管、谁该让位"，与上面"事件序列本身"不是一回事。

// ⑥ 「按下归我」/「键盘归我」/ 顶条当标题栏 已迁出
//   → `tests/Feature/Input/PressClaimTest.php`（7）—— `ownsPress` 接管按下
//     （回调收到 down + 捕获 + 命令不发 + 焦点仍落上去）、捕获期不命中测试、
//     up 收口、默认声明零回归、子按钮赢过祖先的 `ownsPress`。
//   → `tests/Feature/Input/KeyboardSeamTest.php`（6）—— `keyboard()` 自己声明
//     （框架不认识的键也能吃掉）/ 返回 false 让位到激活 / 反例不串台 +
//     沿父链三条（方向键走得通 / 外层吃掉 Backspace / 外层让位后落到 TextFieldState）。
//   → `tests/Feature/App/TitleBarDragTest.php`（7）—— 顶条 `windowDrag()`：
//     标题文字真拖 / 不捕获 / 不改焦点 / 一次按下只拖一次 / 按钮与 `pointer()`
//     声明者让位 / 顶条外反例（声明只在子树内生效）。
//   ★ 迁出时修掉一处恒真判据：smoke 用 `move(5.0, 5.0)` 表示"拖到控件外"，
//     但接管按钮就在左上角 ⇒ 落点仍在它自己的盒内 ⇒ "捕获期不命中测试"对它
//     恒真。现在落点由盒现算且落在**下一个控件**上（事件跑掉才可观测）。

// ⑦ UIA 语义推送已迁出
//   → `tests/Feature/Semantics/SemanticsPushTest.php::testSinkPayloadShape`
//     （载荷形状：两个 Button 各一节点 / role = button / name = 标签）

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── 平台后端解析（§7.2 裁决 4）已迁出 ──────────────────────────────────────
//   · `tests/Unit/Platform/BackendResolveTest.php`（5）—— 表驱动四行
//     （null ⇒ 默认 / 显式 skia / 显式 soft 不被吞 / 未知值退回默认）+ 一条前置
//     "默认不是 soft"（否则"显式 soft"那行对该值**恒真**，默认翻回去也没人发现）
//   ★ 两条失败都是静默的：默认没翻 ⇒ 界面继续锯齿；显式 soft 被吞 ⇒ 以为指定了
//     确定性后端、实际跑 skia（golden 开始漂但没有任何一处报错）。

// ── 虚拟列表 / ensureVisible / 锚点 / 缓冲带 已迁出 ────────────────────────
//   · `tests/Feature/Widget/LazyListTest.php`（18）—— 窗口只覆盖视口（5000 与 20000 行
//     都只造 5 行）/ maxScroll 按完整内容 / sliceOrigin 进坐标链 / 命中跟随窗 /
//     pad 是内容的一部分 / SVG 与全量列表逐字节一致 / 滚轮经 Scrollable 路由 /
//     LazyRow 按 width 算 / 格宽真的是 extent / overscan 两档（0 ⇒ 重建 1 次，200 ⇒ 0）
//   · `tests/Feature/Widget/EnsureVisibleTest.php`（9）—— revealDelta 盒级 / State 级真滚 /
//     已在视口内不标脏 / 下滚 / 比视口高则上沿对齐且幂等 / 横向取 x·width /
//     LazyColumn 同一条算术 / Tab 与 Shift+Tab 双向跟
//   · `tests/Feature/Widget/LazyAnchorTest.php`（4）—— 有 key 删首行仍钉原行 /
//     无 key 按行号夹 / 内容没变不碰偏移 / 锚点消失回顶
//   ★ 夹具统一在 `tests/Support/LazyFixtures.php`（三个文件共用一份，不各抄一遍）。
//   ★ `$anchorStateOf` 原被下方 Table 段的 overscan 用例借用 —— 那条已一并迁走。


// ── 帧预算与 D1 降级 / 挂载登记表 已迁出 ────────────────────────────────────
//   · `tests/Feature/Frame/BudgetTest.php`       —— ⑭⑮⑯ overBudget 立/复位 + ⑰⑱ D1 节流 + ⑲ FrameClock 真量
//   · `tests/Feature/Frame/MountRegistryTest.php` —— ⑳㉑㉒ 登记表消费 / 跳过已卸载节点
//   ★ 共用 `tests/Support/Frame.php`（open + tick + resetErrors + pushCount + diagnostics）。


// ── 窗口状态（§5.4 / M5）已迁出 ────────────────────────────────────
//   · `tests/Feature/State/WindowStateTest.php` —— ① Paths 解析 + ②~⑩ persist/restore 全分支
//   ★ 临时文件走 setUp/tearDown，⑦-b 深目录自建自删。

// ── 语义树（§9 无障碍 / M5）已迁出 ────────────────────────────────────
//   · `tests/Feature/Semantics/SemanticsTest.php` —— ① 空树 / ② 角色+type / ③ TextField / ⑤ 焦点×2+几何 / ⑥ hitTest / ⑦ Modal / ⑧ 越界 / ⑨ toArrays / ⑩ 区间·text / ⑪ LazyColumn（15 条）


// ── 表单控件第一批：Checkbox / Radio / Toggle（㉚）已迁出 ──────────────────────
//   · `tests/Feature/Widget/ToggleTest.php` —— 挂载 RenderToggle / 布局 / 选中·未选中像素 /
//     Radio 值推导 / 开关胶囊像素 / Tab+Space 激活链 / 语义角色（13 条）
//   ★ 像素走共享 `spinCanvas()`（tests/Support/functions.php）；语义走共享 `Semantics::element()`（tests/Support/Semantics.php）。



// ── 分节原语：Divider / Progress（㉛）已迁出 ──────────────────────────────
//   · `tests/Feature/Widget/DividerProgressTest.php` —— 挂载渲染盒 / 布局（吃满·固定·无界回落 MIN_WIDTH）
//     / 像素（Divider 整根线、Progress 墨水分界 168）/ 语义（Divider 装饰不进树、Progress role=progress）/
//     value 夹取（0/负/NaN→0，0.37→370，≥1→满 1000）（15 条）
//   ★ 像素走共享 `spinCanvas()`；语义走共享 `Semantics::element()`。



// ── 图标（Icon / IconName / IconSet）已迁出 ──────────────────────────────
//   · `tests/Feature/Widget/IconTest.php` —— 挂载 RenderIcon / 固定正方形 16×16 /
//     Menu 画墨 / 每个 IconName 都有数据 / 装饰不进语义树（5 条）
//   ★ 像素走共享 `spinCanvas()`；语义走共享 `Semantics::element()`。



// ── 动画：`AnimationController` / `Curve` / 动画链 / 帧阶段顺序 已迁出 ────────────
//   · `tests/Unit/Widget/AnimationControllerTest.php` —— Curve 三根曲线 + Controller 十二个时钟判据（纯算术）
//   · `tests/Feature/Animation/AnimationChainTest.php` —— State::tickFrame → wantsFrame → 帧真重建（特征链）
//   · `tests/Unit/App/FramePhaseTest.php` —— 帧阶段顺序：正确序一条不报 + 三个越界方向都报
//   ★ 纯逻辑与特征链分离：前者不依赖帧驱动，后者用 MotionApplication 夹具。


// ── 语义推送两档（W7 L6-6）已迁出 ─────────────────────────────────────────
//   `tests/Feature/Semantics/SemanticsPushTest.php`（7）—— 结构版本号随挂载增长 /
//   首帧推 full / 动画中 coalesce 每 4 帧一次 / 结构没变时版本号不动 /
//   coalesce 第 1 帧不推 / 虚拟列表换行算结构变 / 焦点变绕过 coalesce。
//   ★ 与 `SemanticsTest` 分工：那边判建树（Element 层），这边判推送节流（Application 层）。
// ── TestApp：声明式场景（`07` §4.2 / §4.3，W7 第 42 步）──
//   三条场景各验一类，都用 `TestApp` 的**同一个**执行器：
//     ① 虚拟列表：滚动前后都只造可见行（R1 查树，不查 Widget 声明）
//     ② 动画：没人调 `tick`，帧时钟自己推进（R4 显式 pump）
//     ③ 几何：滚动只动**子盒偏移**，不动视口盒（M2-3 的不变量）
//   ★ 第 4 条是**反例**：假谓词 / 选择器无匹配 / 选择器不唯一都必须记失败 ——
//     只写正例的宿主等于"永远绿"。
$lazyTextCount = static function (Application $app): int {
    $count = 0;
    $walk = static function (Element $el) use (&$walk, &$count): void {
        if ($el->widget() instanceof Text) {
            ++$count;
        }
        $el->visitChildren($walk);
    };
    $root = $app->root();
    if ($root !== null) {
        $walk($root);
    }

    return $count;
};
$spinnerStep = static function (Application $app): int {
    $root = $app->root();
    if (!$root instanceof StatefulElement) {
        return -1;
    }
    $state = $root->state();

    return $state instanceof SpinnerState ? $state->step() : -1;
};

$testAppScenarios = [
    TestApp::scenario('虚拟列表：滚动前后都只造可见行')
        ->app(new LazyApplication())
        ->constraints(400.0, 200.0)
        ->pump(1)
        ->expect(
            '树里的 Text < 20（500 行只造可见的）',
            static fn(Application $a): bool => $lazyTextCount($a) < 20
        )
        // ★ `scroll()` 的单位是**格**：1 格 = 48px（`Application::WHEEL_NOTCH_PX`）。
        //   这个 48 是**设计值**，改了就该让这条断言红 —— 不是"跑出来再抄回去"。
        // ★ 滚轮是**带动画**的（`glideByAxis()`）⇒ 必须 `settle()` 跑到收口，
        //   `pump(1)` 只走一部分（那条"是滑过去不是闪现"由 phpunit 单独钉住）。
        ->scroll(1.0)
        ->settle()
        ->expect(
            '滚轮真的改了偏移（1 格 = 48px）',
            static fn(Application $a): bool => $a instanceof LazyApplication && $a->lazyState()?->offset() === 48.0
        )
        ->expect(
            '滚动后仍然只造可见行',
            static fn(Application $a): bool => $lazyTextCount($a) < 20
        ),
    TestApp::scenario('Spinner：没人调 tick，帧时钟自己推进')
        ->app(new SpinnerApplication())
        ->constraints(120.0, 120.0)
        ->pump(1)
        ->expect('首帧 step === 1（attach 走同一入口）', static fn(Application $a): bool => $spinnerStep($a) === 1)
        ->pump(2)
        ->expect('再跑两帧 ⇒ step === 3', static fn(Application $a): bool => $spinnerStep($a) === 3),
    TestApp::scenario('几何：滚动只动子盒偏移，不动视口盒')
        ->app(new LazyApplication())
        ->constraints(400.0, 200.0)
        ->pump(1)
        ->expectBox('LazyColumn', 0.0, 0.0, 400.0, 200.0)
        ->scroll(3.0)
        ->settle()
        ->expectBox('LazyColumn', 0.0, 0.0, 400.0, 200.0),

    // ★ 走通 `tap()` / `tapAt()` 的场景（TestApp 的纪律：动词没被场景走通过
    //   就不算实现）。这件事以前只能靠 `probe-modal.py` 去真机抓像素猜
    //   （"中央有没有近白面板""右上角有没有墨迹"）—— 现在几步断言离线跑完。
    //   两条筛选器口径也在这里钉住：`#key` 与**浮层里的**类名（模态在浮层，
    //   不在 `root()` 子树里 —— 走漏了会报"一个都没匹配到"，像极了"模态没开"）。
    TestApp::scenario('模态：点按钮开 → 点 X 关 → 再开 → 点遮罩关')
        ->app(new ModalApplication())
        ->constraints(480.0, 360.0)
        ->pump(1)
        ->expect(
            '起点没开（槽位空）',
            static fn(Application $a): bool => $a instanceof ModalApplication && !$a->isModalOpen()
        )
        ->tap('#open')
        ->pump(1)
        ->expect(
            '点「打开模态」⇒ 命令派发 + 模态开',
            static fn(Application $a): bool => $a instanceof ModalApplication
                && $a->seen === ['modal.open'] && $a->isModalOpen()
        )
        ->expectBox('ModalHost', 0.0, 0.0, 480.0, 360.0)
        // 像素独有判据（树/命令都答不了"遮罩真的画了吗"）：右下角在面板外、
        // 也不在任何按钮上 ⇒ 开模态时必是遮罩色。`Color(0,0,0,90)` 压白底
        // 的合成结果是 `255 - 90 = 165` 灰（alpha 直接等于"减掉多少亮度"，
        // 所以这里正好是整数，没有取整误差）。
        ->expectPixel(470.0, 350.0, new Color(165, 165, 165))
        ->tap('IconButton')
        ->pump(1)
        ->expect(
            '点右上角 X ⇒ 模态真关 + 槽位清空',
            static fn(Application $a): bool => $a instanceof ModalApplication
                && !$a->isModalOpen() && $a->modal() === null
        )
        // 同一点回到白底 —— 这条才是 probe-modal 判据 ④「遮罩区亮度恢复」
        // 的离线等价物：只断言"模态状态变了"查不出"遮罩层没跟着撤"。
        ->expectPixel(470.0, 350.0, new Color(255, 255, 255))
        ->tap('#open')
        ->pump(1)
        ->tapAt(5.0, 5.0)
        ->pump(1)
        ->expect(
            '点遮罩 ⇒ 关且吞掉这次按下（没穿透到背景按钮）',
            static fn(Application $a): bool => $a instanceof ModalApplication
                && !$a->isModalOpen() && $a->seen === ['modal.open', 'modal.open']
        ),
];

foreach ($testAppScenarios as $scenario) {
    $result = $scenario->run();
    check(
        'TestApp · ' . $result['name'] . '（' . $result['passed'] . ' 条断言）',
        $result['failed'] === 0,
        implode(' ｜ ', $result['failures'])
    );
}

// 反例：宿主自己也得会红
$testAppNegative = TestApp::scenario('反例：假谓词 / 无匹配 / 不唯一')
    ->app(new LazyApplication())
    ->constraints(400.0, 200.0)
    ->pump(1)
    ->expect('故意假', static fn(Application $a): bool => false)
    ->expectBox('NoSuchWidget', 0.0, 0.0, 1.0, 1.0)
    ->expectBox('Text', 0.0, 0.0, 1.0, 1.0)
    ->run();
check(
    'TestApp · 反例：假谓词 + 选择器无匹配 + 选择器不唯一都记失败（R2 与 G2 都有牙）',
    $testAppNegative['failed'] === 3 && $testAppNegative['passed'] === 0,
    'failed=' . $testAppNegative['failed'] . ' ｜ ' . implode(' ｜ ', $testAppNegative['failures'])
);

// ── W8-2 控件族：SegmentedControl / Slider / Tooltip / Snackbar / Option / ─────
//    Select / ComboBox 已迁出 ──────────────────────────────────────────────────
//   · `tests/Feature/Widget/SegmentedControlTest.php` —— 结构产盒 / 等宽与 offset 公式 /
//     命令序 / 选中块几何与药丸装得下标签 / 段间缝·未选段·轨道 chrome 像素 /
//     kidList 每次重建 / 越界选中全不选 / 空轨只剩内距 / activate 三态 /
//     语义 radio×3 / Tab+Space 八步 / 指针落段（26 条）
//   · `tests/Feature/Widget/SliderTest.php` —— 尺寸与内禀公式 / thumbX 与 valueAt 互逆 /
//     越界夹紧 / step 量化 / 命令序与轨道·已选段·拇指几何 / 拇指像素与 pad 区零 accent /
//     指针拖拽捕获与释放 / 禁用吞按下 / 方向键·Home·End·step 基值 / 语义区间与写值夹取（28 条）
//   · `tests/Feature/App/AfterSchedulerTest.php` —— after/cancelAfter：排期上膛 /
//     未到点·到点·触发后归假 / 撤销幂等 / 回调内再排不丢 / due 基准取输入时刻（9 条）
//   · `tests/Feature/Overlay/TooltipTest.php` —— 悬停 500ms / closeMenu 复位状态位 /
//     落位居中上方 + 条目参数 / hitTest=false 穿透与 true 反例 / 离开立即收·回来重新计时 /
//     无提示不排期 / 相邻立即换（20 条）
//   · `tests/Feature/Overlay/SnackbarTest.php` —— 立即显示与贴底居中落位 + 条目参数 /
//     到点消失 / FIFO 上位 / duration=0 永不消失 / 动作按钮回调与收口 / dismiss 幂等（17 条）
//   · `tests/Unit/Widget/OptionTest.php` —— 空表·list 原样·映射键收口·fromRows /
//     4 条响亮失败（裸值·空键·缺字段·非数组行，走子进程）（16 条）
//   · `tests/Feature/Widget/SelectTest.php` —— 值→下标 / 开面板与参数 / 面板内容 Primary /
//     点选项回调收面板回焦点 / 点外与 ESC / 无浮层按钮照旧 / 长列表限高可滚 / 短列表贴内容（26 条）
//   · `tests/Feature/Widget/SelectStateTest.php` —— Enter 开面板 / 方向键高亮起点·Down·Up 环绕 /
//     loading·error 换面板 / 值替换非优先级 / 内置与声明空态 / 换表项数变（14 条）
//   · `tests/Feature/Widget/ComboBoxTest.php` —— 开面板切空搜索框 / 跨树过滤 / Enter 取第一项 /
//     再开复位 / Esc 丢弃 / 点项提交 / 无匹配不关 / Tab 后打字仍进输入框 / 点外丢弃（16 条）
//   · `tests/Feature/Widget/ComboBoxRemoteTest.php` —— 换表重读与同内容不重建 /
//     远程 onSearch 只挂用户输入 / 本地过滤让位 / 加载中不选中 / 换表字段不变空白 /
//     没见过的值为空 / 面板未同步时 wantsFrame 为真（14 条）
//   ★ 响亮失败用例的入口不再是 smoke 的 `runCase(__FILE__, ...)`，而是
//     `tests/Support/fatal_cases.php` + `runFatalCase()`（用例表里加一行即可）。
// ── W8-3 Grid / LazyGrid 已迁出 ────────────────────────────────────────────
//   · `tests/Feature/Widget/GridTest.php` —— 切块 / 每格等宽（末行补 Spacer）/ 行高与行距 / 列数 0 响亮失败。
//   · `tests/Feature/Widget/LazyGridTest.php` —— 视口只造 ceil(240/96) 行 / 格宽与全量版一致 / 格子高 = 节距−间距 / 末行不变宽 / 行距≥节距响亮失败。
//   · 夹具下沉到 `tests/Support/GridFixtures.php`（Grid/LazyGrid 共用）；两个响亮失败用例移到 fatal_cases.php（gridzero / lazygridpitch）。

// ── 日志系统 / 日志 P1 已迁出 ──────────────────────────────────────────────
//   · `tests/Unit/Logging/LogTest.php` —— 门槛（全局 / 分类点名覆盖）/ 环满丢最旧 / seq 从 1 单调 /
//     Errors→Log 诊断桥（且不动 `Errors::$ring`）/ disable 后零写入（连 FATAL）/
//     pattern 确定性 + "compile 拆片段、format 只拼接"（反射钉结构）/ 每 sink 独立 formatter /
//     占位符全集（%T 由 entry 的 wallMs 算）/ recent() 双参数过滤 / flush·close 逐个转发
//     且单个 sink 炸不影响其他（13 条）。
//   · `tests/Feature/Logging/LogStreamSinkTest.php` —— 落盘逐字节 / 超上限轮转
//     （★ 关键夹具是"单条就超上限"：证明轮转检查在 write() 开头，不劈开单条）（2 条）。
//   · 响亮失败用例 `log-bad-pattern` 移到 `tests/Support/fatal_cases.php`。

// ── 毛玻璃 / 表面底色已迁出 ────────────────────────────────────────────────
//   · `tests/Feature/Rendering/BackdropFilterTest.php` —— 盒类型与子尺寸 / OP_BLUR_BACKDROP
//     与 sigma 原样 / 模糊矩形 = 内容盒（含内边距反例）/ 顺序（模糊在子墨之前）/
//     栈平衡 / Raster 回放不崩且**真的不模糊** / CAP_BACKDROP / Skia 侧 dpr+偏移换算 /
//     sigma=0 早退且合法（10 方法 / 12 条）。
//   · `tests/Feature/Rendering/SurfaceBackgroundTest.php` —— 底色落到清屏像素（预乘）/
//     底色含 alpha 原样进 Skia clear / RecordingSurface::raster() 用同一底色（3 条）。
//   · `tests/Feature/Platform/WindowMaterialTest.php` —— 值语义 / 逐像素声明（含 a=0）/
//     中性快照 / Blur→1·Acrylic→2·null→0 / ABI 枚举值 / 能力位两组掩码 /
//     ★ 带 alpha 却没 setFrameless ⇒ 响亮失败（8 方法 / 9 条）。
//   ★ 前置改造：Skia / Win32 假桥从 smoke 内联下沉到 `tools/fakes/{skia,win32}.php`，
//     `tests/Support/bootstrap.php` 也 require 同一份（否则那 7 条"桥收到什么"的断言
//     在 PHPUnit 侧加载不到）。`drift_win_material_caps()` 顺带改成可配
//     （`$GLOBALS['DRIFT_SMOKE_WIN_CAPS']`），否则"位 ↔ getter 名字"证伪不了。
// ── ㊾ P2 阶段 1：`Table`（固定表头 + 虚拟行 + 列宽三层）已迁出 ──────────────
//   → `tests/Feature/Table/Phase1Test.php` —— ①10000 行只造一屏+缓冲 / ②maxScroll 按完整行高 /
//     ③列宽三层 / ④表头与数据行逐像素对齐 / ⑤未滚时行区紧贴表头 / ⑥滚动后表头不动且第 1 行
//     顶边落在 24 / 缓冲带让第 0 行不卸载。
//     ★ 这 9 条在 smoke 里是**从未删除的重复副本**（标签、数字、注释与测试文件一字不差）——
//       2026-10-28 第 26 批清掉，smoke 少 9 条纯冗余。
//   → `tests/Feature/Table/ChromeTest.php` —— 斑马行 / 滚动条可见性与 `ScrollBarHost` /
//     5 条像素（表头底色 · 斑马 · 行区下方空白 · 滚动后裁剪 · 滚动后行位置）。
//   → `tests/Feature/Table/HitPartitionTest.php` —— 命中分区（表头区的点进表头子树 /
//     行区的点进行区子树，不是"命中了个非 null"）。
//   → `tests/Feature/Table/WidthDriftTest.php` —— 首帧视口宽漂移（发现差异要一帧 /
//     按真实盒宽重算 / 收敛后不再空转）。
//   → `tests/Feature/Table/ScrollTest.php` —— 内容装得下时偏移回夹 0（弱档）。
//   → `tests/Feature/Widget/ScrollViewScrollTest.php` —— 借来对照的 4 条 `ScrollView` 语义
//     （0 重建 / appliedOffset / 帧间画面真变 / 回夹强档）：被测是 ScrollView，不是表格。
//   ★ 那三个 Table 夹具（$tableMount / $tableStateOf / $tableBoxOf）已随
//     「⑲ 滚轮带轴」一起删除 —— ⑲ 是它们最后一个使用者。
// ── Table 选择 / 排序 / 列宽拖拽的"响亮失败"已迁出 ───────────────────────────
//   → `tests/Feature/Table/ConfigFailureTest.php`（11 个配置面 case / 34 条断言）
//   ★ 迁出的理由不是"判据变了"，而是 `runFatalCase()` 已把子进程入口从 smoke
//     解耦（`tests/Support/fatal_cases.php`）⇒ 这类用例不再必须留在 smoke 里。
// ── Table 键盘光标格的断言已迁出 ──────────────────────────────────────────────
//   → `tests/Feature/Table/KeyboardTest.php`（单 Tab 位 / 方向键 / 边界吞键 /
//     End 自动滚动 / 横向 ensureVisible / 失焦保留光标格 / 子控件让位 / 2px 描边像素）
//   → 列宽带 `maxWidth` 的夹取在 `tests/Feature/Table/ColumnResizeTest.php`
//   ★ 「滚轮带轴」与「单轴容器的按轴语义」都不是键盘语义，已各自迁出（见下）。


// ── ⑱ 单轴容器的按轴语义 已迁出 ─────────────────────────────────────────────
//   → `tests/Feature/Widget/ScrollAxisTest.php`（2）—— 问错轴什么都不做 /
//     主轴由 Widget 决定（不是"有溢出就换个方向跑"）。
//   ★ 它原来靠 `ensureVisible` 段的 `$revState`/`$hState` 活着（相距 3000 行），
//     那两段迁走时一并搬出；主题是滚动容器，不是表格。


// ⑲ 阶段 5b：滚轮**带轴** 已迁出
//   → `tests/Feature/App/ScrollWheelTest.php`（轴来自事件 / 两轴不串味 /
//     装得下的轴不标脏 / 双轴不回落 / 单轴不回落横划）
//   ★ 主题是"滚动输入"，不是表格 —— 宿主用 Table 只是因为它双轴都支持，
//     才能把"轴"与"容器有没有那根轴"分开。

// ⑪ Fatal 面（缺 keyOf / fixed(0) / field+cell / 字段拼错）已迁出
//   → `tests/Feature/Table/ConfigFailureTest.php`

// ═══════════════════════════════════════════════════════════════════════
// P2 阶段 7a：单元格编辑（2026-10-27）
// ═══════════════════════════════════════════════════════════════════════

// ── 编辑会话 / 点击链 / 拖动框选 已迁出 ────────────────────────────────────
//   · `tests/Feature/Table/EditTest.php`       —— 进 / 提交 / 丢弃 / 失效（9 条）
//   · `tests/Feature/Table/TapTest.php`        —— 单击落格 / 双击进编辑 / 插入符（6 条）
//   · `tests/Feature/Table/DragSelectTest.php` —— 区间 / 反向 / 阈值 / 自动滚（9 条）
//   · `tests/Unit/Input/TapTrackerTest.php`    —— 四条规则 + 三个断链边界（9 条）
//   · `tests/Unit/Text/TextDraftTest.php`      —— 字节数学
//   ★ 它们共用 `DrivesEditSession`（按键必推帧、帧号自增）。


// ⑭ 两条"期望响亮失败"已迁出
//   → `tests/Feature/Table/ConfigFailureTest.php`（受控声明缺上报 / cell() 列可编辑）

// ── 汇总 + 退出码（2026-10-18）──
// ★ 以前只打印 FAIL、**从不汇总、也从不非零退出** ⇒ 打印 3 个 FAIL 仍然 exit 0：
//   任何自动化调用（脚本 / CI / `&&` 链）都会把它读成"过了"。与 G2（不许静默
//   失败）直接冲突，故收口在这里。反例自检：故意让一条 check() 失败 ⇒ 末行
//   `N FAIL` 且退出码 1。
printf("\n--- %d ok / %d FAIL ---\n", $checks - $failures, $failures);
exit($failures === 0 ? 0 : 1);
