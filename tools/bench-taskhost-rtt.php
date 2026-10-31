<?php
declare(strict_types=1);

/**
 * 量 `ProcessTaskHost`（A3）**预热之后**一条任务要多久结算。
 *
 * 存在的理由：`docs/ASYNC-ARCH-2026-10-31.md` §5 缺口 ② 的收益列写着
 * "轻 HTTP 从 275ms 降到 ~50µs"。那两个数字**量纲不同**：
 *   275ms = `spawn`（`probes/RESULTS.md` §12 ⑧，一次性）
 *   50µs  = `poll()` 空队列单次（`probes/io_pool` 的 J6，**不是端到端**）
 * ⇒ 补 ② 之前必须先有"预热之后每条任务到底还欠多少"这个数。本脚本给的就是它。
 *
 * 用法：`php tools/bench-taskhost-rtt.php [条数] [busy|frame]`
 *
 * ## 两种口径（**必须分开量，混着量会得出错误结论**）
 *
 * | 口径 | 怎么等 | 量到的是什么 |
 * |---|---|---|
 * | `busy`（默认） | 不给 worker 留空闲，`drain()` 连着打 | **协议本身**的往返下限 |
 * | `frame` | 每 16.666ms `drain()` 一次（60Hz 的一帧） | **UI 里**用户真正看到的延迟 |
 *
 * ★★ 为什么不能只用 `usleep()` 轮询量（我第一版就是这么错的）：Windows 的
 *   定时器精度是 **15.625ms**，`usleep(100)` 实际睡 15.6ms ⇒ 量出来恒为
 *   "15.6ms 或 31.2ms 的整数倍"，看起来像"协议要 30ms"，其实是**量具的分辨率**。
 *   两次实测（sleep 100µs / 1000µs）都是 30.0ms，才暴露出这一点。
 *
 * ★ 任务本身是 `smoke.sum`（纯整数加法，微秒级）⇒ 差值几乎全是协议开销。
 * ★ **不量 spawn**：`prewarm()` 之后第一次 `flush()` 仍在付它（预热不等就绪）。
 *   所以先跑 3 条热身，把启动账摊掉，再开始计时。
 * ★ 结算必须自己做：`TaskHost::drain()` 只把 `Completion` 交出来，
 *   `resolve()` / `reject()` 在 `PumpHost::complete()` 里（§17.4.2 的分工）。
 */

require __DIR__ . '/autoload.php';

use Drift\App\Completion;
use Drift\App\ProcessTaskHost;
use Drift\Dev\Smoke\App\NamedSumJob;
use Drift\Dev\Smoke\App\SmokeJobRegistry;

$rounds = (int) ($argv[1] ?? 200);
$mode = (string) ($argv[2] ?? 'busy');

/** 60Hz 的一帧（纳秒） */
const FRAME_NS = 16_666_667;

$host = new ProcessTaskHost(
    [\PHP_BINARY, __DIR__ . '/Smoke/App/worker.php'],
    SmokeJobRegistry::make(),
    1,
    5_000,
);

/** 与 `PumpHost::complete()` 同一件事：把 `Completion` 结算到它那个 Future 上 */
$settleCompletion = static function (Completion $completion): void {
    if ($completion->error !== null) {
        $completion->future->reject($completion->error);

        return;
    }

    $completion->future->resolve($completion->value);
};

/**
 * 提交一条并跑到它结算为止。
 *
 * @return array{timeout: bool, error: ?string, frames: int}
 */
$roundtrip = static function (ProcessTaskHost $host, int $to, string $mode) use ($settleCompletion): array {
    $state = ['done' => false, 'error' => null];

    $host->submit(new NamedSumJob($to))
        ->then(static function (mixed $v) use (&$state): void {
            $state['done'] = true;
        })
        ->catch(static function (\Throwable $e) use (&$state): void {
            $state['done'] = true;
            $state['error'] = $e->getMessage();
        });

    $deadline = \hrtime(true) + 10_000_000_000;
    $nextFrame = \hrtime(true);
    $frames = 0;

    while (!$state['done']) {
        $host->drain($settleCompletion);
        $frames++;

        if (\hrtime(true) > $deadline) {
            return ['timeout' => true, 'error' => '10s 未结算', 'frames' => $frames];
        }

        if ($mode === 'frame') {
            // 模拟 60Hz：下一次 drain 排到下一帧的起点
            $nextFrame += FRAME_NS;

            while (\hrtime(true) < $nextFrame) {
                // 忙等 —— 用 usleep 会被 15.625ms 的时钟粒度吃掉（见文件头）
            }
        }
    }

    return ['timeout' => false, 'error' => $state['error'], 'frames' => $frames];
};

$host->prewarm();

// 热身 3 条：把 spawn + 首次 flush 的账摊掉（预热不等就绪 ⇒ 第一条仍要等）
for ($i = 0; $i < 3; $i++) {
    $warm = $roundtrip($host, 10, $mode);

    if ($warm['timeout']) {
        \fwrite(STDERR, "热身失败：{$warm['error']}\n");
        exit(1);
    }
}

$samples = [];
$frameCounts = [];

for ($i = 0; $i < $rounds; $i++) {
    $t0 = \hrtime(true);
    $result = $roundtrip($host, 10 + $i, $mode);
    $t1 = \hrtime(true);

    if ($result['timeout']) {
        \fwrite(STDERR, "第 {$i} 条超时：{$result['error']}\n");
        exit(1);
    }

    if ($result['error'] !== null) {
        \fwrite(STDERR, "第 {$i} 条失败：{$result['error']}\n");
        exit(1);
    }

    $samples[] = ($t1 - $t0) / 1000.0;   // µs
    $frameCounts[] = $result['frames'];
}

\sort($samples);

$n = \count($samples);
$mean = \array_sum($samples) / $n;
$median = $samples[\intdiv($n, 2)];

$label = $mode === 'frame' ? '每帧 drain 一次（60Hz，UI 真实延迟）' : '不给空闲（协议往返下限）';

\printf("A3 · ProcessTaskHost（预热后）· %d 条 `smoke.sum` · %s\n\n", $n, $label);
\printf("  最小 %8.1f µs\n", $samples[0]);
\printf("  中位 %8.1f µs\n", $median);
\printf("  p90  %8.1f µs\n", $samples[(int) \floor($n * 0.9)]);
\printf("  p99  %8.1f µs\n", $samples[(int) \floor($n * 0.99)]);
\printf("  最大 %8.1f µs\n", $samples[$n - 1]);
\printf("  均值 %8.1f µs\n", $mean);
\printf("  平均 drain 次数 %.2f\n", \array_sum($frameCounts) / $n);

\printf("\n★ A2 池能省掉的是**协议往返**这一项，不是攒到结算之前的等待。\n");
\printf("  一次真 HTTP 请求本身 50~200 ms ⇒ 协议往返占端到端 %.3f%% ~ %.3f%%。\n", $median / 50_000 * 100, $median / 200_000 * 100);

$host->shutdown();
