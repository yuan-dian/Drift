<?php
declare(strict_types=1);

/**
 * M2 conformance 夹具运行器 —— §19 M2 出口判据的可判定落点。
 *
 * ```bash
 * php tools/M2Conformance.php
 * ```
 *
 * ★ **它不是什么**：不是 M4 的 `drift-conformance` 常驻门（§16.2 六件套之一，
 *   O-10 已裁"做"）—— 本文件是**夹具 + 判据集**的第一次沉淀，工具就位后
 *   判据并入它，别为它新造机制（§19 状态列的同一条纪律）。
 *
 * ★ **判据分两类**（§16.6.2 的 W1–W8）：
 *   · **静态**（W1/W2/W3，token 级扫 `src/Widget`）：单一造盒路径 / 盒字段
 *     对齐（弱形式）/ 死字段。★ **量具自带反例自检** —— 先合成三个已知违规的
 *     临时类，抓不到就 FAIL（"先修量具，再用量具的数字"：§20 纪律 1 的教训，
 *     见 HISTORY 提交 d27db27 的同名事故；本次开发中两个探针原型就因
 *     `->` 是 T_OBJECT_OPERATOR 而非原始令牌而全体假阴性）。
 *   · **运行时**（W4/W5/W6/W7/W8 + W1r）：对夹具页 `M2LayoutPage` 挂载、
 *     跑两帧、平移复布局、录制断言。W5/W6/W7/W8 的**单元级**断言已在
 *     `tools/smoke.php`（Layout / Seal / Rendering 段）—— 这里断的是
 *     **夹具整体**，两边不重复。
 *
 * ★ **诚实边界**：W2 只查弱形式（字段被 `createRenderObject` 读**或**经
 *   public 方法暴露）—— 强形式（getter→盒字段映射表）是 R12 的活，归 M4
 *   的 lint。W3 查私有/受保护字段的类内死读；**public 字段的死检测是全仓活**
 *   （R11 完整形态，归 M4）。W1 扫 `src/Widget`（Widget→盒那条路径）；
 *   `src/Layout` 的渲染盒在构造器里组合子盒是渲染树组合，不属 W1 管辖。
 */

use Drift\App\FocusManager;
use Drift\App\Frame;
use Drift\Dev\Preview\M2LayoutPage;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderObject;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RecordingCanvas;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\Padding;
use Drift\Widget\RenderBoxWidget;
use Drift\Widget\Widget;

require __DIR__ . '/autoload.php';

$ok = 0;
$failures = 0;

function check(string $name, bool $pass, string $detail = ''): void
{
    global $ok, $failures;
    if ($pass) {
        $ok++;
        printf("  ok    %s\n", $name);
        return;
    }
    $failures++;
    printf("  FAIL  %s%s\n", $name, $detail === '' ? '' : '  —— ' . $detail);
}

// ── token 助手（PHP 8：`->` 是 T_OBJECT_OPERATOR 数组令牌，不是原始字符串；
//    `new` 与类名之间有空白令牌 —— 两处都咬过，量具反例自检就是抓这个）──

/** 下一个有意义的令牌（跳过空白与注释） */
function nextMeaningful(array $tokens, int $i): ?array
{
    for ($j = $i + 1; $j < count($tokens); $j++) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($tokens[$j]) ? $tokens[$j] : ['RAW', (string) $tokens[$j]];
    }

    return null;
}

/** @return list<array{int, int}> 文件内每个 class 的类体范围 [开括号, 闭括号] */
function classBodyRanges(array $tokens): array
{
    $ranges = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CLASS) {
            continue;
        }
        for ($j = $i + 1; $j < $n; $j++) {
            if (!is_array($tokens[$j]) && $tokens[$j] === '{') {
                $depth = 0;
                for ($k = $j; $k < $n; $k++) {
                    if (!is_array($tokens[$k]) && $tokens[$k] === '{') {
                        $depth++;
                    }
                    if (!is_array($tokens[$k]) && $tokens[$k] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $ranges[] = [$j, $k];
                            break;
                        }
                    }
                }
                break;
            }
            if (!is_array($tokens[$j]) && $tokens[$j] === ';') {
                break; // `class X;` 之类，无体
            }
        }
    }

    return $ranges;
}

