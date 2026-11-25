<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 搜索结果的文本视图：把 `find()` 的答案排成"一串能直接抄去追问的地址"。
 *
 * ## 为什么把自报放在命中之后而不是页脚
 *
 * 截断、已回收、空窗口三件事**都表现成"没搜到"**，但下一步动作完全不同：
 * 截断要放宽 `--limit`，已回收要重新录一遍，空窗口说明这段根本没采。
 * 放在命中列表正下方，读者扫完结果就看得到；塞进表头会被长路径挤掉。
 *
 * ## 视图不再判"哪条更重要"
 *
 * `find()` 已经按域顺序 + id 升序排好（确定序）。这里不重排、不打分：
 * 搜索的结论要能被复算，而"相关度"没法复算 —— 那属于未来的排序模型，
 * 不属于视图。
 *
 * @internal 证据层视图；`tools/debug-find.php` 是它唯一的调用方
 */
final class Find
{
    /**
     * @param array<string, mixed> $answer `find()` 的返回
     * @return list<string>
     */
    public static function lines(array $answer): array
    {
        $domains = $answer['domains'] ?? [];
        $total = (int) ($answer['total'] ?? 0);
        $returned = (int) ($answer['returned'] ?? 0);

        $scanned = [];
        foreach ($domains as $name => $one) {
            $scanned[] = self::name((string) $name) . ' ' . (int) ($one['scanned'] ?? 0);
        }

        $lines = [\sprintf(
            '搜「%s」· %s%s · 命中 %d · 扫过 %s',
            (string) ($answer['pattern'] ?? ''),
            (string) ($answer['domain'] ?? 'all'),
            ($answer['case'] ?? false) === true ? '（区分大小写）' : '',
            $total,
            $scanned === [] ? '—' : \implode(' / ', $scanned),
        )];

        if ($returned === 0) {
            $lines[] = '  没有命中';
        } else {
            foreach ($answer['matches'] ?? [] as $one) {
                $lines[] = \sprintf(
                    '  %-13s %s',
                    (string) ($one['address'] ?? ''),
                    (string) ($one['text'] ?? ''),
                );
            }
        }

        if (($answer['truncated'] ?? false) === true) {
            $lines[] = \sprintf(
                '  截断：命中 %d，只给了 %d（--limit=%d）',
                $total,
                $returned,
                (int) ($answer['limit'] ?? 0),
            );
        }

        // 已回收的节点不在节点表里 ⇒ 搜不到。不印这个数，读者会把"没搜到"
        // 读成"从来没有过"，而真实原因是它被回收了。
        $evicted = (int) ($domains['node']['evicted'] ?? 0);
        if ($evicted > 0) {
            $lines[] = \sprintf('  另有 %d 个节点已回收，搜不到（不等于没有过）', $evicted);
        }

        if (isset($domains['request'])) {
            $window = $domains['request']['window'] ?? [];
            $lines[] = \sprintf(
                '  网络窗口 %d..%d / %d 条',
                (int) ($window['from'] ?? 0),
                (int) ($window['to'] ?? 0),
                (int) ($window['capacity'] ?? 0),
            );
        }

        return $lines;
    }

    private static function name(string $domain): string
    {
        return match ($domain) {
            'node' => '节点',
            'request' => '请求',
            default => $domain,
        };
    }
}
