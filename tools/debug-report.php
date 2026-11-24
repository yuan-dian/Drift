#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `drift debug` —— 无头跑一个应用，回答"哪些节点重建了、因为谁"。
 *
 * 这是帧因果录制器的**真实使用者**：没有它，`Debug\Session` 就只有测试调用，
 * 而"写了但没被走通过的路径"是本项目最不想要的形态。
 *
 * 用法：
 *   php tools/debug-report.php SlowFrameApplication --frames=12
 *   php tools/debug-report.php TextAreaApplication --key=Tab --text=abc --key=Enter
 *   php tools/debug-report.php TextAreaApplication --key=Enter --json
 *
 * 动作语义：先跑 `--frames` 帧（默认 1，用于挂载），再按命令行顺序执行
 * `--key=` / `--text=`，**每个动作之后推进一帧** —— 重建发生在动作消费之后，
 * 不推帧就看不到因果。
 *
 * 只支持无参构造的应用。需要业务句柄的应用（要注入 Store / 端口）不适合
 * 这条路径：那是应用的组合根职责，CLI 不猜它怎么装配。
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/autoload.php';

use Drift\App\Application;
use Drift\App\DebugSession;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;

$appClass = null;
$frames = 1;
$width = 800;
$height = 600;
$json = false;
$top = 10;
/** @var list<array{0: 'key'|'text', 1: string}> */
$steps = [];

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif (\str_starts_with($arg, '--frames=')) {
        $frames = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--width=')) {
        $width = \max(1, (int) \substr($arg, 8));
    } elseif (\str_starts_with($arg, '--height=')) {
        $height = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--top=')) {
        $top = \max(1, (int) \substr($arg, 6));
    } elseif (\str_starts_with($arg, '--key=')) {
        $steps[] = ['key', \substr($arg, 6)];
    } elseif (\str_starts_with($arg, '--text=')) {
        $steps[] = ['text', \substr($arg, 7)];
    } elseif (\str_starts_with($arg, '--')) {
        \fwrite(\STDERR, "未知参数：{$arg}\n");
        exit(2);
    } else {
        $appClass = $arg;
    }
}

if ($appClass === null) {
    \fwrite(\STDERR, "用法：drift debug <AppClass> [--frames=N] [--key=Tab] [--text=abc] [--json]\n");
    exit(2);
}

$class = resolve_app($appClass);
if ($class === null) {
    \fwrite(\STDERR, "找不到应用类：{$appClass}（试过 Drift\\Dev\\Smoke\\App\\ 与 Drift\\Dev\\Smoke\\）\n");
    exit(2);
}

try {
    $app = new $class();
} catch (\ArgumentCountError $e) {
    \fwrite(\STDERR, "{$class} 的构造函数需要参数：本命令只跑无参应用（装配归组合根）\n");
    exit(2);
}

if (!$app instanceof Application) {
    \fwrite(\STDERR, "{$class} 不是 Application 子类\n");
    exit(2);
}

$constraints = BoxConstraints::tight(new Size((float) $width, (float) $height));
$app->attach(new SurfaceProbe(), $constraints);

$session = DebugSession::open($app, $constraints);

try {
    $t = 0.0;
    /** @var array<int, float> 帧号 => 整帧墙钟（毫秒） */
    $wall = [];
    $ms = $session->pump($t);
    $wall[$session->recorder()->lastFrameId()] = $ms;
    for ($i = 1; $i < $frames; ++$i) {
        $t += 16.0;
        $ms = $session->pump($t);
        $wall[$session->recorder()->lastFrameId()] = $ms;
    }

    foreach ($steps as [$kind, $value]) {
        $t += 16.0;
        $session->send($kind === 'text' ? KeyEvent::character($value) : KeyEvent::down(key_chord($value)), $t);
        $ms = $session->pump($t);
        $wall[$session->recorder()->lastFrameId()] = $ms;
    }

    $recorded = $session->frames($frames + \count($steps) + 1);
    foreach ($recorded as $i => $frame) {
        $recorded[$i]['wall'] = $wall[(int) $frame['id']] ?? $frame['cost'];
    }

    $report = [
        'app' => $class,
        'viewport' => [$width, $height],
        'stats' => $session->stats(),
        'frames' => $recorded,
        'hotspots' => $session->hotspots(0, $top),
        'nodes' => $session->nodes(0, $top),
    ];
} finally {
    $session->close();
}

