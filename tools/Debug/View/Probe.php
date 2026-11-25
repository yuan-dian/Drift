<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * dev 探针的读数：内存存活跨度 / 源码位置（A55 L2，不是证据）。
 *
 * 两视图都带一行"不是证据"的免责：内存是进程级 + 当下活树，源码位置是当场
 * 反射已加载类；两者都离开当下就复算不了，读者不该拿它去解释导出的证据包。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Probe
{
    /** @param array<array-key, mixed> $answer @return list<string> */
    public static function lines(string $what, array $answer): array
    {
        return $what === 'source' ? self::source($answer) : self::memory($answer);
    }

    /** @param array<array-key, mixed> $a @return list<string> */
    private static function memory(array $a): array
    {
        if (($a['found'] ?? false) !== true) {
            return ['内存内省不可用：' . (string) ($a['reason'] ?? 'unknown')];
        }
        $process = \is_array($a['process'] ?? null) ? $a['process'] : [];
        $gc = \is_array($process['gc'] ?? null) ? $process['gc'] : [];
        $live = \is_array($a['live'] ?? null) ? $a['live'] : [];
        $rows = \is_array($live['rows'] ?? null) ? $live['rows'] : [];

        $lines = [
            \sprintf(
                '内存 · 第 %d 帧  usage=%s  peak=%s  GC(roots=%d, collected=%d, threshold=%d)%s',
                (int) ($a['frame'] ?? 0),
                self::bytes((int) ($process['usage'] ?? 0)),
                self::bytes((int) ($process['peak'] ?? 0)),
                (int) ($gc['roots'] ?? 0),
                (int) ($gc['collected'] ?? 0),
                (int) ($gc['threshold'] ?? 0),
                ($gc['running'] ?? false) === true ? ' [GC运行中]' : '',
            ),
            \sprintf(
                '活树：%d 对象 / %d 元素 / %d 类%s',
                (int) ($live['objects'] ?? 0),
                (int) ($live['elements'] ?? 0),
                (int) ($live['types'] ?? 0),
                ((int) ($live['omitted'] ?? 0)) > 0 ? '（另 ' . (int) $live['omitted'] . ' 类未列）' : '',
            ),
            '类型                           数量    最老存活(帧)  本帧新增',
        ];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $lines[] = \sprintf(
                '%-12s %-10s %7d  %8d',
                \substr((string) ($row['kind'] ?? ''), 0, 12) . '/' . \substr((string) ($row['type'] ?? ''), 0, 24),
                (string) ($row['objects'] ?? 0),
                (int) ($row['maxAge'] ?? 0),
                (int) ($row['fresh'] ?? 0),
            );
        }
        $lines[] = '  （存活跨度 = 当前帧 − 首次见到帧；进程级数字含同进程其他窗口。不是证据、不进包）';

        return $lines;
    }

    /** @param array<array-key, mixed> $a @return list<string> */
    private static function source(array $a): array
    {
        $site = (string) ($a['site'] ?? '');
        if (($a['found'] ?? false) !== true) {
            return [
                '源码位置 · ' . $site,
                '  未解析：' . (string) ($a['reason'] ?? 'unknown') . '（扫了 ' . (int) ($a['scanned'] ?? 0) . ' 个方法）',
            ];
        }

        return [
            '源码位置 · ' . $site,
            '  方法：' . (string) ($a['method'] ?? ''),
            '  定义：' . (string) ($a['definedAt'] ?? '') . '（第 ' . (int) ($a['startLine'] ?? 0) . '–' . (int) ($a['endLine'] ?? 0) . ' 行）',
            '  扫描：' . (int) ($a['scanned'] ?? 0) . ' 个方法（只看已加载类）。当场反射、不是证据、不进包',
        ];
    }

    private static function bytes(int $n): string
    {
        if ($n < 1024) {
            return $n . 'B';
        }
        if ($n < 1048576) {
            return \sprintf('%.1fKB', $n / 1024);
        }

        return \sprintf('%.1fMB', $n / 1048576);
    }
}
