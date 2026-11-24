<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 「重开 N 次」的读数：一张表 + 一句结论 + 两段分布。
 *
 * ## 为什么结论要单独占一行
 *
 * 表里的 digest 是 12 位十六进制，人眼比不出来 —— 三行完全一样的哈希看起来
 * 就像"应该有区别但我看不出来"。结论行把它翻译成一句话，而这句话只能是
 * 两种之一：「逐值相同」或「第 K 轮从第几帧起不同」。中间态（"差不多"）不存在。
 *
 * ## 为什么性能与内存要单独一段
 *
 * 它们**不参与判定**（见 `Reopen` 的注释）：挂载 3ms 还是 4ms 是这台机器的运气，
 * 而"第 2 帧因果不同"是代码的问题。混在同一张表里会让人以为两者是同一种证据。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class LoopReport
{
    /** 列宽：中文按 `mb_strwidth` 算两列，所以对齐必须走 {@see Fit::pad} 而不是 `sprintf`。 */
    private const array COLS = [2, 9, 12, 4, 3, 8, 8];

    /**
     * @param array<string, mixed> $report {@see \Drift\Dev\Debug\Reopen::run()}
     *
     * @return list<string>
     */
    public static function lines(string $label, array $report): array
    {
        /** @var list<array<string, mixed>> $runs */
        $runs = $report['runs'];
        $perRun = $runs === [] ? 0 : (int) $runs[0]['frameCount'];

        $lines = [\sprintf('重开 %d 次 · %s · 每轮 %d 帧', \count($runs), $label, $perRun), ''];

        $lines[] = '  ' . self::row(['轮', '会话', 'digest', '节点', '帧', '挂载', '首帧']);
        foreach ($runs as $row) {
            $lines[] = '  ' . self::row([
                (string) $row['round'],
                self::shortSession((string) $row['session']),
                \substr((string) $row['digest'], 0, 12),
                (string) $row['nodeCount'],
                (string) $row['frameCount'],
                \sprintf('%.1fms', $row['mountMs']),
                \sprintf('%.2fms', $row['firstFrameMs']),
            ]);
        }

        $lines[] = '';
        foreach (self::verdict($report) as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $mount = $report['mount'];
        $firstFrame = $report['firstFrame'];
        $memory = $report['memory'];
        $lines[] = \sprintf('  挂载   中位 %.1fms   极差 %.1fms', $mount['median'], $mount['spread']);
        $lines[] = \sprintf('  首帧   中位 %.2fms   极差 %.2fms', $firstFrame['median'], $firstFrame['spread']);

        // 首轮总是慢一截：类加载、JIT、autoload 都在第一次付。按首轮判断"变慢了"
        // 是最常见的误读，而"看中位"这件事必须写在报告里，不能指望人记得。
        if (\count($runs) > 1 && $runs[0]['mountMs'] > $mount['median'] * 1.5) {
            $lines[] = '         （首轮含类加载，判断变慢不慢请看中位）';
        }

        $delta = $memory['last'] - $memory['first'];
        $lines[] = \sprintf(
            '  内存   %.3f → %.3f MB（GC 后，%+.0f KB）%s',
            $memory['first'] / 1048576,
            $memory['last'] / 1048576,
            $delta / 1024,
            // 只在"整体涨了 1% 以上又逐轮上升"时才提一句：三轮涨 60KB 是噪声，
            // 而把它标成警告会让人去查一个不存在的问题。
            $memory['rising'] && $memory['deltaPercent'] > 1.0 ? '  逐轮上升' : '',
        );

        return $lines;
    }

    /**
     * 数字列右对齐，文字列左对齐 —— 一列数字左对齐时对不齐个位，而个位正是要看的那位。
     *
     * @param list<string> $cells
     */
    private static function row(array $cells): string
    {
        $out = [];
        foreach ($cells as $i => $cell) {
            $width = self::COLS[$i];
            $out[] = $i === 0 || $i === 1 || $i === 2
                ? Fit::pad($cell, $width)
                : \str_repeat(' ', \max(0, $width - Fit::width($cell))) . $cell;
        }

        return \rtrim(\implode('  ', $out));
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private static function verdict(array $report): array
    {
        /** @var list<array<string, mixed>> $runs */
        $runs = $report['runs'];

        if (\count($runs) < 2) {
            // 单轮不比，也不打勾：没有可比对象时的"相同"是空话。
            return ['  — 只跑了 1 轮，没有可比对象'];
        }

        $divergence = $report['divergence'];
        if ($divergence === null) {
            $lines = [\sprintf('  ✓ %d 轮因果与结构逐值相同', \count($runs))];
            // 只有一轮之外的证据：令牌各不相同，说明真的重建了。少了这句，
            // "两轮相同"随时可能是"两轮是同一棵树"。
            $sessions = \array_unique(\array_column($runs, 'session'));
            $lines[] = \sprintf(
                '  会话令牌 %d 个互不相同 —— 确实重建了 %d 次',
                \count($sessions),
                \count($runs),
            );

            return $lines;
        }

        $lines = [\sprintf('  ✗ 第 %d 轮与第 1 轮不同', $divergence['round'])];
        $lines[] = '      ' . $divergence['detail'];
        $total = \count($runs);
        $bad = \count($divergence['rounds']);
        if ($bad < $total - 1) {
            // "只有某一轮不同"与"从某一轮起都不对"是两件事：前者像偶发，
            // 后者像状态延续。这句话区分它们。
            $lines[] = \sprintf(
                '      另有 %d 轮与第 1 轮相同 —— 一致性是断在第 %d 轮，不是从头就分叉',
                $total - 1 - $bad,
                $divergence['round'],
            );
        }

        return $lines;
    }

    /** 令牌够区分前 8 位；整串放进来会把表撑破，而对人没有更多信息。 */
    private static function shortSession(string $session): string
    {
        return $session === '' ? '—' : \substr($session, 0, 8) . '…';
    }
}
