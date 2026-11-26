<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 异步视图：把 `async()` 的时间线压成可读文本。
 *
 * 只展示查询层给出的标量：后端 / 状态 / 帧号 / 耗时 / 发起点。视图不猜 worker
 * 内部时间，也不把"没开采集"的空表说成"没有任务"。
 *
 * @internal 证据层视图；`tools/debug-async.php` 是它唯一的调用方
 */
final class Async
{
    /** @param array<string, mixed> $answer */
    public static function timeline(array $answer): array
    {
        $window = $answer['window'] ?? [];
        $captured = ($answer['captured'] ?? false) === true;
        $total = (int) ($answer['total'] ?? 0);
        $rows = $answer['rows'] ?? [];
        $shown = \count($rows);
        $lines = [
            \sprintf(
                '异步时间线  %d 条（窗口 %d..%d / 容量 %d / 在途 %d）%s',
                $total,
                (int) ($window['from'] ?? 0),
                (int) ($window['to'] ?? 0),
                (int) ($window['capacity'] ?? 0),
                (int) ($window['inFlight'] ?? 0),
                $shown < $total ? "，本次只列 {$shown} 条" : '',
            ),
        ];

        if (!$captured) {
            $lines[] = '  （未开启异步采集；用 --async 显式开。空白不是"没有任务"）';

            return $lines;
        }
        if ($shown === 0) {
            $lines[] = '  （这段证据没有后端调度过任务；await / sleep 不在这里）';

            return $lines;
        }

        // 表头不手写：手写过的那一版把 `ID` / `后端` / `任务` 的宽度按字节数着补空格，
        // 状态列一出现中文（`在飞`）就整行右移。列宽现在由 `Table` 按显示宽度算。
        $cols = [
            ['t' => '#', 'r' => true],
            ['t' => '后端'],
            ['t' => '任务', 'w' => 22],
            ['t' => '提交', 'r' => true],
            ['t' => '派发', 'r' => true],
            ['t' => '结算', 'r' => true],
            ['t' => '队列/等待'],
            ['t' => '状态'],
            ['t' => '耗时', 'r' => true],
            ['t' => '来源'],
        ];
        $table = [];
        foreach ($rows as $row) {
            $table[] = [
                (string) (int) ($row['id'] ?? 0),
                (string) ($row['backend'] ?? ''),
                (string) ($row['job'] ?? ''),
                (string) (int) ($row['submittedAtFrame'] ?? 0),
                (string) (int) ($row['startedAtFrame'] ?? 0),
                (string) (int) ($row['settledAtFrame'] ?? 0),
                \sprintf('%d/%d', (int) ($row['queueFrames'] ?? 0), (int) ($row['pendingFrames'] ?? 0)),
                (string) ($row['state'] ?? ''),
                \sprintf('%.1fms', (float) ($row['elapsedMs'] ?? 0.0)),
                (string) ($row['initiator'] ?? ''),
            ];
        }
        foreach (Table::render($cols, $table) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /** @param array<string, mixed> $answer */
    public static function task(array $answer): array
    {
        $job = (int) ($answer['rows'][0]['id'] ?? 0);
        if (($answer['total'] ?? 0) < 1) {
            $window = $answer['window'] ?? [];

            return [\sprintf(
                '任务查不到：%s（窗口 %d..%d / 容量 %d，已记 %d 条）',
                (string) ($answer['miss'] ?? 'unknown-job'),
                (int) ($window['from'] ?? 0),
                (int) ($window['to'] ?? 0),
                (int) ($window['capacity'] ?? 0),
                (int) ($window['jobs'] ?? 0),
            )];
        }

        $row = $answer['rows'][0];
        $lines = [
            "任务 #{$job} " . (string) ($row['backend'] ?? '') . ' / ' . (string) ($row['job'] ?? ''),
            \sprintf(
                '  帧：提交 #%d → 派发 #%d → 结算 #%s；队列 %d 帧，等待 %d 帧',
                (int) ($row['submittedAtFrame'] ?? 0),
                (int) ($row['startedAtFrame'] ?? 0),
                (int) ($row['settledAtFrame'] ?? 0) > 0 ? '#' . (int) $row['settledAtFrame'] : '在飞',
                (int) ($row['queueFrames'] ?? 0),
                (int) ($row['pendingFrames'] ?? 0),
            ),
            \sprintf(
                '  状态：%s%s；耗时 %.1fms；worker %d',
                (string) ($row['state'] ?? ''),
                (string) ($row['code'] ?? '') === '' ? '' : '（' . (string) $row['code'] . '）',
                (float) ($row['elapsedMs'] ?? 0.0),
                (int) ($row['worker'] ?? 0),
            ),
            '  发起：' . (string) ($row['initiator'] ?? ''),
        ];
        $landed = $row['landed'] ?? [];
        $lines[] = $landed === []
            ? '  落地：这一帧没有 state 写入归到本任务'
            : '  落地：node:' . \implode(', node:', \array_map('\intval', $landed));

        return $lines;
    }
}
