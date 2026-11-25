<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 基线对比的终端呈现：**只列要修的**，其余只计数。
 *
 * ## 为什么持平与修好的不列
 *
 * 一份基线里有几十条发现时，逐条列出"没变的"会把变差的三条淹掉。读者打开这份
 * 报告只问两件事：什么变差了、有没有新出现的。所以 `same` / `better` / `fixed`
 * 只出现在计数行，要看全部去 `--json`。
 *
 * ## 为什么变差的要有上限
 *
 * 上限是 20 行（一屏能看完要修的）。超了就自报"另有 N 条未列"，而不是静默截断
 * —— 一份只显示前 20 条的报告，读者看不出来第 21 条存在。
 *
 * ## 为什么耗时类标 `~` 而不是藏起来
 *
 * "慢了 8%"仍然是信息，只是不足以断言。藏起来等于丢失，标出来并写清"不计断言"
 * 才让读者知道该拿它当参考还是当结论。
 *
 * @internal 证据层投影；`tools/debug-brief.php --baseline` 是它唯一的调用方
 */
final class BaselineText
{
    /** 一屏能看完要修的行数；超出的自报。 */
    private const int MAX_ROWS = 20;

    /** 只计数、不逐条列出的状态。 */
    private const array QUIET = [Baseline::SAME, Baseline::BETTER, Baseline::FIXED];

    private const array MARK = [
        Baseline::WORSE => '▼',
        Baseline::NEW => '+',
        Baseline::RULE_CHANGED => '≠',
        Baseline::UNCOMPARABLE => '?',
        Baseline::SAME => '=',
        Baseline::BETTER => '✓',
        Baseline::FIXED => '✓',
    ];

    /**
     * @param array<string, mixed> $comparison {@see Baseline::compare()}
     * @return array{lines: list<string>, omitted: array<string, int>}
     */
    public static function render(array $comparison, string $source, int $width = 100): array
    {
        $rows = $comparison['rows'] ?? [];

        $shown = [];
        $shortRows = 0;
        foreach ($rows as $row) {
            // 确定性行的持平 / 修好 / 消失只计数；耗时类一律列行（含持平）——
            // 否则计数行说"偏离 2"，正文里一条也看不到，读者不知道该去看哪两行。
            // 这里**不能**再无条件跳过 FIXED：耗时类"消失"同样是信息，
            // 而下面那句自报只解释"为什么不进断言"，不解释"为什么被藏掉"。
            $quiet = \in_array($row['state'], self::QUIET, true)
                && ($row['deterministic'] ?? true) === true;
            if ($quiet) {
                continue;
            }
            if (\count($shown) >= self::MAX_ROWS) {
                ++$shortRows;
                continue;
            }
            $shown[] = $row;
        }

        $lines = self::header($comparison, $source, $width);
        foreach ($shown as $row) {
            $lines[] = self::line($row, $width);
        }

        $tail = self::tail($shortRows, $width);
        if ($tail !== '') {
            $lines[] = $tail;
        }

        return [
            'lines' => $lines,
            'omitted' => ['baseline' => $shortRows],
        ];
    }

