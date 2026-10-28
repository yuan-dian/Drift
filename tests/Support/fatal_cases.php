<?php
declare(strict_types=1);

/**
 * "必须响亮失败"用例的子进程入口。
 *
 * ## 为什么必须另起进程
 *
 * `Errors::report()` 的默认级别是 FATAL，落点是 `exit(3)` —— 进程内**抓不到**。
 * 所以"该崩的真的崩了、退出码是 3、错误信息里说了用户该怎么改"这三件事只能在
 * 新进程里验。`tools/smoke.php` 一直用 `runCase(__FILE__, $case)` 做这件事，但
 * 那种写法的 `__FILE__` 必须是 smoke 入口 ⇒ 用例搬到 PHPUnit 就只能整段留在
 * smoke 里（`EachTest` / `StateProducedBoxTest` 就是这么留下的，见它们的头注）。
 *
 * 这个脚本把入口从 smoke 里解耦出来：case 名 + 一段会 Fatal 的代码，谁都能加。
 *
 * ```bash
 * php tests/Support/fatal_cases.php --case=optbare            # 日志落 Errors.log
 * php tests/Support/fatal_cases.php --case=optbare /tmp/x.log # 日志落指定路径
 * ```
 *
 * 退出码约定（与 smoke 同口径）：**走了 FATAL ⇒ 3**。没崩的 case 自己 `exit(0)`，
 * 好让"该崩没崩"在调用方那边表现为 `code !== 3` 而不是一句含糊的"失败了"。
 *
 * ★ 调用方请用 `runFatalCase()`（`functions.php`），别在这里 `exec` 一遍。
 * ★ 新增 case 时只往 `$cases` 里加一行 —— 不要再长出 `if ($case === ...)` 链。
 */

require __DIR__ . '/../../tools/autoload.php';

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Grid;
use Drift\Widget\LazyGrid;
use Drift\Widget\Option;
use Drift\Widget\Text;
use Drift\Widget\Widget;

$case = '';
$logPath = \dirname(__DIR__, 2) . '/Errors.log';

foreach (\array_slice($argv, 1) as $arg) {
    if (\str_starts_with($arg, '--case=')) {
        $case = \substr($arg, 7);

        continue;
    }
    if (!\str_starts_with($arg, '--')) {
        $logPath = $arg;
    }
}

Errors::setLogPath($logPath);
Errors::setDebug(true);

/** @var array<string, callable(): void> $cases */
$cases = [
    // 选项表给了裸值（`['甲','乙']`）⇒ 身份不明确。`[0 => 'x']` 与 `['x']` 在 PHP 里
    // 是同一个数组，"值 0"与"标签列表"无法区分 ⇒ 猜就是替应用做决定。
    'optbare' => static function (): void {
        Option::normalize(['甲', '乙']);
    },

    // 空字符串键与 `value('')`（"未选"哨兵）语义冲突 ⇒ 必须响亮失败。
    'optemptykey' => static function (): void {
        Option::normalize(['' => '请选择']);
    },

    // 行里没有显示名列 ⇒ 静默变成整列空白（WPF / antd 就是这样）⇒ 这里 Fatal。
    'optnofield' => static function (): void {
        Option::fromRows([['id' => 1, 'title' => '北京市']], 'name', 'id');
    },

    // 行不是数组（比如直接给了 list<string>）⇒ 形状不对，必须响亮失败。
    'optbadrow' => static function (): void {
        Option::fromRows(['北京市', '上海市'], 'name', 'id');
    },

    // W8-3：列数非法（0）⇒ 构造期响亮失败（不留到布局期变成"没格子"）。
    'gridzero' => static function (): void {
        Grid::make(0);
    },

    // W8-3：行间距 ≥ 行节距 ⇒ 格子高 ≤ 0，build() 里响亮失败。
    // ★ 守卫在 build()（mount 时跑）⇒ 这里内联构造 BuildContext（smokeContext() 只在
    //   tests 助手与 smoke.php 各一份，子进程不加载）。
    'lazygridpitch' => static function (): void {
        $bad = LazyGrid::make(3, 96.0)
            ->spacing(96.0)
            ->items([0], static fn(int $i): Widget => Text::make('x'));
        $element = $bad->createElement();
        $element->mount(null, new BuildContext(
            320.0,
            240.0,
            ThemeData::light(),
            new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ),
        ));
        $element->effectiveBox()?->layout(BoxConstraints::tight(new Size(336.0, 240.0)));
    },
];

if (!isset($cases[$case])) {
    \fwrite(\STDERR, "unknown case: {$case}\n");
    exit(2);
}

$cases[$case]();

// 走到这里 = 该崩的没崩。退出码 0 让调用方靠 `!== 3` 一眼看出方向。
echo "reached-end-without-fatal\n";
exit(0);
