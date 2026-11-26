<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 帧因果报告的**文本面**：把 `run_once()` 收上来的报告排成行。
 *
 * ## 为什么不在脚本里排
 *
 * 这段排版原先直接住在 `tools/debug-report.php` 里，往 `STDOUT` 写、按 `--top` 截。
 * 后果是**它没法被验证**：判据只能跑一遍再肉眼比对，于是五张表的表头与数据列早就
 * 各走各的 —— 「耗时分布」那张是 `sprintf('%-22s %7.2fms')`，`（段外）` 十二字节补到
 * 22、显示宽度只有 18 列，而 `settle` 是 22 列，毫秒列差 4 列。这类错不会报错，
 * 只会"有点歪"。
 *
 * 现在这里是**纯函数**：同样的 `$report` 给出逐字节相同的行，判据能直接钉住列边界。
 * 脚本只负责 `fwrite` 与退出码。（同一套拆法见过两次：`Brief::project` /
 * `BriefText::render`、`cascade` / `TreeRows`。）
 *
 * ## 三条纪律
 *
 * 1. **不重算事实**：只读 `$report` 里已有的数；缺的键当作"这一段没有"，不猜。
 * 2. **不静默截断**：裁剪必须留一行账（"另有 N 支未展开"）；自由文本列（调用点 /
 *    路径）永不截断 —— 截掉它等于把"该改哪一行"删了。
 * 3. **表头与数据同侧**：数字列右对齐时表头也右对齐，否则第一眼就认错列。
 *
 * @internal 证据层视图；`tools/debug-report.php` 是它唯一的调用方
 */