    /**
     * @param array<string, mixed> $comparison
     * @return list<string>
     */
    private static function header(array $comparison, string $source, int $width): array
    {
        $tolerance = (float) ($comparison['tolerance'] ?? 0.0);
        // 只显示文件名：一行里塞完整路径会把"容差"挤到看不见，而完整路径
        // 除了确认"我传的是这个"之外没有信息量。
        $name = \basename($source);

        $lines = [Fit::clip(\sprintf('基线 · %s · 容差 %.1f%%', $name, $tolerance), $width)];

        // 计数只用确定性那一桶：耗时类的行另有"偏离"一句，混在这里会重复计数。
        $stable = $comparison['gates']['stable'] ?? [];
        $parts = [
            '回归 ' . (int) ($comparison['regressions'] ?? 0),
            '新增 ' . (int) ($stable[Baseline::NEW] ?? 0),
        ];
        $unstable = (int) ($comparison['unstable'] ?? 0);
        // 只要正文列了耗时类行，就必须自报"不计断言"：`~` 本身不是说明，
        // 读者不知道它算不算结论。原实现只在"偏离 > 0"时报，而耗时类完全可能
        // 全部落在容差内（实测 -4.5% / +2.6%），那时正文有 `~` 行却没有任何解释 ——
        // 一份让人猜符号含义的报告，比没有那一行更糟。
        $timingRows = \array_sum($comparison['gates']['unstable'] ?? []);
        if ($timingRows > 0) {
            $parts[] = $unstable > 0
                ? \sprintf('%d 行耗时类不计断言（偏离 %d）', $timingRows, $unstable)
                : \sprintf('%d 行耗时类不计断言', $timingRows);
        }
        foreach ([Baseline::SAME => '持平', Baseline::BETTER => '修好', Baseline::FIXED => '消失', Baseline::RULE_CHANGED => '换版', Baseline::UNCOMPARABLE => '不可比'] as $state => $label) {
            $n = (int) ($stable[$state] ?? 0);
            if ($n > 0) {
                $parts[] = $label . ' ' . $n;
            }
        }
        $lines[] = Fit::clip('  ' . \implode(' · ', $parts), $width);

        foreach (self::context($comparison, $width) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * 口径差异。它不改变行状态，但读者必须知道 —— 否则一堆"新增"会被当成应用变差。
     *
     * @param array<string, mixed> $comparison
     * @return list<string>
     */
    private static function context(array $comparison, int $width): array
    {
        $out = [];

        $digest = $comparison['digest'] ?? [];
        if (($digest['same'] ?? false) !== true) {
            $out[] = Fit::clip(\sprintf(
                '  指纹 %s → %s：应用或脚本变了，差异可能来自它',
                self::short((string) ($digest['before'] ?? '')),
                self::short((string) ($digest['after'] ?? '')),
            ), $width);
        }

        $changed = $comparison['context']['thresholds'] ?? [];
        if ($changed !== []) {
            $out[] = Fit::clip('  阈值变了：' . \implode(' / ', $changed) . '（新增与消失可能来自口径）', $width);
        }
        if (($comparison['context']['selection'] ?? false) === true) {
            // 不写"这份只跑了其中一部分"：跑得少的那一侧可能是基线，也可能是当前。
            // 猜错方向比不说更糟 —— 读者会去查错的那次运行。
            $out[] = Fit::clip('  规则选择与基线不同：两份跑的不是同一组规则', $width);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function line(array $row, int $width): string
    {
        $mark = ($row['deterministic'] ?? true) ? (self::MARK[$row['state']] ?? '?') : '~';

        // 三段各自定量：地址（去哪看）、变化（差多少）、标题（是什么）。用 `spread`
        // 两段拼会在放不下时先丢右段 —— 而这里被丢掉的正是变化值，只剩形容词。
        $addrWidth = \min(20, \max(10, \intdiv($width, 5)));
        $changeWidth = \min(22, \max(12, \intdiv($width, 5)));
        $titleWidth = \max(8, $width - $addrWidth - $changeWidth - 2);

        return Fit::pad(Fit::clip($mark . ' ' . (string) $row['title'], $titleWidth), $titleWidth)
            . ' ' . Fit::pad(Fit::clip((string) $row['address'], $addrWidth), $addrWidth)
            . ' ' . Fit::pad(Fit::clip(self::change($row), $changeWidth), $changeWidth);
    }

    /** @param array<string, mixed> $row */
    private static function change(array $row): string
    {
        $before = self::num($row['before'] ?? null);
        $after = self::num($row['after'] ?? null);

        if ($before === null && $after === null) {
            return '—';
        }
        if ($before === null) {
            return '— → ' . $after;
        }
        if ($after === null) {
            return $before . ' → —';
        }

        $percent = $row['percent'] ?? null;
        $arrow = $before . ' → ' . $after;

        return $percent === null
            ? $arrow
            : $arrow . ' ' . \sprintf('%+.1f%%', (float) $percent);
    }

    private static function num(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $f = (float) $value;
        if (\abs($f - \round($f)) < 0.005) {
            return (string) (int) \round($f);
        }

        // 去掉末尾的 0：`290.4` 比 `290.40` 好读，而列宽已经固定。
        return \rtrim(\rtrim(\sprintf('%.2f', $f), '0'), '.');
    }

    /**
     * 裁剪账。**只报"没列出来的变差"**，不重复计数行已经说过的持平与修好。
     */
    private static function tail(int $shortRows, int $width): string
    {
        if ($shortRows <= 0) {
            return '';
        }

        return Fit::clip(\sprintf('  另有 %d 条超出一屏未列（--json 取全部）', $shortRows), $width);
    }

    private static function short(string $digest): string
    {
        return $digest === '' ? '—' : \substr($digest, 0, 12);
    }
}