/** 类体范围内的顶层方法：name => [bodyStart, bodyEnd, fnIndex] */
function methodBodies(array $tokens, array $classRange): array
{
    [$from, $to] = $classRange;
    $methods = [];
    $i = $from + 1;
    while ($i < $to) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
            $name = null;
            for ($j = $i + 1; $j < $to; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                    break;
                }
                if (!is_array($tokens[$j]) && $tokens[$j] === '(') {
                    break;
                }
            }
            if ($name !== null) {
                for ($j = $i + 1; $j < $to; $j++) {
                    if (!is_array($tokens[$j]) && $tokens[$j] === '{') {
                        $depth = 0;
                        for ($k = $j; $k < $to; $k++) {
                            if (!is_array($tokens[$k]) && $tokens[$k] === '{') {
                                $depth++;
                            }
                            if (!is_array($tokens[$k]) && $tokens[$k] === '}') {
                                $depth--;
                                if ($depth === 0) {
                                    $methods[$name] = [$j, $k, $i];
                                    break;
                                }
                            }
                        }
                        $i = $k + 1;
                        continue 2;
                    }
                }
            }
        }
        $i++;
    }

    return $methods;
}

/** 区间内 `$this->name` / `$this?->name` 的读点集合（空白容忍） */
function fieldReads(array $tokens, int $from, int $to): array
{
    $reads = [];
    for ($i = $from; $i < $to; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== '$this') {
            continue;
        }
        $op = nextMeaningful($tokens, $i);
        if ($op === null || !in_array($op[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        // 从 $this 之后重新按位置推进，找 -> 后的第一个 T_STRING
        for ($j = $i + 1; $j < $to; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                for ($k = $j + 1; $k < $to; $k++) {
                    if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
                        $reads[$tokens[$k][1]] = true;
                    }
                    break;
                }
            }
            break;
        }
    }

    return $reads;
}

/**
 * 类内自有字段名 => 可见性（属性声明 + 构造函数提升属性；排除 const / static /
 * 方法签名）。返回 ['name' => 'private'|'protected'|'public']。
 */
function declaredFields(array $tokens, array $classRange): array
{
    [$from, $to] = $classRange;
    $fields = [];
    for ($i = $from; $i < $to; $i++) {
        if (!is_array($tokens[$i])) {
            continue;
        }
        $vis = match ($tokens[$i][0]) {
            T_PRIVATE => 'private',
            T_PROTECTED => 'protected',
            T_PUBLIC, T_READONLY => 'public',
            default => null,
        };
        if ($vis === null) {
            continue;
        }
        // 向后找第一个 $var；途中撞 function/const/static 则不是字段声明
        for ($j = $i + 1; $j < min($i + 14, $to); $j++) {
            if (is_array($tokens[$j])) {
                if (in_array($tokens[$j][0], [T_FUNCTION, T_CONST, T_STATIC], true)) {
                    break;
                }
                if ($tokens[$j][0] === T_VARIABLE) {
                    $fields[ltrim($tokens[$j][1], '$')] = $vis;
                    break;
                }
            }
        }
    }

    return $fields;
}

/** 文件内 `new RenderXxx`（含限定名）的出现位置（=> 是否在某类的 createRenderObject 体内） */
function newRenderSites(array $tokens, array $classRanges): array
{
    $sites = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
            continue;
        }
        $next = nextMeaningful($tokens, $i);
        if ($next === null) {
            continue;
        }
        $short = null;
        if ($next[0] === T_STRING) {
            $short = $next[1];
        } elseif (in_array($next[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            $parts = explode('\\', ltrim($next[1], '\\'));
            $short = end($parts);
        }
        if ($short === null || !str_starts_with($short, 'Render')) {
            continue;
        }
        $inside = false;
        foreach ($classRanges as $range) {
            $methods = methodBodies($tokens, $range);
            $cro = $methods['createRenderObject'] ?? null;
            if ($cro !== null && $i >= $cro[0] && $i <= $cro[1]) {
                $inside = true;
                break;
            }
        }
        $sites[] = ['class' => $short, 'index' => $i, 'insideCro' => $inside];
    }

    return $sites;
}

/**
 * 扫一组文件的 W3（死字段）：返回 "Class::\$field" 列表。
 *
 * ★ 只查**私有/受保护**字段 —— public 字段是值对象的 API 面（如 `Key::$value`），
 *   由调用方读；"全仓库没人读的 public 字段"是 R11 的完整形态（跨文件分析），
 *   归 M4 的 lint，本尺子不假装能查。
 */
