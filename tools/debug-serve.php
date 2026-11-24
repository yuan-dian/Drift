#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 调试**常驻宿主**：自己按脚本推帧，同时开一个 loopback 端口等人来看。
 *
 * 与 {@see \Drift\Dev\Debug\Host} 的分工：`Host` 只管"消息进、响应出、空闲时推一步"，
 * 而"推哪一步、隔多久推"是这一层的事（`--speed` / `--at` 是本命令的词）。
 *
 * ## 时间从脚本里来，不从墙钟来
 *
 * `--speed` 只改**两次 `pump()` 之间等多久**，不改喂进去的 `t` —— `t` 永远是脚本里
 * 写的那个值。所以 `--speed=0`（尽快）与 `--speed=1`（实时）跑同一个脚本，记录内容
 * 逐字节相同，只有墙钟耗时不同。反过来，拿 `now()` 当 `t` 的自走，无论多快都不可判。
 *
 * ## 跑完之后不退进程
 *
 * 脚本跑完只把状态改成 `finished`（`--at` 停的是 `paused` + `reason=at`），然后阻塞
 * 等人连上来查。进程退出的时机是"最后一个观察者走了"——宿主先退的话，发现文件里的
 * 条目就成了死的，而观察者看到的会是"连接被拒绝"。
 *
 * 用法：
 *   php tools/debug-serve.php --page=table --frames=1
 *   php tools/debug-serve.php --page=async-table --rows=6 --resolve --speed=1
 *   php tools/debug-serve.php --page=table --frames=30 --at=10 --click=400,300
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/autoload.php';

use Drift\App\DebugSession;
use Drift\App\Replay;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;
use Drift\Dev\Debug\Discovery;
use Drift\Dev\Debug\Host;
use Drift\Dev\Debug\OutputGuard;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Debug\SocketServer;

