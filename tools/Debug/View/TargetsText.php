<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 跨窗口视图的终端呈现：一行一个会话，预算不够时先保不可达的那几行。
 *
 * 不可达优先于"发现了问题"——一个连不上的窗口不是"没问题"，把它裁掉会让
 * 读者以为整组都健康。这与 BriefText 先保发现、再保明细是同一条纪律。
 *
 * @internal 手工工具；`tools/debug-targets.php` 是它唯一的调用方
 */
final class TargetsText
{
    /**
     * @param array{targets: list<array<string, mixed>>, summary: array<string, mixed>} $projection
     *
     * @return array{lines: list<string>, omitted: array<string, int>}
     */
    public static function render(array $projection, int $budget = 40, int $width = 100): array
    {
        $summary = $projection['summary'] ?? [];
        $targets = $projection['targets'] ?? [];
        $sev = $summary['bySeverity'] ?? [];

        $head = \sprintf(
            '跨窗口视图：%d 个会话（%d 已连接 / %d 不可达），共 %d 条发现（%d error / %d warning / %d info）',
            (int) ($summary['sessions'] ?? 0),
            (int) ($summary['connected'] ?? 0),
            (int) ($summary['unreachable'] ?? 0),
            (int) ($summary['findings'] ?? 0),
            (int) ($sev['error'] ?? 0),
            (int) ($sev['warning'] ?? 0),
            (int) ($sev['info'] ?? 0),
        );

        // 2 行固定：表头 + 裁剪账。预算再小也留一行会话，不能报出一份空视图。
        $room = \max(1, $budget - 2);
        $keep = [];
        foreach ($targets as $i => $row) {
            if ($row['state'] !== 'ok') {
                $keep[$i] = true;
            }
        }
        foreach ($targets as $i => $row) {
            if (\count($keep) >= $room) {
                break;
            }
            if ($row['state'] === 'ok') {
                $keep[$i] = true;
            }
        }

        $lines = [Fit::clip($head, $width)];
        foreach ($targets as $i => $row) {
            if (!isset($keep[$i])) {
                continue;
            }
            $lines[] = Fit::clip(self::row($row), $width);
        }

        $omitted = \count($targets) - \count($keep);
        $lines[] = $omitted > 0
            ? \sprintf('（显示 %d / 共 %d 个会话；%d 个用 --json 取）', \count($keep), \count($targets), $omitted)
            : \sprintf('（显示 %d / 共 %d 个会话）', \count($keep), \count($targets));

        return ['lines' => $lines, 'omitted' => ['targets' => $omitted]];
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): string
    {
        $id = \substr((string) $row['id'], 0, 8);
        $target = Fit::pad(\substr((string) $row['target'], 0, 14), 14);
        $head = \sprintf(
            '  [%s] %s  %s  pid %-6d',
            $row['state'] === 'ok' ? 'ok' : '!!',
            $id,
            $target,
            (int) $row['pid'],
        );

        if ($row['state'] !== 'ok') {
            return $head . '  不可达：' . (string) ($row['error'] ?? '未知原因');
        }

        $worst = \is_array($row['worst'] ?? null) ? $row['worst'] : null;
        $detail = \sprintf(
            '  帧 %d  发现 %d  最慢 %s  指纹 %s%s',
            (int) $row['frames'],
            (int) $row['findings'],
            $worst === null
                ? '—'
                : \sprintf('f%d:%.1fms', (int) $worst['frame'], (float) $worst['cost']),
            \substr((string) $row['digest'], 0, 8),
            ($row['complete'] ?? false) === true ? '' : '  因果树不完整',
        );

        return $head . $detail;
    }
}