function scanDeadFields(array $files): array
{
    $findings = [];
    foreach ($files as $file) {
        $tokens = token_get_all((string) file_get_contents($file));
        foreach (classBodyRanges($tokens) as $range) {
            $reads = fieldReads($tokens, $range[0], $range[1]);
            foreach (declaredFields($tokens, $range) as $field => $vis) {
                if ($vis !== 'public' && !isset($reads[$field])) {
                    $findings[] = basename($file) . ' :: $' . $field;
                }
            }
        }
    }

    return $findings;
}

/**
 * 扫一组文件的 W2 弱形式：**有 createRenderObject 的类**（盒控件）的每个字段
 * 必须被 createRenderObject 读，或经 public 方法暴露。返回 "Class::\$field" 列表。
 */
function scanFieldAlignment(array $files): array
{
    $findings = [];
    foreach ($files as $file) {
        $tokens = token_get_all((string) file_get_contents($file));
        foreach (classBodyRanges($tokens) as $range) {
            $methods = methodBodies($tokens, $range);
            if (!isset($methods['createRenderObject'])) {
                continue; // 不是盒控件（结构节点 / StatefulWidget）—— W2 不管它
            }
            $croReads = fieldReads($tokens, $methods['createRenderObject'][0], $methods['createRenderObject'][1]);
            $publicReads = [];
            foreach ($methods as $mrange) {
                // public 判据：T_FUNCTION 前 4 个令牌内找 T_PUBLIC（`public function x(`）
                for ($i = $mrange[2] - 1; $i >= max(0, $mrange[2] - 4); $i--) {
                    if (!is_array($tokens[$i])) {
                        continue;
                    }
                    if ($tokens[$i][0] === T_PUBLIC) {
                        foreach (fieldReads($tokens, $mrange[0], $mrange[1]) as $k => $v) {
                            $publicReads[$k] = true;
                        }
                        break;
                    }
                    if (in_array($tokens[$i][0], [T_PRIVATE, T_PROTECTED, T_FUNCTION], true)) {
                        break;
                    }
                }
            }
            foreach (declaredFields($tokens, $range) as $field => $_) {
                if (!isset($croReads[$field]) && !isset($publicReads[$field])) {
                    $findings[] = basename($file) . ' :: $' . $field;
                }
            }
        }
    }

    return $findings;
}

/** 扫一组文件的 W1：`new Render*` 必须位于 createRenderObject 体内 */
function scanSingleBoxPath(array $files): array
{
    $findings = [];
    foreach ($files as $file) {
        $tokens = token_get_all((string) file_get_contents($file));
        foreach (newRenderSites($tokens, classBodyRanges($tokens)) as $site) {
            if (!$site['insideCro']) {
                $findings[] = basename($file) . ' :: new ' . $site['class'] . '（不在 createRenderObject 内）';
            }
        }
    }

    return $findings;
}

// ── 一、量具反例自检（先证明尺子能量，再量代码）──────────────────────────

echo "── M2 conformance：量具自检 + W1–W8 ──\n";
echo "· 量具反例自检\n";

$selftestDir = __DIR__ . '/../_build/conformance-selftest';
@mkdir($selftestDir, 0777, true);

$selftestFiles = [];
$write = function (string $name, string $body) use ($selftestDir, &$selftestFiles): string {
    $path = $selftestDir . '/' . $name;
    file_put_contents($path, $body);
    $selftestFiles[] = $path;
    require_once $path;
    return $path;
};

// W3 反例：死字段
$write('W3Probe.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Drift\ConformanceSelfTest;
use Drift\Widget\{BuildContext, Spacer, StatelessWidget, Widget};
final class W3Probe extends StatelessWidget
{
    private int $dead = 0;
    public function build(BuildContext $ctx): Widget
    {
        return Spacer::make();
    }
}
PHP);

// W2 反例：字段既不进 createRenderObject 也不暴露
$write('W2Probe.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Drift\ConformanceSelfTest;
use Drift\Layout\{RenderBox, RenderSpinner};
use Drift\Rendering\ColorToken;
use Drift\Widget\{BuildContext, RenderBoxWidget};
final class W2Probe extends RenderBoxWidget
{
    private float $orphan = 1.0;
    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderSpinner(8.0, 0, $context->color(ColorToken::Accent));
    }
}
PHP);