final class Report
{
    /**
     * @param array<string, mixed> $report `run_once()` 的 `report`，外加 `digest` 与 `script`
     *
     * @return list<string> 行（空串是一个空行）
     */
    public static function lines(array $report): array
    {
        /** @var array<string, mixed> $stats */
        $stats = $report['stats'] ?? [];
        /** @var list<array<string, mixed>> $frames */
        $frames = $report['frames'] ?? [];

        $walls = \array_map(static fn(array $f): float => (float) $f['wall'], $frames);
        $last = $walls === [] ? 0.0 : $walls[\count($walls) - 1];
        $slowest = self::slowestFrame($frames);

        $out = [
            \sprintf(
                '应用  %s%s',
                (string) ($report['app'] ?? '?'),
                ($report['hostPid'] ?? null) === null
                    ? ''
                    : \sprintf('（宿主进程 %d）', (int) $report['hostPid']),
            ),
            \sprintf(
                '视口  %dx%d     帧数 %d     登记节点 %d     挂载 %.2fms',
                (int) (($report['viewport'] ?? [0, 0])[0]),
                (int) (($report['viewport'] ?? [0, 0])[1]),
                (int) ($stats['frames'] ?? 0),
                (int) ($stats['nodes'] ?? 0),
                (float) ($report['mountMs'] ?? 0.0),
            ),
            \sprintf(
                '墙钟  末帧 %.2fms   平均 %.2fms   p95 %.2fms   最慢 %.2fms(#%d)',
                $last,
                \array_sum($walls) / \max(1, \count($walls)),
                self::percentile($walls, 0.95),
                $walls === [] ? 0.0 : \max($walls),
                $slowest,
            ),
        ];

        /** @var array<string, mixed> $scriptInfo */
        $scriptInfo = $report['script'] ?? [];
        $out[] = \sprintf(
            '脚本  %d 步（帧 %d / 输入 %d / 效果 %d）%s  驱动摘要 %s',
            (int) ($scriptInfo['steps'] ?? 0),
            (int) ($scriptInfo['frames'] ?? 0),
            (int) ($scriptInfo['inputs'] ?? 0),
            (int) ($scriptInfo['effects'] ?? 0),
            ($report['replayed'] ?? false) === true ? '  ← 本次由脚本重放' : '',
            (string) ($scriptInfo['digest'] ?? ''),
        );
        $out[] = '';

        foreach (self::frameRows($frames) as $line) {
            $out[] = $line;
        }

        $profile = self::frameById($frames, $slowest);
        if ($profile !== null) {
            foreach (self::stageLines($profile) as $line) {
                $out[] = $line;
            }
        }

        $cascade = $report['cascade'] ?? null;
        if (\is_array($cascade)) {
            foreach (self::cascadeLines($cascade) as $line) {
                $out[] = $line;
            }
        }

        foreach (self::hotspotLines($report) as $line) {
            $out[] = $line;
        }
        foreach (self::nodeLines($report) as $line) {
            $out[] = $line;
        }
        foreach (self::attributionLines($report) as $line) {
            $out[] = $line;
        }

        $why = $report['why'] ?? null;
        if (\is_array($why)) {
            foreach (self::whyLines($why) as $line) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * 帧表。**帧内 / 帧外分开列**：帧内是记录器包住的 `shell->frame()`，帧外是应用
     * 覆写的 `frame()` 里在父调用之后干的那段。两者差很多时，只看"帧内"会让人以为
     * 这一帧很便宜。
     *
     * @param list<array<string, mixed>> $frames
     *
     * @return list<string>
     */
    private static function frameRows(array $frames): array
    {
        $cols = [
            ['t' => '帧号', 'r' => true],
            ['t' => 't(ms)', 'r' => true],
            ['t' => '墙钟', 'r' => true],
            ['t' => '帧内', 'r' => true],
            ['t' => '帧外', 'r' => true],
            ['t' => '重建', 'r' => true],
            ['t' => '边', 'r' => true],
            ['t' => '跳过'],
        ];

        $rows = [];
        foreach ($frames as $frame) {
            $skipped = [];
            if ((bool) ($frame['settleSkipped'] ?? false)) {
                $skipped[] = 'settle';
            }
            if ((bool) ($frame['paintSkipped'] ?? false)) {
                $skipped[] = 'paint';
            }
            $outside = \max(0.0, (float) $frame['wall'] - (float) $frame['cost']);
            $rows[] = [
                (string) (int) $frame['id'],
                \sprintf('%.1f', (float) $frame['t']),
                \sprintf('%.2f', (float) $frame['wall']),
                \sprintf('%.2f', (float) $frame['cost']),
                \sprintf('%.2f', $outside),
                (string) (int) $frame['rebuilt'],
                (string) (int) $frame['causes'],
                $skipped === [] ? '-' : \implode('+', $skipped),
            ];
        }

        return Table::render($cols, $rows);
    }

    /**
     * 分段耗时，取**最慢帧**：只看平均会把那一帧埋掉，而"该优化哪一段"恰恰问的是它。
     *
     * 段外必须单列 —— 段账不覆盖帧内全部时间，藏着它等于报告自己冒充完整。
     *
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private static function stageLines(array $profile): array
    {
        $slowest = (int) $profile['id'];
        $out = [
            '',
            \sprintf('耗时分布（最慢帧 #%d，帧内 %.2fms）', $slowest, (float) $profile['cost']),
        ];

        /** @var array<string, float> $stageMs */
        $stageMs = $profile['stageMs'] ?? [];
        if ($stageMs === []) {
            $out[] = '  （这一帧没有分段记录）';
        }

        $rows = [];
        foreach ($stageMs as $stage => $ms) {
            $rows[] = [(string) $stage, \sprintf('%.2fms', (float) $ms)];
        }
        $unattributed = (float) ($profile['unattributedMs'] ?? 0.0);
        if ($unattributed > 0.0) {
            $rows[] = ['（段外）', \sprintf('%.2fms', $unattributed)];
        }
        if ($rows === []) {
            return $out;
        }

        foreach (Table::render([['w' => 0], ['w' => 8, 'r' => true]], $rows) as $line) {
            $out[] = $line;
        }
        if ($unattributed > 0.0) {
            $out[\count($out) - 1] .= '  ← 不在任何段里';
        }

        return $out;
    }

    /**
     * 单帧的「构成 + 因果覆盖」两段。
     *
     * 构成回答"这一帧是哪些控件在重建"（类型聚合，React Profiler 的 component ×N 口径），
     * 覆盖回答"从哪个根长下来、每个节点牵动多少"。两者都不受 `--top` 影响：它们是这一帧
     * 的全量事实，不是排行榜。
     *
     * @param array<string, mixed> $cascade
     *
     * @return list<string>
     */
    private static function cascadeLines(array $cascade): array
    {
        // 因果树取"最慢的**有重建**的帧"：纯重绘的帧（动画 / 滚动插值）可以最慢却一个
        // 节点都没重建。换过帧就要说，否则"最慢帧 #3"会指着一棵属于 #2 的树。
        $frame = (int) ($cascade['frame'] ?? 0);
        $slowest = (int) ($cascade['slowestFrame'] ?? $frame);
        $which = $slowest === $frame
            ? \sprintf('最慢帧 #%d', $slowest)
            : \sprintf('帧 #%d（最慢帧 #%d 只重绘不重建）', $frame, $slowest);

        $out = [
            '',
            \sprintf(
                '重建构成（%s，%d 个节点，最深 %d 层）',
                $which,
                (int) ($cascade['rebuilt'] ?? 0),
                (int) ($cascade['depthMax'] ?? 0),
            ),
        ];

        /** @var list<array{type: string, nodes: int}> $byType */
        $byType = $cascade['byType'] ?? [];
        if ($byType === []) {
            $out[] = '  （没有重建节点）';

            return $out;
        }

        $parts = [];
        foreach (\array_slice($byType, 0, 8) as $row) {
            $parts[] = $row['type'] . '×' . $row['nodes'];
        }
        $out[] = '  ' . \implode('   ', $parts);
        if (\count($byType) > 8) {
            $out[] = \sprintf('  … 还有 %d 类', \count($byType) - 8);
        }

        // 树的排版只有一份：{@see TreeRows}。CLI 与面板共用它，差别只在"看多少行"
        // （这里按 budget 截，面板按视口截）。
        $out[] = '';
        $out[] = '因果覆盖（根 = 父没有重建的节点；×N 折叠同形兄弟，数字按每个实例计）';
        $budget = 30;
        foreach (TreeRows::flatten($cascade['roots'] ?? []) as $row) {
            if ($budget <= 0) {
                break;
            }
            --$budget;
            $out[] = $row->text();
        }
        // 森林这一层也会被 maxChildren 截断：不说出来的话，"树里的数加起来不等于总重建数"
        // 就成了读者无法核对的一笔账。
        if ((int) ($cascade['hidden'] ?? 0) > 0) {
            $out[] = \sprintf(
                '  … 另有 %d 支根（%d 个节点）未展开',
                (int) ($cascade['omitted'] ?? 0),
                (int) $cascade['hidden'],
            );
        }
        if ($budget <= 0) {
            $out[] = '  … 已达到显示上限（层级与分支都只显示前几层）';
        }

        return $out;
    }

    /**
     * 根因聚合（`kind=state` 的写入点）。
     *
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private static function hotspotLines(array $report): array
    {
        /** @var list<array<string, mixed>> $hotspots */
        $hotspots = $report['hotspots'] ?? [];
        $out = [
            '',
            '根因（kind=state，次数 / 帧数 / 涉及节点 / 波及 / 调用点 / 明细）',
        ];
        if ($hotspots === []) {
            $out[] = '  （这段里没有状态写入：静止画面不会产生因果边）';

            return $out;
        }

        $cols = [
            ['t' => '次数', 'r' => true],
            ['t' => '帧数', 'r' => true],
            ['t' => '节点', 'r' => true],
            ['t' => '波及', 'r' => true],
            ['t' => '调用点'],
            ['t' => '明细'],
        ];
        $rows = [];
        foreach ($hotspots as $row) {
            $rows[] = [
                (string) (int) $row['writes'],
                (string) (int) $row['frames'],
                (string) (int) $row['nodes'],
                (string) (int) ($row['subtree'] ?? 0),
                (string) $row['site'],
                (string) $row['detail'],
            ];
        }
        foreach (Table::render($cols, $rows) as $line) {
            $out[] = $line;
        }
        if ((int) ($report['hiddenHotspots'] ?? 0) > 0) {
            $out[] = \sprintf('  …… 还有 %d 处根因没列出（--top=N 看更多）', (int) $report['hiddenHotspots']);
        }

        return $out;
    }

