<?php
declare(strict_types=1);

/**
 * drift-smoke —— M0 的出口判据（§19）：骨架能装载、错误出口三级都真的生效。
 *
 * 用法：
 *   php tools/smoke.php
 *
 * ★ 中止路径（Fatal / 封存后写入）会真的 `exit(3)`，所以它们跑在**子进程**里，
 *   断言的是"退出码 + 日志内容 + 错误框被调到" —— 而不是把 abort 改成可注入的
 *   测试钩子（那会给生产 API 添一个只为测试存在的开关，正是 A15）。
 */

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

fresh($log);
if (is_file($log)) {
    unlink($log);
}

printf("\n--- %s ---\n", $failures === 0 ? '冒烟全绿' : $failures . ' 项失败');
exit($failures === 0 ? 0 : 1);
