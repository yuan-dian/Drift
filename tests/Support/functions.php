<?php
declare(strict_types=1);

/**
 * 冒烟段的**全局断言入口** —— 段代码里调的就是这个 `check()`。
 *
 * ★ 为什么是全局函数而不是 `$this->assertXxx()`：段代码有 1727 条 `check()`
 *   调用，改成方法调用等于重写全部断言。让 `check()` 转发给"当前活动的
 *   TestCase"，搬迁就退化成"把代码整段放进方法体"。
 * ★ 不在 TestCase 里时（单跑一个段脚本调 bug）退回打印模式，不抛。
 */

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Layout\RenderBox;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;

if (!\function_exists('check')) {
    function check(string $name, bool $ok, string $detail = ''): void
    {
        $case = SmokeTestCase::currentCase();

        if ($case !== null) {
            $case->recordCheck($name, $ok, $detail);

            return;
        }

        \printf("  %s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $detail === '' ? '' : '  —— ' . $detail);
    }
}

if (!\function_exists('findElement')) {
    /** 深度优先找第一个满足谓词的 Element（找不到 ⇒ null） */
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
}

if (!\function_exists('repoRoot')) {
    /**
     * 仓库根目录的绝对路径。
     *
     * ★ 搬迁前真资源路径写的是 `__DIR__ . '/../assets/...'`（相对 `tools/`）；
     *   测试在 `tests/Unit/Rendering/` 这类深两级的目录里，`__DIR__` 不能再用。
     *   也别用 `getcwd()`：phpunit 从哪个目录启动都能跑，cwd 不是契约。
     */
    function repoRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}

if (!\function_exists('smokeContext')) {
    /**
     * 320×240 的标准上下文（与 `tools/smoke.php` 同规格）。
     *
     * ★ 每次都建新的：`DeterministicMetricsProvider` 首次 layout 会算字宽，
     *   缓存住它会让"A 用例改了度量 ⇒ B 用例跟着变"的串味无处可查。
     *   实测单次 ≈ 2.2 ms，不是瓶颈。
     */
    function smokeContext(): BuildContext
    {
        return new BuildContext(
            320.0,
            240.0,
            ThemeData::light(),
            new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ),
        );
    }
}

if (!\function_exists('spinCanvas')) {
    /**
     * 把一个 RenderBox 画到软件光栅画布（与 `tools/smoke.php` 同名函数同实现）。
     *
     * ★ 像素断言的单一来源：Checkbox/Radio/Toggle/Spinner/Icon/Progress 等控件测试
     *   都靠它取色，放这里避免每个测试各抄一遍（与 smoke 各自一份，互不加载）。
     */
    function spinCanvas(\Drift\Layout\RenderBox $box, int $width, int $height): \Drift\Rendering\RasterCanvas
    {
        $list = new \Drift\Rendering\DisplayList();
        $box->paint(new \Drift\Rendering\RecordingCanvas($list));

        $canvas = new \Drift\Rendering\RasterCanvas($width, $height, \Drift\Rendering\Color::white(), \Drift\Geometry\Scale::of(1.0));
        $list->replay($canvas);

        return $canvas;
    }
}

if (!\function_exists('boxOrigin')) {
    /**
     * 盒在客户区的绝对原点（沿父链累加相对偏移）。
     *
     * ★ 委派 `RenderBox::absoluteOrigin()`，**不要**在这里手抄一遍累加：
     *   W5 之后子盒 offset 住**内容盒**坐标，"内容原点 → 边框盒原点"那一步在
     *   `absoluteOrigin()` 里补。手抄版少补一次 ⇒ `Padding::all(24)` 的字段盒
     *   原点算成 (0,0)，7 条框选/光标断言全红（2026-10-22 实测）。
     */
    function boxOrigin(\Drift\Layout\RenderBox $box): array
    {
        return \Drift\Layout\RenderBox::absoluteOrigin($box);
    }
}

if (!\function_exists('childBoxes')) {
    /**
     * 盒的**直接孩子**盒列表（子类不是 RenderBox 的跳过）。
     *
     * ★ 原先只是 smoke 里某个断言段内的局部闭包（`$kidsOf`），却被 700 行后的
     *   「侧栏宽度」用例借去 —— 与 `spinCanvas()` 同一个坑：工具不该长在断言段里。
     */
    function childBoxes(?\Drift\Layout\RenderBox $box): array
    {
        $out = [];
        $box?->visitChildren(static function ($child) use (&$out): void {
            if ($child instanceof \Drift\Layout\RenderBox) {
                $out[] = $child;
            }
        });

        return $out;
    }
}