if ($json) {
    \fwrite(\STDOUT, \json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

render_text($report);
exit(0);

// ---------------------------------------------------------------- 解析与渲染

/** @return class-string<Application>|null */
function resolve_app(string $name): ?string
{
    $candidates = \str_contains($name, '\\')
        ? [$name]
        : ['Drift\\Dev\\Smoke\\App\\' . $name, 'Drift\\Dev\\Smoke\\' . $name];

    foreach ($candidates as $candidate) {
        if (\class_exists($candidate) && \is_subclass_of($candidate, Application::class)) {
            return $candidate;
        }
    }

    return null;
}

/** `Ctrl+A` / `Shift+Tab` / `Enter` / `a` → 键码；单字符走文本输入。 */
function key_chord(string $spec): KeyChord
{
    $ctrl = false;
    $alt = false;
    $shift = false;

    while (($pos = \strpos($spec, '+')) !== false) {
        $modifier = \strtolower(\substr($spec, 0, $pos));
        $spec = \substr($spec, $pos + 1);
        match ($modifier) {
            'ctrl' => $ctrl = true,
            'alt' => $alt = true,
            'shift' => $shift = true,
            default => null,
        };
    }

    $named = [
        'tab' => 'Tab', 'enter' => 'Enter', 'esc' => 'Escape', 'escape' => 'Escape',
        'space' => ' ', 'backspace' => 'Backspace', 'delete' => 'Delete', 'del' => 'Delete',
        'left' => 'Left', 'right' => 'Right', 'up' => 'Up', 'down' => 'Down',
        'home' => 'Home', 'end' => 'End', 'pageup' => 'PageUp', 'pagedown' => 'PageDown',
    ];
    $key = $named[\strtolower($spec)] ?? $spec;
    if ($key === '') {
        $key = ' ';
    }
    if (\mb_strlen($key, 'UTF-8') === 1) {
        $key = \mb_strtoupper($key, 'UTF-8'); // 单字符键码统一大写，与 KeyEvent::character 同源
    }

    return KeyChord::make($key, $ctrl, $alt, $shift);
}

/** @param array<string, mixed> $report */
function render_text(array $report): void
{
    /** @var array<string, mixed> $stats */
    $stats = $report['stats'];
    /** @var list<array<string, mixed>> $frames */
    $frames = $report['frames'];
    /** @var list<array<string, mixed>> $hotspots */
    $hotspots = $report['hotspots'];
    /** @var list<array<string, mixed>> $nodes */
    $nodes = $report['nodes'];

    \fwrite(\STDOUT, \sprintf(
        "应用  %s\n视口  %dx%d     帧数  %d     节点数  %d     末帧墙钟  %.2fms\n\n",
        (string) $report['app'],
        (int) $report['viewport'][0],
        (int) $report['viewport'][1],
        (int) $stats['frames'],
        (int) $stats['nodes'],
        $frames === [] ? 0.0 : (float) $frames[\count($frames) - 1]['wall'],
    ));

    \fwrite(\STDOUT, "帧号   t(ms)    墙钟(ms)   重建  边   跳过\n");
    foreach ($frames as $frame) {
        $skipped = [];
        if ((bool) $frame['settleSkipped']) {
            $skipped[] = 'settle';
        }
        if ((bool) $frame['paintSkipped']) {
            $skipped[] = 'paint';
        }
        \fwrite(\STDOUT, \sprintf(
            "%-6d %-9.1f %-10.2f %-5d %-4d %s\n",
            (int) $frame['id'],
            (float) $frame['t'],
            (float) $frame['wall'],
            (int) $frame['rebuilt'],
            (int) $frame['causes'],
            $skipped === [] ? '-' : \implode('+', $skipped),
        ));
    }

    \fwrite(\STDOUT, "\n根因（kind=state，次数 / 出现帧数 / 涉及节点 / 调用点）\n");
    if ($hotspots === []) {
        \fwrite(\STDOUT, "  （这段里没有状态写入：静止画面不会产生因果边）\n");
    }
    foreach ($hotspots as $row) {
        \fwrite(\STDOUT, \sprintf(
            "  %-4d %-4d %-4d %s  %s\n",
            (int) $row['writes'],
            (int) $row['frames'],
            (int) $row['nodes'],
            (string) $row['site'],
            (string) $row['detail'],
        ));
    }

    \fwrite(\STDOUT, "\n重建最多（次数 / 自身写入 / 父传播 / 路径）\n");
    if ($nodes === []) {
        \fwrite(\STDOUT, "  （这段里没有节点重建）\n");
    }
    foreach ($nodes as $row) {
        \fwrite(\STDOUT, \sprintf(
            "  %-4d %-4d %-4d %s\n",
            (int) $row['rebuilds'],
            (int) $row['self'],
            (int) $row['inherited'],
            (string) $row['path'],
        ));
    }
}
