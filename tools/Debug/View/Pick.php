<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 点选视图：把 `pick(x,y)` 的返回压成可读文本。
 *
 * 纯函数、不采集、不判断：链的顺序、归属与 miss 原因都是查询面给的事实。
 * 特别地，"没有 Element 认领的盒"必须照实显示 —— 略掉它就是替读者假设
 * "这一层不存在"，而那正是自绘 chrome 最常见的情况。
 *
 * @internal 证据层视图；`tools/debug-pick.php` 是它唯一的调用方
 */
final class Pick
{
    /** @param array<string, mixed> $answer `pick()` 的返回 */
    public static function lines(array $answer): array
    {
        $x = (float) ($answer['x'] ?? 0.0);
        $y = (float) ($answer['y'] ?? 0.0);
        $roots = (int) ($answer['roots'] ?? 0);
        $frame = (int) ($answer['liveFrame'] ?? 0);

        if (($answer['found'] ?? false) !== true) {
            return [
                \sprintf(
                    '点选 (%.0f, %.0f) 未命中：%s（%d 棵树，读于第 %d 帧）',
                    $x,
                    $y,
                    (string) ($answer['reason'] ?? 'unknown'),
                    $roots,
                    $frame,
                ),
            ];
        }

        $hit = $answer['hit'] ?? [];
        $lines = [
            \sprintf(
                '点选 (%.0f, %.0f) → 树 #%d/%d（%s），第 %d 帧命中 %s #%s',
                $x,
                $y,
                (int) ($answer['tree'] ?? 0),
                $roots,
                (string) ($answer['scope'] ?? '?'),
                $frame,
                (string) ($hit['widget'] ?? '?'),
                (string) ($hit['node'] ?? '?'),
            ),
        ];

        $depth = 0;
        foreach ($answer['chain'] ?? [] as $link) {
            $lines[] = \sprintf(
                '  %s %s%s',
                \str_repeat('  ', $depth),
                self::link($link),
                $depth === \count($answer['chain']) - 1 ? '  ← 命中' : '',
            );
            $depth++;
        }

        $lines[] = \sprintf(
            '链深 %d 层，其中 %d 层无 Element 认领（沿渲染父链上溯，不冒充父节点）',
            \count($answer['chain'] ?? []),
            (int) ($answer['ownerless'] ?? 0),
        );

        return $lines;
    }

    /** @param array<string, mixed> $link */
    private static function link(array $link): string
    {
        $size = $link['size'] ?? null;
        $geometry = \is_array($size)
            ? \sprintf(' %.0f×%.0f', (float) ($size['width'] ?? 0.0), (float) ($size['height'] ?? 0.0))
            : '';

        return \sprintf(
            '%s%s · %s',
            (string) ($link['box'] ?? '?'),
            $geometry,
            ($link['node'] ?? null) === null
                ? '（无 Element 认领）'
                : \sprintf(
                    '%s #%d%s%s',
                    (string) ($link['widget'] ?? '?'),
                    (int) $link['node'],
                    ($link['state'] ?? null) === null ? '' : ' / State=' . $link['state'],
                    ($link['path'] ?? '') === '' ? '' : ' · ' . $link['path'],
                ),
        );
    }
}
