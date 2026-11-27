<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

/**
 * `debug a11y` 的文本面（A77）：纯函数，出口只负责 `fwrite`。
 *
 * 不手写对齐列：语义字段里有中文名与 Key，`sprintf('%-Ns')` 数字节会错位。
 * 这里按"一个字段一段"拼，读者扫一眼就知道从哪到哪是一个字段。
 */
final class A11y
{
    /** @return list<string> */
    public static function lines(string $what, array $answer): array
    {
        if (($answer['found'] ?? false) !== true) {
            return ['语义查询：' . self::reason($answer)];
        }

        if ($what === 'tree') {
            $lines = [
                \sprintf(
                    '语义树 · %d 节点 · 帧 #%s · 下标=UIA 快照%s',
                    (int) ($answer['count'] ?? 0),
                    (string) ($answer['liveFrame'] ?? '?'),
                    ($answer['truncated'] ?? false) ? ' · 已截断' : '',
                ),
            ];
            foreach ($answer['rows'] ?? [] as $row) {
                $lines[] = self::row($row);
            }

            return $lines;
        }

        $head = $what === 'hit'
            ? \sprintf('坐标命中 · (%.0f, %.0f) · 下标 %d', (float) ($answer['x'] ?? 0), (float) ($answer['y'] ?? 0), (int) ($answer['index'] ?? -1))
            : \sprintf('语义节点 · 下标 %d', (int) ($answer['index'] ?? -1));

        return [$head, ...self::detail($answer)];
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): string
    {
        $rect = \sprintf(
            '%d,%d %dx%d',
            (int) ($row['x'] ?? 0), (int) ($row['y'] ?? 0), (int) ($row['w'] ?? 0), (int) ($row['h'] ?? 0),
        );

        return \sprintf(
            '  #%d %s/%s [%s] · key=%s · v=%s · %s · %s · rect=%s · parent=%d',
            (int) ($row['index'] ?? -1),
            (string) ($row['role'] ?? ''),
            (string) ($row['type'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['key'] ?? '') === '' ? '—' : (string) ($row['key'] ?? ''),
            (string) ($row['value'] ?? '') === '' ? '—' : (string) ($row['value'] ?? ''),
            ($row['enabled'] ?? false) ? '可用' : '禁用',
            ($row['focused'] ?? false) ? '有焦点' : '无焦点',
            $rect,
            (int) ($row['parent'] ?? -1),
        );
    }

    /** @param array<string, mixed> $row */
    private static function detail(array $row): array
    {
        $lines = [];
        foreach ([
            'role' => '角色', 'type' => '控件', 'name' => '名字', 'value' => '取值',
            'key' => 'Key', 'automationId' => 'AutomationId', 'hint' => '提示',
            'acceleratorKey' => '快捷键', 'level' => '层级',
        ] as $key => $label) {
            $value = (string) ($row[$key] ?? '');
            if ($value !== '') {
                $lines[] = '  ' . $label . '：' . $value;
            }
        }

        $lines[] = \sprintf(
            '  状态：enabled=%d focused=%d focusable=%d select=%d expand=%d',
            (int) (bool) ($row['enabled'] ?? false),
            (int) (bool) ($row['focused'] ?? false),
            (int) (bool) ($row['focusable'] ?? false),
            (int) ($row['selectState'] ?? 0),
            (int) ($row['expandState'] ?? 0),
        );
        $lines[] = \sprintf(
            '  几何：x=%d y=%d w=%d h=%d parent=%d depth=%d',
            (int) ($row['x'] ?? 0), (int) ($row['y'] ?? 0), (int) ($row['w'] ?? 0), (int) ($row['h'] ?? 0),
            (int) ($row['parent'] ?? -1), (int) ($row['depth'] ?? 0),
        );

        $axis = (int) ($row['scrollAxis'] ?? 0);
        if ($axis !== 0) {
            $lines[] = \sprintf(
                '  滚动：axis=%d offset=%.1f max=%.1f viewport=%.1fx%.1f',
                $axis,
                (float) ($row['scrollOffset'] ?? 0), (float) ($row['scrollMax'] ?? 0),
                (float) ($row['scrollViewportW'] ?? 0), (float) ($row['scrollViewportH'] ?? 0),
            );
        }
        $range = (float) ($row['rangeMin'] ?? 0) !== 0.0 || (float) ($row['rangeMax'] ?? 0) !== 0.0;
        if ($range) {
            $lines[] = \sprintf('  区间：%.2f–%.2f', (float) ($row['rangeMin'] ?? 0), (float) ($row['rangeMax'] ?? 0));
        }

        return $lines;
    }

    /** @param array<string, mixed> $answer */
    private static function reason(array $answer): string
    {
        return match ($answer['reason'] ?? null) {
            'semantics-unavailable' => '离线证据包没有活语义树（A77：L2 内省，不进包）',
            'semantics-index-out-of-range' => \sprintf(
                '下标 %d 越界（共 %d 个语义节点）',
                (int) ($answer['index'] ?? -1), (int) ($answer['count'] ?? 0),
            ),
            'no-semantic-hit' => '这个坐标没有命中有角色的控件',
            default => (string) ($answer['reason'] ?? '未知原因'),
        };
    }
}