// W1 反例：造盒在 createRenderObject 之外
$write('W1Probe.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Drift\ConformanceSelfTest;
use Drift\Layout\{RenderBox, RenderSpinner};
use Drift\Rendering\ColorToken;
use Drift\Widget\{BuildContext, RenderBoxWidget};
final class W1Probe extends RenderBoxWidget
{
    public function createRenderObject(BuildContext $context): RenderBox
    {
        return $this->elsewhere();
    }
    private function elsewhere(): RenderBox
    {
        return new RenderSpinner(8.0, 0, \Drift\Rendering\Color::white());
    }
}
PHP);

check(
    '量具 · W3 反例被抓到（死字段）',
    in_array('W3Probe.php :: $dead', scanDeadFields($selftestFiles), true),
);
check(
    '量具 · W2 反例被抓到（字段不进盒也不暴露）',
    in_array('W2Probe.php :: $orphan', scanFieldAlignment($selftestFiles), true),
);
check(
    '量具 · W1 反例被抓到（createRenderObject 之外造盒）',
    count(array_filter(scanSingleBoxPath($selftestFiles), static fn (string $f): bool => str_starts_with($f, 'W1Probe.php'))) === 1,
);

// ── 二、静态扫描 src/Widget（W1 / W2 / W3）─────────────────────────────

echo "· 静态扫描 src/Widget\n";

$widgetFiles = glob(__DIR__ . '/../src/Widget/*.php') ?: [];

check(
    'W3 · src/Widget 无死字段（声明了就必须被读 —— A1）',
    scanDeadFields($widgetFiles) === [],
    implode('；', scanDeadFields($widgetFiles)),
);
check(
    'W2 · src/Widget 盒控件字段全部进盒或经 public 暴露（弱形式 —— A9）',
    scanFieldAlignment($widgetFiles) === [],
    implode('；', scanFieldAlignment($widgetFiles)),
);
check(
    'W1 · src/Widget 无 createRenderObject 之外的造盒（单一造盒路径）',
    scanSingleBoxPath($widgetFiles) === [],
    implode('；', scanSingleBoxPath($widgetFiles)),
);

// ── 三、运行时：夹具页 M2LayoutPage（W4 / W5 / W6 / W7 / W8 + W1r）─────

echo "· 运行时夹具页\n";

require __DIR__ . '/Preview/M2LayoutPage.php';

$theme = ThemeData::light();
$metrics = new DeterministicMetricsProvider(
    DeterministicFontManager::standard()->collection(),
    new DeterministicShaper(),
);

// 挂载夹具页（与 drift-preview 同路径：require + 取第一个声明类 + 无参构造）
$page = new M2LayoutPage();
$context = new BuildContext(360.0, 300.0, $theme, $metrics);
$element = $page->createElement();
$element->mount(null, $context);

// W6：挂载即封存（全树）
$unsealed = [];
$walkElements = function (Element $e) use (&$walkElements, &$unsealed): void {
    if (!$e->widget()->isSealed()) {
        $unsealed[] = $e->widget()::class;
    }
    $e->visitChildren($walkElements);
};
$walkElements($element);
check('W6 · 挂载后全树 Widget 已封存（Seal-on-Mount）', $unsealed === [], implode('；', $unsealed));

// 跑两帧（同约束、无状态变化）
$constraints = BoxConstraints::tight(new Size(360.0, 300.0));
$surface = new SurfaceProbe();
$surface->resize(360, 300, 1.0);
$frame = new Frame($surface);

/** @return list<array{class: string, w: ?float, h: ?float, ox: float, oy: float, id: int}> */
function collectBoxes(RenderObject $root): array
{
    $out = [];
    $walk = function (RenderObject $ro) use (&$out, &$walk): void {
        if ($ro instanceof RenderBox) {
            $out[] = [
                'class' => $ro::class,
                'w' => $ro->size()?->width,
                'h' => $ro->size()?->height,
                'ox' => $ro->offsetX(),
                'oy' => $ro->offsetY(),
                'id' => spl_object_id($ro),
            ];
        }
        $ro->visitChildren($walk);
    };
    $walk($root);

    return $out;
}

