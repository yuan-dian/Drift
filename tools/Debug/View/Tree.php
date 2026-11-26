<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 结构树视图：把 `tree()` 的扁平先序行压成缩进文本。
 *
 * 只画查询层给的深度与标记，不自己走 parent 指针 —— 同一份证据在 CLI 与 Web
 * 上必须长出同一棵树。几何查不到时空着，不把"没有这一帧的几何"写成"没有盒"。
 *
 * @internal 证据层视图；`tools/debug-tree.php` 是它唯一的调用方
 */
final class Tree
{
    /** @param array<string, mixed> $answer */
    public static function render(array $answer): array
    {
        $cov = $answer['coverage'] ?? [];
        $lines = [
            \sprintf(
                '结构树  活动 %d / 已回收 %d / 根 %d / 无盒 %d / 未解析 %d / 孤儿 %d / 环 %d',
                (int) ($cov['active'] ?? 0),
                (int) ($cov['evicted'] ?? 0),
                (int) ($cov['roots'] ?? 0),
                (int) ($cov['boxless'] ?? 0),
                (int) ($cov['unresolved'] ?? 0),
                (int) ($cov['orphan'] ?? 0),
                (int) ($cov['cycle'] ?? 0),
            ),
            \sprintf(
                '  几何 frame=%d effective=%d%s%s',
                (int) ($cov['geometryFrame'] ?? 0),
                (int) ($cov['geometryEffective'] ?? 0),
                ($cov['geometryStale'] ?? false) === true ? '（沿用旧记录）' : '',
                (string) ($cov['geometryReason'] ?? '') === ''
                    ? ''
                    : '（' . (string) $cov['geometryReason'] . '）',
            ),
        ];

        if (isset($answer['reason'])) {
            $lines[] = '  查不到这棵树：' . (string) $answer['reason'];

            return $lines;
        }

        $rows = $answer['rows'] ?? [];
        if ($rows === []) {
            $lines[] = '  （节点表是空的；还没采到树）';

            return $lines;
        }

        foreach ($rows as $row) {
            $depth = \max(0, (int) ($row['depth'] ?? 0));
            $geo = $row['geometry'] ?? null;
            $size = '';
            if (\is_array($geo)) {
                $w = (float) (($geo['size'] ?? [])['w'] ?? 0.0);
                $h = (float) (($geo['size'] ?? [])['h'] ?? 0.0);
                $size = \sprintf(' %gx%g', $w, $h);
            }
            $flag = (string) ($row['flag'] ?? 'normal');
            $flag = $flag === 'normal' ? '' : " [{$flag}]";
            $stale = ($row['stale'] ?? false) === true ? ' stale' : '';
            $lines[] = \sprintf(
                '%s#%-5d %s 子%-3d %s%s%s%s',
                \str_repeat('  ', $depth),
                (int) ($row['id'] ?? 0),
                Fit::pad(Fit::clip((string) ($row['type'] ?? ''), 22), 22),
                (int) ($row['children'] ?? 0),
                ($row['boxed'] ?? false) === true ? '盒' : '无盒',
                $size,
                $flag,
                $stale,
            );
        }

        $shown = \count($rows);
        $omitted = [
            'depth' => (int) ($cov['omittedDepth'] ?? 0),
            'limit' => (int) ($cov['omittedLimit'] ?? 0),
        ];
        if ($omitted['depth'] > 0 || $omitted['limit'] > 0) {
            $lines[] = \sprintf(
                '  （列出 %d 行；深度截断 %d，条数截断 %d）',
                $shown,
                $omitted['depth'],
                $omitted['limit'],
            );
        }

        return $lines;
    }
}