    /**
     * 重建聚合（按节点）。
     *
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private static function nodeLines(array $report): array
    {
        /** @var list<array<string, mixed>> $nodes */
        $nodes = $report['nodes'] ?? [];
        $out = [
            '',
            '重建聚合（次数 / 节点id / 覆盖 / 自身写入 / 父传播 / 路径）',
        ];
        if ($nodes === []) {
            $out[] = '  （这段里没有节点重建）';

            return $out;
        }

        // 单帧动作里常常每个节点各重建 1 次：这时次数没有区分度，排序实际由"覆盖"决定。
        // 不说清楚，读者会以为榜首就是"重建最多的那个节点"。
        if (\count($nodes) > 1
            && (int) $nodes[0]['rebuilds'] === (int) $nodes[\count($nodes) - 1]['rebuilds']
        ) {
            $out[] = \sprintf(
                '  （本段每个节点都只重建 %d 次：次数无区分度，已按覆盖子树排序）',
                (int) $nodes[0]['rebuilds'],
            );
        }

        $cols = [
            ['t' => '次数', 'r' => true],
            ['t' => '节点id', 'r' => true],
            ['t' => '覆盖', 'r' => true],
            ['t' => '自身写入', 'r' => true],
            ['t' => '父传播', 'r' => true],
            ['t' => '路径'],
        ];
        $rows = [];
        foreach ($nodes as $row) {
            $rows[] = [
                (string) (int) $row['rebuilds'],
                (string) (int) $row['id'],
                (string) (int) ($row['subtree'] ?? 0),
                (string) (int) $row['self'],
                (string) (int) $row['inherited'],
                (string) $row['path'],
            ];
        }
        foreach (Table::render($cols, $rows) as $line) {
            $out[] = $line;
        }
        if ((int) ($report['hiddenNodes'] ?? 0) > 0) {
            $out[] = \sprintf('  …… 还有 %d 个节点没列出（--top=N 看更多）', (int) $report['hiddenNodes']);
        }