/** 以首盒为基准归一化（剥掉整体平移，留下内部几何） */
function normalizeBoxes(array $boxes): array
{
    if ($boxes === []) {
        return [];
    }
    $rx = $boxes[0]['ox'];
    $ry = $boxes[0]['oy'];

    return array_map(
        static fn (array $b): array => [$b['class'], $b['w'], $b['h'], $b['ox'] - $rx, $b['oy'] - $ry],
        $boxes,
    );
}

Errors::reset();
$frame->run($element, $constraints, 16.0);
$boxes1 = collectBoxes($element->effectiveBox());
$ids1 = array_column($boxes1, 'id');

$frame->run($element, $constraints, 32.0);
$boxes2 = collectBoxes($element->effectiveBox());
$ids2 = array_column($boxes2, 'id');

check(
    'W1r · 无状态变化的两帧之间盒身份稳定（没有第二条造盒路径每帧造新盒）',
    $ids1 === $ids2 && $ids1 !== [],
    sprintf('帧1 %d 盒 / 帧2 %d 盒', count($ids1), count($ids2)),
);
check(
    'W5 · 夹具同约束两帧 ⇒ 盒树几何逐一相等',
    normalizeBoxes($boxes1) === normalizeBoxes($boxes2),
);

// W5 位置无关：同一夹具平移 20,20 后再布局，内部几何不变（C1：子不自定位）
$padded = Padding::make($page)->all(20.0)->createElement();
$padded->mount(null, new BuildContext(400.0, 340.0, $theme, $metrics));
$padded->effectiveBox()?->layout(BoxConstraints::tight(new Size(400.0, 340.0)));
$boxesPadded = collectBoxes($padded->effectiveBox());
$inner = array_slice($boxesPadded, 1); // 去掉最外 Padding 盒
check(
    'W5 · 夹具整体位置无关（平移 20,20 后内部几何逐一相等）',
    normalizeBoxes($boxes1) === normalizeBoxes($inner),
);

// W4：夹具内的弹性容器显式声明 Report（会超的容器不许静默）
$flexCount = 0;
$flexBad = [];
$walkObjects = function (RenderObject $ro) use (&$walkObjects, &$flexCount, &$flexBad): void {
    if ($ro instanceof RenderFlex) {
        $flexCount++;
        if ($ro->overflow() !== Overflow::Report) {
            $flexBad[] = $ro::class . '=' . $ro->overflow()->name;
        }
    }
    $ro->visitChildren($walkObjects);
};
$walkObjects($element->effectiveBox());
check(
    'W4 · 夹具内弹性容器全部显式声明 Report（' . $flexCount . ' 个 RenderFlex）',
    $flexCount > 0 && $flexBad === [],
    implode('；', $flexBad),
);

// W7：渲染全程零 Errors 记录（G2：没有安静发生的坏事）
$diagnostics = Errors::recentDiagnostics();
check(
    'W7 · 夹具渲染全程零 Errors 记录',
    $diagnostics === [],
    count($diagnostics) . ' 条：' . implode('；', array_slice($diagnostics, 0, 3)),
);

// W8：录制结束命令栈归零（§7.2 缓冲复用的前提）
$list = new DisplayList();
$element->effectiveBox()?->paint(new RecordingCanvas($list));
check(
    'W8 · 录制结束 save/clip 栈深度归零',
    $list->saveDepth() === 0 && $list->clipDepth() === 0,
    sprintf('save=%d clip=%d', $list->saveDepth(), $list->clipDepth()),
);

// §19 M2 出口判据之三：Tab 序有夹具 —— 夹具里的可聚焦控件按文档序进链
$fm = new FocusManager();
$fm->collect($element);
$focusOrder = array_map(static fn (Element $e): string => $e->widget()::class, $fm->order());
check(
    '§9.1 · 夹具内可聚焦控件按文档序进焦点链（Tab 序有夹具）',
    $focusOrder === ['Drift\Widget\Button', 'Drift\Widget\Button', 'Drift\Widget\Button'],
    implode(' → ', $focusOrder),
);

// 清理自检临时类（量具的燃料，不进版本库）
array_map('unlink', $selftestFiles);
@rmdir($selftestDir);

printf("\n── %d ok · %d FAIL ──\n", $ok, $failures);
exit($failures > 0 ? 1 : 0);
