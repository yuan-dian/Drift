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

fresh($log);
if (is_file($log)) {
    unlink($log);
}

printf("\n--- %s ---\n", $failures === 0 ? '冒烟全绿' : $failures . ' 项失败');
exit($failures === 0 ? 0 : 1);
