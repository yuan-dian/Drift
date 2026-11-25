#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 录制开销尺：同一棵 2000 节点合成树，量 off / frames / geometry / full 四档。
 *
 * 用法：php tools/recorder-overhead.php [--rows=500] [--frames=30] [--check] [--json]
 */

require __DIR__ . '/autoload.php';

use Drift\Dev\Bench\RecorderOverhead;

$rows = RecorderOverhead::DEFAULT_ROWS;
$frames = RecorderOverhead::DEFAULT_FRAMES;
$check = false;
$json = false;
foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--check') {
        $check = true;
        continue;
    }
    if ($arg === '--json') {
        $json = true;
        continue;
    }
    if (\str_starts_with($arg, '--rows=')) {
        $rows = \max(1, (int) \substr($arg, 7));
        continue;
    }
    if (\str_starts_with($arg, '--frames=')) {
        $frames = \max(1, (int) \substr($arg, 9));
        continue;
    }
    \fwrite(\STDERR, "未知参数：{$arg}\n");
    exit(2);
}

$result = RecorderOverhead::measure($rows, $frames);

if ($json) {
    \fwrite(\STDOUT, (string) \json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n");
} else {
    \printf("%-9s %12s %12s %11s %10s %10s\n", 'mode', 'median(us)', 'vs-off%', 'budget%', 'geoFrames', 'requests');
    foreach ($result as $name => $row) {
        \printf(
            "%-9s %12.2f %11.2f%% %10.3f%% %10d %10d\n",
            $name,
            $row['medianUs'],
            $row['overheadPct'],
            $row['budgetPct'],
            $row['geometryFrames'],
            $row['networkRequests'],
        );
    }
}

if ($check) {
    $overhead = (float) $result['frames']['budgetPct'];
    if ($overhead >= RecorderOverhead::FRAMES_ONLY_BUDGET_PCT) {
        \fwrite(
            \STDERR,
            \sprintf(
                "frames-only 帧预算占用 %.3f%% 超过上限 %.2f%%\n",
                $overhead,
                RecorderOverhead::FRAMES_ONLY_BUDGET_PCT,
            ),
        );
        exit(1);
    }
}

exit(0);
