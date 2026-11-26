<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 两帧结构对比视图：把 `treeDiff()` 的事件流水压成一份可直接读的清单。
 *
 * 只画查询层给的事件与覆盖率，不自己从两棵树相减 —— 那会把 limit 截断误当成
 * "没有变化"。三种事件各有前缀：`+` 出生、`-` 离开、`~` 区间内重建。
 *
 * 头部必须说清两件事：对比的是哪一段（左开右闭）、这份对比看没看全。缺帧记录
 * 或缺寿命两端时，空结果不等于"没变"——第三行会把具体缺口写出来。
 *
 * @internal 证据层视图；`tools/debug-tree-diff.php` 是它唯一的调用方
 */
final class TreeDiff
{
    /** @param array<string, mixed> $answer */
    public static function render(array $answer): array
    {
        $cov = $answer['coverage'] ?? [];
        $base = (int) ($answer['base'] ?? 0);
        $frame = (int) ($answer['frame'] ?? 0);
        $lines = [
            \sprintf(
                '结构对比  (%d, %d]   事件 %d（出生 %d / 离开 %d / 重建 %d）  列出 %d',
                $base,
                $frame,
                (int) ($cov['events'] ?? 0),
                (int) ($cov['born'] ?? 0),
                (int) ($cov['gone'] ?? 0),
                (int) ($cov['rebuilt'] ?? 0),
                (int) ($cov['shown'] ?? 0),
            ),
            self::coverageLine($cov),
            '  父/类型旧值 未记录：移位与改型不在这次对比的口径里',
        ];

        if (isset($answer['reason'])) {
            $lines[] = '  查不到这份对比：' . self::reasonText((string) $answer['reason']);

            return $lines;
        }

        $rows = $answer['rows'] ?? [];
        if ($rows === []) {
            $lines[] = '  （这两帧之间没有结构事件）';

            return $lines;
        }

        foreach ($rows as $row) {
            $lines[] = self::row($row);
        }

        $omitted = (int) ($cov['omitted'] ?? 0);
        if ($omitted > 0) {
            $lines[] = \sprintf('  （还有 %d 条没列；用 --limit=0 看全）', $omitted);
        }

        return $lines;
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): string
    {
        $sign = match ((string) ($row['change'] ?? '')) {
            'born' => '+',
            'gone' => '-',
            'rebuilt' => '~',
            default => '?',
        };
        $type = Fit::clip((string) ($row['type'] ?? ''), 22);
        $path = Fit::tailPath((string) ($row['path'] ?? ''), 44);
        $count = (int) ($row['count'] ?? 1);
        $when = \sprintf('@%d', (int) ($row['at'] ?? 0));
        if ($count > 1) {
            $when .= \sprintf(' ×%d (末@%d)', $count, (int) ($row['lastAt'] ?? 0));
        }
        $flags = '';
        if (($row['evicted'] ?? false) === true) {
            $flags .= ' 已回收';
        }
        if (($row['known'] ?? true) === false) {
            $flags .= ' 名称未知';
        }

        return \sprintf(
            '  %s #%-5d %s %s%s%s',
            $sign,
            (int) ($row['id'] ?? 0),
            Fit::pad($type, 22),
            Fit::pad($when, 12),
            Fit::pad($path, 44),
            $flags,
        );
    }

    /** @param array<string, mixed> $cov */
    private static function coverageLine(array $cov): string
    {
        if (($cov['complete'] ?? false) === true) {
            return '  对比 完整';
        }

        $why = [];
        if (($cov['lifetimeComplete'] ?? false) === false) {
            $birth = (int) ($cov['unknownBirth'] ?? 0);
            $gone = (int) ($cov['unknownGone'] ?? 0);
            if ($birth > 0) {
                $why[] = $birth . ' 个节点出生帧未知';
            }
            if ($gone > 0) {
                $why[] = $gone . ' 个节点离开帧未知';
            }
            if (($cov['goneKnown'] ?? true) === false) {
                $why[] = '几何采集关着，离开事件无从得知';
            }
            if (($cov['withinRetention'] ?? true) === false) {
                $why[] = '起点早于节点记录保留期 #' . (int) ($cov['retentionFloor'] ?? 0);
            }
            if ($why === []) {
                $why[] = '这份证据没有时间轴';
            }
        }
        if (($cov['rebuiltComplete'] ?? false) === false) {
            $why[] = \sprintf('重建区间缺 %d 帧记录', (int) ($cov['framesMissing'] ?? 0));
        }
        if ((int) ($cov['unknownRebuilt'] ?? 0) > 0) {
            $why[] = (int) $cov['unknownRebuilt'] . ' 个重建节点连名字都没了';
        }

        return '  对比 不完整：' . \implode('；', $why) . '（未知的不当成没发生）';
    }

    private static function reasonText(string $reason): string
    {
        return match ($reason) {
            'frame-not-recorded' => '目标帧还没发生（超出录制范围）',
            'invalid-range' => '起点不能在终点之后，也不能为负',
            'timeline-unavailable' => '这份证据没有时间轴',
            default => $reason,
        };
    }
}
