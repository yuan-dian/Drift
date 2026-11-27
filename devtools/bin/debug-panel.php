#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `drift panel` —— 在一次会话上反复追问：时间轴 + 因果树 + 原因浮层。
 *
 * 这是 D3 传输的第一个**真消费者**。`composer debug` 是批处理：一次跑完，把该问的
 * 都问一遍，退出。想换个角度再问一次，就得重跑一遍页面（挂载 + 几百帧），而两次
 * 运行的时间戳与异步交付顺序不保证相同 —— 于是"这帧为什么慢"与"这棵树为什么重建"
 * 取自两次不同的运行。面板把会话留在原处：挂载一次，按一下问一次。
 *
 * 用法（先跑一段，再在同一个会话里看）：
 *   php devtools/bin/debug-panel.php --page=table --frames=1 --click=400,300
 *   php devtools/bin/debug-panel.php --page=async-table --rows=6 --frames=2 --resolve --hold=3
 *   php devtools/bin/debug-panel.php --page=table --replay=case.json --remote
 *   php devtools/bin/debug-panel.php --page=table --frames=1 --click=400,300 --keys="j,j,enter,w,esc,q"
 *
 * 键（与 `--key=` 同一套词表，所以脚本化与手敲走同一条路径）：
 *   j/k 移动   J/K 翻页   g/G 首尾   ⏎ 展开/下钻   w 原因   esc 关闭/回时间轴
 *   n/N 推 1/10 帧   [ ] 换帧   Tab 切换   ? 帮助   q 退出
 *
 * `--keys` 是**非交互**模式：按键依次施加，只打印最后一屏（`--shots` 则每步一屏）。
 * 它是判据的入口 —— 交互本身也要能被自动化验证，否则"能点"只是手测过。
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\App\Replay;
use Drift\DevTools\Protocol\RemoteSession;
use Drift\Debug\Session\Script;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Host\ProcessTransport;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\View\Panel;

$args = Commands::parseOrExit('panel', $argv);
$target = $args->target();
$page = $args->str('page');
$json = $args->bool('json');
$shots = $args->bool('shots');
$remote = $args->bool('remote');
$frames = $args->int('frames');
$width = $args->float('view-width');
$height = $args->float('height');
$rows = $args->int('rows');
$hold = $args->int('hold');
$at = $args->at();
$replayPath = $args->str('replay');
$keys = $args->str('keys');
$frameArg = $args->has('frame') ? $args->int('frame') : null;
$screenWidth = null;
$screenHeight = null;
$screen = $args->str('screen');
if ($screen !== null) {
    // `宽x高`：屏幕（终端）尺寸，与视口宽高是两件事。
    $parts = \explode('x', \strtolower($screen));
    $screenWidth = \max(20, (int) ($parts[0] ?? 0));
    $screenHeight = \max(6, (int) ($parts[1] ?? 0));
}
$steps = $args->steps();
if ($args->has('save')) {
    \fwrite(\STDERR, "面板不支持 --save：要存脚本请用 composer debug --save=<路径>\n");
    exit(2);
}

// ---------------------------------------------------------------- 脚本：读入

$replay = null;
if ($replayPath !== null) {
    $raw = @\file_get_contents($replayPath);
    if ($raw === false) {
        \fwrite(\STDERR, "读不到脚本：{$replayPath}\n");
        exit(2);
    }
    try {
        $replay = Script::fromJson($raw);
    } catch (\Throwable $e) {
        \fwrite(\STDERR, "脚本不能用（{$replayPath}）：" . $e->getMessage() . "\n");
        exit(2);
    }
    if ($page === null && $target === null) {
        $target = $replay->target;
    }
    // 脚本里记着"目标是路由 id"（`page=1`）⇒ 重放也得按路由 id 建页，
    // 否则组合根会拿一个不存在的类名去找，报的是"类不存在"而不是真正的原因。
    if ($page === null && $replay->metaValue('page') !== null) {
        $page = $replay->target;
    }
    $rows = \max($rows, (int) ($replay->metaValue('rows') ?? 0));
}

$label = $page ?? $target;
if ($label === null) {
    \fwrite(\STDERR, "用法：drift panel <AppClass|路由id> [--page=<路由id>] [--frames=N] [--rows=N]\n");
    \fwrite(\STDERR, "               [--key=Tab] [--text=abc] [--click=x,y[,键]] [--wheel=x,y,格[,h]]\n");
    \fwrite(\STDERR, "               [--hold=N] [--resolve] [--frame=N] [--screen=WxH] [--remote]\n");
    \fwrite(\STDERR, "               [--keys=j,j,enter,w,q [--shots]] [--json]\n");
    exit(2);
}

if ($replay !== null) {
    if ((int) $replay->width !== $width || (int) $replay->height !== $height) {
        $width = \max(1, (int) $replay->width);
        $height = \max(1, (int) $replay->height);
    }
    if ($replay->truncated()) {
        \fwrite(\STDERR, '脚本被截断（超过 ' . Script::MAX_STEPS . " 步），不能重放\n");
        exit(2);
    }
    if ($steps !== []) {
        \fwrite(\STDERR, "重放时不能再给 --key/--text/--click/--wheel/--resolve：动作已经在脚本里\n");
        exit(2);
    }
}