        return $out;
    }

    /**
     * 逐帧归因。聚合排名回答"谁总在重建"，这里回答"这一帧为什么动"。
     *
     * 只有根因（`kind=state`）值得逐帧列：父传播是它的下游，列出来只是把同一件事
     * 重复 N 遍，反而看不出是哪一次写入引起的。
     *
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private static function attributionLines(array $report): array
    {
        /** @var list<array<string, mixed>> $attribution */
        $attribution = $report['attribution'] ?? [];
        $out = [
            '',
            '逐帧归因（有根因的帧 / 该帧重建节点数 / 根因）',
        ];
        if ($attribution === []) {
            $out[] = '  （没有带根因的帧）';

            return $out;
        }

        $cols = [
            ['t' => '帧', 'r' => true],
            ['t' => '重建', 'r' => true],
            ['t' => '根因'],
        ];
        $rows = [];
        foreach ($attribution as $row) {
            /** @var list<array<string, mixed>> $roots */
            $roots = $row['roots'] ?? [];
            $shown = [];
            foreach (\array_slice($roots, 0, 3) as $root) {
                $shown[] = \sprintf(
                    '%s @ %s ×%d',
                    (string) $root['detail'],
                    (string) $root['site'],
                    (int) $root['count'],
                );
            }
            if (\count($roots) > 3) {
                $shown[] = '还有 ' . (\count($roots) - 3) . ' 类';
            }
            $rows[] = [
                '#' . (int) $row['id'],
                (string) (int) $row['rebuilt'],
                $shown === [] ? '（无 kind=state 根因：只有父传播或 unknown）' : \implode('；', $shown),
            ];
        }

        foreach (Table::render($cols, $rows) as $line) {
            $out[] = $line;
        }

        return $out;
    }

    /**
     * `--why=<节点>`：这一帧它为什么重建，以及"我该改哪一行"。
     *
     * @param array<string, mixed> $why
     *
     * @return list<string>
     */
    private static function whyLines(array $why): array
    {
        $out = [
            '',
            \sprintf(
                '为什么 #%d（%s）在第 %s 帧重建',
                (int) $why['node'],
                (string) $why['path'],
                (string) ($why['frame'] ?? '-'),
            ),
        ];
        if (($why['rebuilt'] ?? false) !== true) {
            $out[] = '  该帧它没有重建';
        }

        /** @var list<array<string, mixed>> $causes */
        $causes = $why['causes'] ?? [];
        /** @var list<array<string, mixed>> $trail */
        $trail = \is_array($why['trail'] ?? null) ? $why['trail'] : [];

        if ($causes === []) {
            $out[] = '  没有归因边：本次会话里没记到这个节点的重建';
        }

        // parent 边本来只有一个空 site：补上是**哪个父节点**，否则"因为父重建了"等于
        // 没说。父路径从 trail 取，两处共用同一份拓扑。
        $pathById = [];
        foreach ($trail as $step) {
            $pathById[(int) $step['id']] = (string) $step['path'];
        }

        $rows = [];
        foreach ($causes as $cause) {
            $room = (string) $cause['site'];
            if ((string) $cause['detail'] !== '') {
                $room .= '  ' . (string) $cause['detail'];
            }
            if ((string) $cause['kind'] === 'parent' && isset($pathById[(int) ($cause['from'] ?? 0)])) {
                $fromId = (int) $cause['from'];
                $room .= \sprintf('  ← #%d %s', $fromId, Fit::tailSegments($pathById[$fromId]));
            }
            $rows[] = [(string) $cause['kind'], $room];
        }
        foreach (Table::render([['t' => '原因'], ['t' => '说明']], $rows) as $line) {
            $out[] = $line;
        }

        // 直接边只说"因为父节点重建了"，不说父是谁。链路把一路的父节点与终点
        // （state 写入点）摊开 —— 那才是"我该改哪一行"。
        if (\count($trail) > 1) {
            $out[] = '';
            $out[] = '  链路（目标 → 根）：';
            $trailRows = [];
            foreach ($trail as $step) {
                $room = (string) $step['site'];
                if ((string) $step['detail'] !== '') {
                    $room .= '  ' . (string) $step['detail'];
                }
                $trailRows[] = ['#' . (int) $step['id'], (string) $step['kind'], $room];
            }
            $cols = [['t' => '节点', 'r' => true], ['t' => '原因'], ['t' => '说明']];
            foreach (Table::render($cols, $trailRows, '    ') as $line) {
                $out[] = $line;
            }
            $out[] = '  ↑ 末行是链路终点：改它才治本，改下游只是少重建几个节点';
        }

        return $out;
    }

    /**
     * 最慢的那一帧。
     *
     * `$rebuiltOnly` 只要"重建过的帧"：因果树问的是"这一帧为什么动"，而最慢帧常常是
     * 挂载帧 —— 它最贵，却一个节点都不重建，拿它做因果树只会得到"（没有重建节点）"。
     *
     * @param list<array<string, mixed>> $frames
     */
    public static function slowestFrame(array $frames, bool $rebuiltOnly = false): int
    {
        $best = 0;
        $bestWall = -1.0;
        foreach ($frames as $frame) {
            if ($rebuiltOnly && (int) $frame['rebuilt'] === 0) {
                continue;
            }
            if ((float) $frame['wall'] > $bestWall) {
                $bestWall = (float) $frame['wall'];
                $best = (int) $frame['id'];
            }
        }

        return $best;
    }

    /**
     * 分位数（最近秩法）：n 个样本取第 `ceil(p*n)` 个。
     *
     * 不用插值：帧耗时的样本本来就少，插值出来的数在报告里不可回指到某一帧。
     *
     * @param list<float> $values 未排序
     */
    public static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        \sort($values);
        $rank = (int) \ceil($p * \count($values));

        return $values[\min(\count($values) - 1, \max(0, $rank - 1))];
    }

    /**
     * @param list<array<string, mixed>> $frames
     *
     * @return array<string, mixed>|null
     */
    private static function frameById(array $frames, int $id): ?array
    {
        foreach ($frames as $frame) {
            if ((int) $frame['id'] === $id) {
                return $frame;
            }
        }

        return null;
    }
}
