<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

/**
 * 结构树视图：把 `tree()` 的扁平先序行压成缩进文本。
 *
 * 只画查询层给的深度与标记，不自己走 parent 指针 —— 同一份证据在 CLI 与 Web
 * 上必须长出同一棵树。几何查不到时空着，不把"没有这一帧的几何"写成"没有盒"。
 *
 * ## 头两行必须先说清"这是哪一帧、可不可信"
 *
 * 树本身没有任何一处能自证它是回放还是现状。少了这两行，读者会把"第 3 帧的树"
 * 当成"现在的树"，或者反过来。所以头行给回放帧号，第二行给可回放性
 * （`lifecycle.complete`）—— 不完整时要说清是哪一种不完整，而不是打个"警告"了事。
 *
 * @internal 证据层视图；`devtools/bin/debug-tree.php` 是它唯一的调用方
 */
final class Tree
{
    /** @param array<string, mixed> $answer */
    public static function render(array $answer): array
    {
        $cov = $answer['coverage'] ?? [];
        $life = $cov['lifecycle'] ?? [];
        $lines = [
            \sprintf(
                '结构树  回放 #%d   节点 %d（已回收 %d）  根 %d / 无盒 %d / 未解析 %d / 孤儿 %d / 环 %d',
                (int) ($answer['frame'] ?? 0),
                (int) ($cov['active'] ?? 0),
                (int) ($cov['evicted'] ?? 0),
                (int) ($cov['roots'] ?? 0),
                (int) ($cov['boxless'] ?? 0),
                (int) ($cov['unresolved'] ?? 0),
                (int) ($cov['orphan'] ?? 0),
                (int) ($cov['cycle'] ?? 0),
            ),
            self::geometryLine($answer, $cov),
            self::lifecycleLine($life),
        ];

        if (isset($answer['reason'])) {
            $lines[] = '  查不到这棵树：' . self::reasonText((string) $answer['reason']);

            return $lines;
        }

        $rows = $answer['rows'] ?? [];
        if ($rows === []) {
            $lines[] = '  （这一帧树上没有节点）';

            return $lines;
        }

        foreach ($rows as $row) {
            $lines[] = self::row($row);
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

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): string
    {
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

        // 这一帧重建过就打一个记号。它是**回放的核心事实**：树回答"长什么样"，
        // 这个记号回答"这一帧动了哪儿"。`null`（帧记录不在窗口里）什么也不打，
        // 不能画成"没重建"。
        //
        // 不印 `stale`：它的定义是"距上次重建超过 0 帧"，几乎每一行都成立（静止
        // 帧里全部成立），一整片 `stale` 等于没有信息，还会把真正的 `重建` 淹没。
        // 字段仍留在 JSON 里，给需要"多久没动"的消费者。
        $built = ($row['rebuilt'] ?? null) === true ? ' 重建' : '';

        // 已经不在注册表里、但这一帧还活着。不标出来，读者会拿它当现存节点去搜，
        // 然后在 `node --node=N` 那里撞上 "unknown-node"。
        $gone = ($row['evicted'] ?? false) === true
            ? \sprintf(' 已回收(生@%s 没@%s)', self::frame($row['bornAt'] ?? null), self::frame($row['goneAt'] ?? null))
            : '';

        return \sprintf(
            '%s#%-5d %s 子%-3d %s%s%s%s%s',
            \str_repeat('  ', $depth),
            (int) ($row['id'] ?? 0),
            Fit::pad(Fit::clip((string) ($row['type'] ?? ''), 22), 22),
            (int) ($row['children'] ?? 0),
            ($row['boxed'] ?? false) === true ? '盒' : '无盒',
            $size,
            $flag,
            $built,
            $gone,
        );
    }

    /** @param array<string, mixed> $cov */
    private static function geometryLine(array $answer, array $cov): string
    {
        $geo = (int) ($cov['geometryFrame'] ?? 0);
        $effective = (int) ($cov['geometryEffective'] ?? 0);
        $reason = (string) ($cov['geometryReason'] ?? '');
        $stale = ($cov['geometryStale'] ?? false) === true ? '（沿用旧记录）' : '';

        // 结构帧与几何帧不是一回事：几何只有 60 帧窗口且"有变化才记"。两者不同时
        // 必须写出来，否则"#12 的树配着 #1 的尺寸"看起来像是同一帧的东西。
        $mismatch = $geo > 0 && $geo !== (int) ($answer['frame'] ?? 0)
            ? \sprintf('（结构帧 #%d 没有几何采样，尺寸取最近一次）', (int) ($answer['frame'] ?? 0))
            : '';

        return \sprintf(
            '  几何 frame=%d effective=%d%s%s%s',
            $geo,
            $effective,
            $stale,
            $reason === '' ? '' : '（' . self::reasonText($reason) . '）',
            $mismatch,
        );
    }

    /** @param array<string, mixed> $life */
    private static function lifecycleLine(array $life): string
    {
        if ($life === []) {
            return '  生命周期 未记录（这份证据没有节点寿命，回放不可信）';
        }

        $recycled = (int) ($life['recycled'] ?? 0);
        $tail = $recycled > 0 ? \sprintf('，画出已回收 %d 个', $recycled) : '';

        if (($life['complete'] ?? false) === true) {
            return '  生命周期 完整' . $tail;
        }

        $why = [];
        if ((int) ($life['unknownBirth'] ?? 0) > 0) {
            $why[] = \sprintf('%d 个节点没有出生帧（旧证据包）', (int) $life['unknownBirth']);
        }
        if ((int) ($life['unknownGone'] ?? 0) > 0) {
            $why[] = \sprintf('%d 个已回收节点没有离开帧', (int) $life['unknownGone']);
        }
        if (($life['goneKnown'] ?? true) === false) {
            $why[] = '几何采集关着，谁什么时候离开无从得知';
        }
        if ($why === []) {
            $why[] = '这份证据没有时间轴';
        }

        return '  生命周期 不完整：' . \implode('；', $why) . '（未知的按"那时还在"保留）' . $tail;
    }

    private static function reasonText(string $reason): string
    {
        return match ($reason) {
            'node-not-born' => '这一帧它还没出生',
            'node-gone' => '这一帧它已经离开树了',
            'node-evicted' => '节点已回收',
            'unknown-node' => '从来没有发过这个号',
            'frame-not-recorded' => '这一帧还没发生（超出录制范围）',
            'frame-out-of-window' => '几何窗口之外',
            default => $reason,
        };
    }

    private static function frame(mixed $value): string
    {
        return $value === null || (int) $value <= 0 ? '?' : (string) (int) $value;
    }
}