$target = null;
$page = null;
$frames = 1;
$width = 800.0;
$height = 600.0;
$rows = 0;
$hold = 1;
$at = null;
$speed = 0.0;
/** @var list<array{0: 'key'|'text'|'click'|'wheel'|'resolve', 1: string}> */
$steps = [];

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--resolve') {
        $steps[] = ['resolve', ''];
    } elseif (\str_starts_with($arg, '--frames=')) {
        $frames = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--view-width=')) {
        $width = (float) \max(1, (int) \substr($arg, 13));
    } elseif (\str_starts_with($arg, '--height=')) {
        $height = (float) \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--rows=')) {
        $rows = \max(0, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--hold=')) {
        $hold = \max(1, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--at=')) {
        $at = \max(1, (int) \substr($arg, 5));
    } elseif (\str_starts_with($arg, '--speed=')) {
        $speed = \max(0.0, (float) \substr($arg, 8));
    } elseif (\str_starts_with($arg, '--page=')) {
        $page = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--key=')) {
        $steps[] = ['key', \substr($arg, 6)];
    } elseif (\str_starts_with($arg, '--text=')) {
        $steps[] = ['text', \substr($arg, 7)];
    } elseif (\str_starts_with($arg, '--click=')) {
        $steps[] = ['click', \substr($arg, 8)];
    } elseif (\str_starts_with($arg, '--wheel=')) {
        $steps[] = ['wheel', \substr($arg, 8)];
    } elseif (\str_starts_with($arg, '--')) {
        \fwrite(\STDERR, "未知参数：{$arg}\n");
        exit(2);
    } else {
        $target = $arg;
    }
}

$label = $page ?? $target;
if ($label === null) {
    \fwrite(\STDERR, "用法：debug-serve <AppClass|路由id> [--page=<路由id>] [--frames=N] [--at=N] [--speed=N]\n");
    \fwrite(\STDERR, "      debug-serve --page=async-table --rows=6 --resolve --speed=1\n");
    exit(2);
}
if ($at !== null && $at > $frames) {
    // 不报错：脚本本来就只跑 frames 帧，`--at` 大于它等于没给。但要说一声，
    // 否则"我明明 --at=30 怎么只到 1 帧"会被当成 bug 查半天。
    \fwrite(\STDERR, "--at={$at} 大于 --frames={$frames}，脚本跑完就停，不会再往后推\n");
}

$meta = $rows > 0 ? ['rows' => $rows] : [];
if ($page !== null) {
    $meta['page'] = 1;
}

/**
 * 一次运行的全部驱动步骤。
 *
 * 与 `brief` 不同的是**先成脚本、再逐步喂**：`Plan` 已经把动作与帧写成了带时间戳的
 * 序列，这里只负责"往会话里送"，不重新解释动作。
 */
$script = Plan::build((string) $label, $width, $height, $meta, $frames, $hold, $steps);
$queue = $script->steps();

$index = 0;
$pushed = 0;
/** @var null|array{0: float, 1: float} $pace 脚本首帧的 `t` 与当时的墙钟，限速的锚点 */
$pace = null;

$tick = static function (DebugSession $session) use ($queue, &$index, &$pushed, &$pace, $speed, $at): bool {
    $total = \count($queue);

    while ($index < $total) {
        $step = $queue[$index];
        $isFrame = $step->kind === Step::FRAME;

        if ($isFrame && $at !== null && $pushed >= $at) {
            // 到点了不是"跑完了"：观察者这会儿正该来看这一帧，而"一会儿还动"是
            // 另一件事（`--at` 与客户端暂停的区别就在这里，见 DESIGN-EVIDENCE-B2 §5.2）。
            $session->markState('paused', 'at');

            return false;
        }

        // 限速只对帧生效：输入派发没有时长，等它等于凭空插一段延迟。
        if ($isFrame && $speed > 0.0 && $step->t !== null) {
            $now = \hrtime(true) / 1e6;
            $pace ??= [$step->t, $now];
            $due = $pace[1] + ($step->t - $pace[0]) / $speed;
            $waitMs = $due - $now;
            if ($waitMs > 0.5) {
                // 单次最多 4ms：16ms 一帧也就问 4 次，够宿主插进来收消息。
                \usleep((int) (\min($waitMs, 4.0) * 1000));

                // 还没到点：把控制权交回去，宿主下一轮先看看有没有人说话。
                return true;
            }
        }

        try {
            Replay::advance($session, $step, $index + 1);
        } catch (\Throwable $e) {
            // 崩了也要留着会话：观察者还得能查出"崩之前录到了什么"。
            $session->markState('crashed', 'exception');
            \fwrite(\STDERR, '驱动失败：' . $e->getMessage() . "\n");

            return false;
        }

        ++$index;
        if ($isFrame) {
            ++$pushed;
        }
    }

    // 脚本跑完= `finished`。它和 `crashed` 必须分开：一个是"看吧，就是这样"，
    // 另一个是"这里有问题"。混成一个状态，观察者就分不清该信哪份记录。
    $session->markState('finished');

    return false;
};

$server = new SocketServer(0);
$token = \bin2hex(\random_bytes(16));
$discovery = Discovery::inDirectory((string) \getcwd());

OutputGuard::start();

$id = $discovery->register($script->target, $token, $server->port());
\fwrite(\STDERR, \sprintf(
    "会话已就绪：%s（pid %d，端口 %d）\n连上去看：drift debug brief --attach\n",
    $discovery->path(),
    (int) \getmypid(),
    $server->port(),
));

try {
    $host = new Host($server, $token);
    $host->serve(
        static fn(Script $skeleton): array => Runner::open(
            $skeleton->target,
            $skeleton->width,
            $skeleton->height,
            $skeleton->meta(),
            $skeleton,
        ),
        $tick,
        // 开局脚本：自走宿主没有客户端来发 open，会话得自己先建起来。
        $script,
    );
} catch (\Throwable $e) {
    \fwrite(\STDERR, '宿主异常退出：' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $discovery->unregister($id);
    $server->close();
    OutputGuard::stop();
}

exit(0);
