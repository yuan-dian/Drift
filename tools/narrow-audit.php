<?php
declare(strict_types=1);

/**
 * 窄窗审计（`tools/narrow-audit.php`）—— 「窗口拖到最小，每一页还装得下吗」。
 *
 * ```bash
 * php tools/narrow-audit.php            # 全部页，宽度由 minSize − 侧栏算出
 * php tools/narrow-audit.php --width=300
 * php tools/narrow-audit.php --verbose  # 每页都打一行（默认只打溢出页）
 * ```
 *
 * ## 它量的是什么
 *
 * 真机上用户可以把窗口拖到 `setMinSize()` 允许的最小值；那时**内容区**还剩多宽，
 * 取决于侧栏定宽。若页面里的 `Row` 比这还宽，`RenderFlex` 就裁掉多余部分
 * （窗口帧下走 `LayoutTolerance` 的宽容分支）—— 而页面外壳是**竖向** `ScrollView`，
 * 被裁掉的右边**没有横向滚动条可以拿回来**。用户看着就是"右边没了"。
 *
 * ⇒ 本工具把每页单独放进「内容区可用宽 × 一个够高的视口」里排一遍，
 * 收集 `LayoutTolerance::clipped()` 留下的记录。**每页各清一次**状态：
 * 框架的去重是按**类**的（`RenderFlex`），不清就会"第一页溢出后，后面全被吞掉"。
 *
 * ## 宽度不硬编码
 *
 * `420 − 200 = 220` 这个算式有两个来源：`Bootstrap::MIN_WINDOW_WIDTH` 与
 * `ShowcaseState::NAV_WIDTH`。两者都读出来算 —— 任何一处改了宽度，审计跟着变。
 * 写死 220 的话，改完侧栏宽度审计还按老宽度跑，**全绿是假的**。
 */
require __DIR__ . '/autoload.php';

use App\Showcase\Bootstrap;
use App\Showcase\ShowcaseRoot;
use App\Showcase\ShowcaseState;
use Drift\Dev\Inspect\Snapshot;
use Drift\Dev\Smoke\App\FakeDialog;
use Drift\Errors;
use Drift\Layout\LayoutTolerance;
use Drift\Platform\Dialog;
use Drift\Widget\BuildContext;
use Drift\Widget\Widget;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;

$verbose = \in_array('--verbose', $argv, true);
$width = null;
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--width=')) {
        $width = (int) \substr($arg, 8);
    }
}

/** 读一个类的私有常量（审计只读，不改产品代码）。 */
function constOf(string $class, string $name): int
{
    // ★ `ShowcaseState` 与 `ShowcaseRoot` 同住一个文件（一文件两类是本仓的既有写法）
    //   ⇒ PSR-4 找不到 `ShowcaseState.php`，必须先让装载器把兄弟类那个文件读进来。
    if (!\class_exists($class) && $class === ShowcaseState::class) {
        \class_exists(ShowcaseRoot::class);
    }

    $ref = new ReflectionClass($class);
    if (!$ref->hasConstant($name)) {
        \fwrite(\STDERR, "✗ {$class}::{$name} 不存在 —— 审计与产品代码已经漂了，先修这里。\n");
        exit(2);
    }

    return (int) $ref->getConstant($name);
}

$minWidth = constOf(Bootstrap::class, 'MIN_WINDOW_WIDTH');
$navWidth = constOf(ShowcaseState::class, 'NAV_WIDTH');
$contentWidth = $width ?? ($minWidth - $navWidth);

// 高度只影响"纵向装不装得下"，而有 ScrollView 兜着 ⇒ 宽度才是判据。
$height = 600;

echo "窄窗审计：窗口最小宽 {$minWidth} − 侧栏 {$navWidth} = 内容区 {$contentWidth}，"
    . "视口高 {$height}\n";
echo \str_repeat('─', 72) . "\n";

/**
 * 按构造签名补齐假依赖：闭包给空实现、`Dialog` 给 `FakeDialog`。
 *
 * ★ 不写死"哪几页要什么"：那会跟页面构造器一起漂。由签名决定，加参数不用改这里。
 */
function instantiate(string $class): object
{
    $ref = new ReflectionClass($class);
    $ctor = $ref->getConstructor();
    if ($ctor === null) {
        return $ref->newInstance();
    }

    $args = [];
    foreach ($ctor->getParameters() as $param) {
        $type = $param->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : '';

        if ($name === Closure::class || $name === 'Closure') {
            $args[] = static function (): void {};
            continue;
        }
        if ($name === Dialog::class || \is_subclass_of($name, Dialog::class)) {
            $args[] = new FakeDialog();
            continue;
        }
        if ($param->isDefaultValueAvailable()) {
            $args[] = $param->getDefaultValue();
            continue;
        }
        if ($param->allowsNull()) {
            $args[] = null;
            continue;
        }

        throw new RuntimeException(\sprintf('%s::__construct($%s) 审计不认识（%s）', $class, $param->getName(), $name));
    }

    return $ref->newInstanceArgs($args);
}

$files = \glob(__DIR__ . '/../apps/showcase/src/Pages/*Page.php') ?: [];
\sort($files);

$fail = 0;
$ran = 0;
$skipped = [];

foreach ($files as $file) {
    $short = \basename($file, '.php');
    $class = 'App\\Showcase\\Pages\\' . $short;

    try {
        /** @var Widget $page */
        $page = instantiate($class);
    } catch (\Throwable $e) {
        $skipped[] = $short . '（' . $e->getMessage() . '）';
        continue;
    }

    Errors::reset();
    LayoutTolerance::reset();

    try {
        // ★ 用 `withinWindow` 包住：溢出走**真机同一条**宽容分支（裁剪 + 留痕），
        //   而不是纯布局侧的 Fatal 中止。审计量的是"真机会怎样"。
        LayoutTolerance::withinWindow(static function () use ($page, $contentWidth, $height): void {
            Snapshot::ofPage($page, $contentWidth, $height);
        });
    } catch (\Throwable $e) {
        echo \sprintf("  ✗ %-20s 布局抛异常：%s\n", $short, $e->getMessage());
        $fail++;
        $ran++;
        continue;
    }

    $ran++;
    $diag = Errors::recentDiagnostics();

    if ($diag === []) {
        if ($verbose) {
            echo "  ✓ {$short}\n";
        }
        continue;
    }

    $fail++;
    echo \sprintf("  ✗ %-20s %d 条：\n", $short, \count($diag));
    foreach ($diag as $msg) {
        echo '      ' . \str_replace("\n", "\n      ", $msg) . "\n";
    }
}

echo \str_repeat('─', 72) . "\n";

if ($skipped !== []) {
    echo "未审计（无头建不出来，**不计入通过**）：\n";
    foreach ($skipped as $s) {
        echo "  · {$s}\n";
    }
}

echo \sprintf("合计：%d 页审计 · %d 页装不下 · %d 页跳过\n", $ran, $fail, \count($skipped));

if ($fail > 0) {
    echo "结论：✗ 最小窗口下有内容被裁且拿不回来（页面外壳是竖向 ScrollView，没有横向滚动）。\n";
    exit(1);
}

echo "结论：✓ 最小窗口下所有页面都装得下\n";
exit(0);