$meta = $args->meta();
$drive = $replay ?? $args->script();

// 屏幕尺寸：命令行 > 环境变量 > 默认。终端里给人看的东西，撑满宽度比"刚好够"好读。
$screenWidth = $screenWidth ?? (\getenv('COLUMNS') !== false ? \max(40, (int) \getenv('COLUMNS')) : 100);
$screenHeight = $screenHeight ?? (\getenv('LINES') !== false ? \max(10, (int) \getenv('LINES')) : 24);

// -------------------------------------------------------------------- 驱动

$session = null;
try {
    if ($remote) {
        $session = new RemoteSession(ProcessTransport::spawn([
            \PHP_BINARY,
            \dirname(__DIR__) . '/bin/debug-host.php',
        ]));
        $mountMs = $session->open($drive->fork());
    } else {
        $opened = Runner::open((string) $label, (float) $width, (float) $height, $meta, $drive->fork());
        $session = $opened['session'];
        $mountMs = $opened['mountMs'];
    }

    $title = \sprintf(
        '%s · %dx%d · 挂载 %.1fms%s',
        (string) $label,
        $width,
        $height,
        $mountMs,
        $remote && $session instanceof RemoteSession && $session->hostPid() !== null
            ? \sprintf(' · 宿主 %d', (int) $session->hostPid())
            : '',
    );

    $panel = new Panel($session, $title, $screenWidth, $screenHeight);

    // 先把计划跑完（这就是"挂载一次"），墙钟逐帧喂给面板 —— 时间轴上的条与
    // `composer debug` 的帧表是同一份测量，不另算一遍。
    Replay::apply($session, $drive, [], $at, static function (int $id, float $ms) use ($panel): void {
        $panel->noteWall($id, $ms);
    });

    $panel->boot($frameArg);

    if ($keys !== null) {
        foreach (\explode(',', $keys) as $token) {
            $token = \trim($token);
            if ($token === '') {
                continue;
            }
            if (!press_token($panel, $token)) {
                break;
            }
            if ($shots) {
                print_screen($panel, true);
            }
        }
        print_screen($panel, false, $json);
    } else {
        // 交互：一屏 → 一行命令 → 一屏。行输入而不是单键：Windows 下把终端切到
        // 非行模式要么靠扩展、要么靠平台 API，而"一次一行"在 ssh / 管道里都一样。
        while (true) {
            print_screen($panel, true);
            \fwrite(\STDOUT, "> ");
            $line = \fgets(\STDIN);
            if ($line === false) {
                break; // EOF：管道喂完了，正常退出
            }
            $line = \trim($line);
            if ($line === '') {
                continue;
            }
            $quit = false;
            foreach (\preg_split('/[\s,]+/', $line) ?: [] as $token) {
                if ($token === '') {
                    continue;
                }
                if (!press_token($panel, $token)) {
                    $quit = true;
                    break;
                }
            }
            if ($quit) {
                break;
            }
        }
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    if ($session !== null) {
        $session->close();
    }
}

exit(0);

// -------------------------------------------------------------------- 渲染

/**
 * 打一屏。`$interactive` 时先清屏（终端里画的是同一块地方）；
 * 管道输出时不清 —— 那会往日志里塞一堆控制字符。
 */
function print_screen(Panel $panel, bool $interactive, bool $json = false): void
{
    if ($json) {
        \fwrite(\STDOUT, \json_encode([
            'tab' => $panel->tab(),
            'frame' => $panel->frame(),
            'cursor' => $panel->cursorKey(),
            'status' => $panel->status(),
            'lines' => $panel->lines(),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT) . "\n");

        return;
    }

    if ($interactive && \function_exists('stream_isatty') && @\stream_isatty(\STDOUT)) {
        // 光标归位 + 清屏。比起逐行覆写，它不会在缩过窗口之后留下残行。
        \fwrite(\STDOUT, "\e[H\e[2J");
    }
    foreach ($panel->lines() as $line) {
        \fwrite(\STDOUT, $line . "\n");
    }
}

/** 一个命令行 token ⇒ 一次（或多次）按键。 */
function press_token(Panel $panel, string $token): bool
{
    $named = [
        'enter' => 'enter', 'return' => 'enter',
        'esc' => 'esc', 'escape' => 'esc',
        'tab' => 'tab',
        'up' => 'up', 'down' => 'down',
        'pgup' => 'pageup', 'pgdn' => 'pagedown',
        'home' => 'home', 'end' => 'end',
        'help' => '?', 'quit' => 'q',
    ];
    $lower = \strtolower($token);
    if (isset($named[$lower])) {
        return $panel->press($named[$lower]);
    }
    if (\strlen($token) === 1) {
        return $panel->press($token);
    }

    // `jjj` 这种连写：一次一个键，遇到退出就停。
    foreach (\str_split($token) as $ch) {
        if (!$panel->press($ch)) {
            return false;
        }
    }

    return true;
}