if (!\function_exists('expectBox')) {
    /**
     * 断言某个盒的**矩形**（客户区绝对坐标）。
     *
     * ★ 与 `tools/smoke.php` 同名函数同实现。四个数一起断言：几何错通常是
     *   "整体偏了一个 padding"，分开断言会得到四条消息。严格 `===`，不加容差
     *   （布局是确定性的）。盒为 null / 没 layout 过 ⇒ 断言失败并写明是哪种。
     */
    function expectBox(?\Drift\Layout\RenderBox $box, float $x, float $y, float $w, float $h, string $name = ''): void
    {
        $label = $name !== '' ? $name : '盒矩形';
        if ($box === null) {
            check($label, false, '盒为 null（没挂上 / 没 layout）');

            return;
        }
        $size = $box->size();
        if ($size === null) {
            check($label, false, '盒 size() 为 null（layout 没跑）');

            return;
        }
        [$ax, $ay] = RenderBox::absoluteOrigin($box);
        check(
            $label,
            $ax === $x && $ay === $y && $size->width === $w && $size->height === $h,
            sprintf(
                '期望 (%.4g, %.4g) %.4g×%.4g，实际 (%.4g, %.4g) %.4g×%.4g',
                $x, $y, $w, $h,
                $ax, $ay, $size->width, $size->height
            )
        );
    }
}

if (!\function_exists('countTree')) {
    /** 数整棵子树有多少个 Element（含自己） */
    function countTree(Element $element): int
    {
        $total = 1;
        $element->visitChildren(static function (Element $child) use (&$total): void {
            $total += countTree($child);
        });

        return $total;
    }
}

if (!\function_exists('runFatalCase')) {
    /**
     * 起子进程跑一个"必须响亮失败"的用例，返回 `[退出码, 输出]`。
     *
     * ★ 只能起进程：`Errors::report()` 的 FATAL 落点是 `exit(3)`，进程内抓不到。
     *   用例表在 `tests/Support/fatal_cases.php`，加 case 加一行即可。
     * ★ 退出码 3 = 按预期 Fatal 了；0 = 该崩没崩；2 = case 名拼错（照 3 判会假绿）。
     *
     * @return array{0: int, 1: string}
     */
    function runFatalCase(string $case, ?string $log = null): array
    {
        $command = \escapeshellarg(PHP_BINARY)
            . ' ' . \escapeshellarg(__DIR__ . '/fatal_cases.php')
            . ' ' . \escapeshellarg('--case=' . $case);
        if ($log !== null) {
            $command .= ' ' . \escapeshellarg($log);
        }

        $output = [];
        $code = 0;
        \exec($command . ' 2>&1', $output, $code);

        return [$code, \implode("\n", $output)];
    }
}

if (!\function_exists('fresh')) {
    /** 清掉日志与状态，让每个用例互不污染（与 smoke 同名同义） */
    function fresh(string $log): void
    {
        \Drift\Errors::reset();
        \Drift\Errors::setLogPath($log);
        if (is_file($log)) {
            unlink($log);
        }
    }
}

if (!\function_exists('logText')) {
    function logText(string $log): string
    {
        return is_file($log) ? (string) file_get_contents($log) : '';
    }
}

if (!\function_exists('inkBounds')) {
    /**
     * 墨迹包围盒 `[minX, maxX, minY, maxY]`；无墨 ⇒ null。
     *
     * ★ 只数墨点数（`inkCount`）测不出"画在了哪儿" —— `Center` 算对偏移而绘制丢掉它，
     *   墨点数一样多，画面却贴在左上角。
     * ★ 扫描范围固定 320×240（与全部用例的画布规格一致），保持与 smoke 同实现。
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
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
}

if (!\function_exists('inkCount')) {
    /**
     * 非背景像素数：用来断言"真的画了东西 / 真的被挡住了"。
     *
     * ★ 唯一实现。此前 `TextFieldTest` 里有一份私有副本 —— 同一个量具两份实现会各自
     *   漂移，第三处要用时立刻下沉到这里。
     */
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
}

if (!\function_exists('describeColor')) {
    /**
     * 颜色的失败消息口径：`r,g,b` 三个数。
     *
     * ★ 唯一实现。此前 `FocusRingTest` 里有一份私有副本，`AppBarTest` 要用第二份时
     *   下沉到这里（§20：第二个使用者出现就下沉，不等第三个）。
     * ★ 不打印 ARGB 整数：像素断言红了要"一眼看出差多少"，`10,20,30` 比 `0xFF0A141E` 好读。
     */
    function describeColor(?Color $color): string
    {
        return $color === null ? 'null' : $color->r . ',' . $color->g . ',' . $color->b;
    }
}
