<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 证据简报：把已经记下来的事实压成"预算内可读、且自报裁剪"的一段文本。
 *
 * ## 它不采集任何东西
 *
 * 全部输入来自查询面（`frames` / `explain` / `hotspots` / `nodes` / `cascade` /
 * `digest` / `stats`）。这不是省事，是这一层存在的意义：如果简报要新开采集，
 * 它就成了第二份真相，"同一份证据不同消费者结论一致"立刻不成立。
 *
 * ## 三条纪律，缺一条就不再是证据
 *
 * 1. **纯函数**：同样的 `facts` + `budget` 必须给出逐字节相同的行。无时间戳、
 *    无随机、排序必须有确定的次级键（耗时降序 → 帧号升序）。否则同一份证据
 *    两次投影不同，"可复算"从投影这一层就断了。
 * 2. **自报裁剪**：裁掉多少、裁在哪、怎么取回，都要写进最后一行。读者会把
 *    "我没看到"当成"不存在"，而报告本身看不出来。
 * 3. **不按时间截断**：永远保留最贵帧与最热节点。只按"最近 N 帧"截，窗口一长
 *    真正的根因正好被裁掉，而读者以为"没什么问题"。
 *
 * ## 为什么发现先于明细
 *
 * 预算不够时先丢明细、再丢发现，而不是相反：明细可以按地址重新取回，发现不能
 * ——它是"要去查什么"的入口，丢了就不知道该查哪。
 *
 * @internal 证据层原型；`tools/debug-brief.php` 是它唯一的调用方
 */
final class Brief
{
    /** 一帧的预算（60Hz）。超了就是结论，不是排序项。 */
    public const float SLOW_MS = 16.7;

    /** 单帧重建超过这个数，才值得问"是不是扇出"。 */
    public const int FANOUT_NODES = 100;

    /** 且最大的那一支要占到六成 —— 否则是"很多支各自重建"，不是扇出。 */
    public const int FANOUT_SHARE = 60;

    /** 跨帧重复：窗口里一半以上的帧都在重建它。 */
    public const int REPEAT_PERCENT = 50;

    /** 同形兄弟达到这个数，基本就是"该虚拟化却没虚拟化"。 */
    public const int GROUP_SAME = 8;

    /**
     * 父子约束上限相差多少才算一次改写。
     *
     * 8px 以下多半是浮点尾数或 1px 描边，报出来只是噪声；阈值本身要写进发现，
     * 否则读者不知道"216px"到底算不算异常。
     */
    public const float RESHAPE_PX = 8.0;

    /** 还要超过父内容盒的 10%，避免大盒上的小抖动被当结论。 */
    public const float RESHAPE_PERCENT = 10.0;

    /** 只看本身够大的盒：1px 图标被"从 760 收到 1"骗出来不是发现，是噪声。 */
    public const float RESHAPE_MIN_SIZE = 100.0;

    /**
     * 从事实里算出发现（确定性规则，按固定顺序）。
     *
     * @param array<string, mixed> $facts
     * @return list<Finding>
     */
    public static function findings(array $facts): array
    {
        $found = [];

        foreach (self::slowFindings($facts) as $one) {
            $found[] = $one;
        }

        $stage = self::stageFinding($facts);
        if ($stage !== null) {
            $found[] = $stage;
        }

        foreach (self::fanoutFindings($facts) as $one) {
            $found[] = $one;
        }

        $group = self::groupFinding($facts);
        if ($group !== null) {
            $found[] = $group;
        }

        $repeat = self::repeatFindings($facts);
        foreach ($repeat as $one) {
            $found[] = $one;
        }

        foreach (self::reshapeFindings($facts) as $one) {
            $found[] = $one;
        }

        // 几何没覆盖到目标帧时，"没有布局发现"不是结论，而是没看见。
        $gap = self::geometryGapFinding($facts);
        if ($gap !== null) {
            $found[] = $gap;
        }

        if ($found === []) {
            $window = self::mapOf($facts, 'window');
            $cov = self::coverage($facts);
            $address = \sprintf('window:%d-%d', (int) ($window['from'] ?? 0), (int) ($window['to'] ?? 0));

            // "没触发规则"与"没问题"是两句话。树被截断时只能说前者 —— 后者是
            // 把"我没看到"当成"不存在"，而这恰好是这类报告最容易犯、最难发现的错。
            $found[] = $cov['complete']
                ? new Finding(
                    'clean',
                    \sprintf(
                        '窗口内没有触发任何规则（慢帧 %.1fms / 扇出 %d / 重复 %d%%）',
                        self::SLOW_MS,
                        self::FANOUT_NODES,
                        self::REPEAT_PERCENT,
                    ),
                    $address,
                    '0 条',
                    '—',
                    '换更长的窗口或降低阈值再看一次',
                )
                : new Finding(
                    'inconclusive',
                    \sprintf(
                        '扫过的那部分没有触发规则，但因果树只展开到第 %d 层、%d 处被截断',
                        $cov['seen'],
                        $cov['cut'],
                    ),
                    $address,
                    \sprintf('%d 处未展开', $cov['cut']),
                    '扫描完整度 = 100%',
                    '调大 cascade 的 maxDepth 重取一次，或直接 cascade(' . (int) ($window['to'] ?? 0) . ')',
                );
        }

        return $found;
    }

    /**
     * 投影：事实 + 预算 → 行 + 裁剪账。
     *
     * @param array<string, mixed> $facts
     * @return array{lines: list<string>, omitted: array<string, int|string>, findings: list<Finding>}
     */
    public static function project(array $facts, int $budget = 40, int $width = 100): array
    {
        $findings = self::findings($facts);
        $head = self::header($facts, $width);
        $detail = self::detail($facts, $width);

        // 固定开销：表头三行 + "发现 N 条" + 空行 + 裁剪账一行。
        $fixed = \count($head) + 3;
        $room = \max(1, $budget - $fixed);

        // 发现优先：每条一行，超了就截，但至少留一条（"没有发现"也是一种结论）。
        $shown = $findings;
        $droppedFindings = 0;
        if (\count($shown) > $room) {
            $kept = \max(1, $room - 1);
            $droppedFindings = \count($shown) - $kept;
            $shown = \array_slice($shown, 0, $kept);
        }

        $detailRoom = \max(0, $room - \count($shown));
        $shownDetail = \array_slice($detail, 0, $detailRoom);
        $droppedDetail = \count($detail) - \count($shownDetail);

        $tail = self::omissions($facts, $droppedFindings, $droppedDetail, $width);

        $lines = $head;
        $lines[] = \sprintf('发现 %d 条%s', \count($findings), $droppedFindings > 0 ? \sprintf('（显示 %d 条）', \count($shown)) : '');
        foreach ($shown as $one) {
            $lines[] = '  ' . $one->line($width - 2);
        }
        if ($shownDetail !== []) {
            $lines[] = '';
            foreach ($shownDetail as $line) {
                $lines[] = $line;
            }
        }
        $lines[] = '';
        $lines[] = $tail;

        return [
            'lines' => $lines,
            'omitted' => [
                'findings' => $droppedFindings,
                'detail' => $droppedDetail,
                'budget' => $budget,
                'rows' => \count($lines),
            ],
            'findings' => $findings,
        ];
    }

    // ------------------------------------------------------------ 规则

    /**
     * @param array<string, mixed> $facts
     * @return list<Finding>
     */
    private static function slowFindings(array $facts): array
    {
        $slow = [];
        foreach (self::listOf($facts, 'frames') as $frame) {
            if ((float) ($frame['cost'] ?? 0.0) > self::SLOW_MS) {
                $slow[] = $frame;
            }
        }
        // 耗时降序 → 帧号升序：同耗时也要有确定次序，否则两次投影不同。
        \usort($slow, static fn(array $a, array $b): int => ((float) $b['cost'] <=> (float) $a['cost'])
            ?: ((int) $a['id'] <=> (int) $b['id']));

        $out = [];
        foreach (\array_slice($slow, 0, 3) as $frame) {
            $id = (int) $frame['id'];
            $cost = (float) $frame['cost'];
            $out[] = new Finding(
                'slow-frame',
                \sprintf('慢帧 #%d %.2fms，超预算 %.1f×', $id, $cost, $cost / self::SLOW_MS),
                'frame:' . $id,
                \sprintf('%.2fms', $cost),
                \sprintf('%.1fms', self::SLOW_MS),
                \sprintf('explain(%d).cost 应给出同一数值', $id),
            );
        }

        return $out;
    }

    /** @param array<string, mixed> $facts */
    private static function stageFinding(array $facts): ?Finding
    {
        $explain = self::mapOf($facts, 'explain');
        $frameId = (int) ($explain['id'] ?? 0);
        $cost = (float) ($explain['cost'] ?? 0.0);
        if ($frameId <= 0 || $cost <= 0.0) {
            return null;
        }

        // `stageMs` 已按耗时降序（记录层做的），取第一段就是最大段。
        $name = '';
        $ms = 0.0;
        foreach (self::mapOf($explain, 'stageMs') as $stage => $value) {
            $name = (string) $stage;
            $ms = (float) $value;
            break;
        }
        if ($name === '') {
            return null;
        }

        $share = $ms / $cost;
        if ($share < 0.6 || $ms < 2.0) {
            return null;
        }

        return new Finding(
            'stage-dominance',
            \sprintf('#%d 的 %.0f%% 花在 %s（%.2fms / %.2fms）', $frameId, $share * 100, $name, $ms, $cost),
            \sprintf('frame:%d stage:%s', $frameId, $name),
            \sprintf('%.2fms', $ms),
            '占比 ≥ 60% 且 ≥ 2.00ms',
            \sprintf('explain(%d).stageMs.%s', $frameId, $name),
        );
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<Finding>
     */
    private static function fanoutFindings(array $facts): array
    {
        $cascade = self::mapOf($facts, 'cascade');
        $rebuilt = (int) ($cascade['rebuilt'] ?? 0);
        if ($rebuilt < self::FANOUT_NODES) {
            return [];
        }

        $roots = self::listOf($cascade, 'roots');
        if ($roots === []) {
            return [];
        }

        // 第一大支：组内每份子树 × 份数（`cascade` 的口径就是"每实例"）。
        // ★ `ids` 只在 `count > 1` 时存在（单个节点不给，免得同一件事两种写法），
        //   所以代表 id 与份数要分别从 `id` / `count` 取 —— 直接读 `ids[0]` 会拿到 0，
        //   而 0 不在有效 id 域里，错误会静默地印进报告。
        $top = $roots[0];
        $topId = (int) ($top['id'] ?? 0);
        $topCount = \max(1, (int) ($top['count'] ?? 1));
        $subtree = (int) ($top['subtree'] ?? 1) * $topCount;
        $share = (int) \round($subtree * 100 / $rebuilt);
        if ($share < self::FANOUT_SHARE) {
            return [];
        }

        $frameId = (int) ($cascade['frame'] ?? 0);
        return [new Finding(
            'fanout',
            \sprintf(
                '#%d 重建 %d 个，其中 %d 落在一个 %s 根（%d%%）',
                $frameId,
                $rebuilt,
                $subtree,
                (string) ($top['type'] ?? '?'),
                $share,
            ),
            self::addressOf($frameId, $topId),
            (string) $rebuilt,
            '≥ ' . self::FANOUT_NODES . ' 且单支 ≥ ' . self::FANOUT_SHARE . '%',
            \sprintf('cascade(%d).roots[0] 与 rebuilt', $frameId),
        )];
    }

    /**
     * 同形兄弟：一个容器底下 N 个一模一样的子树一起重建，基本就是"该虚拟化却没虚拟化"。
     *
     * 要**往下走**，不能只看根：表格页的根是整棵 `PageShell`（份数 1），
     * 真正重复的是它内部某个容器底下的 12 个同形兄弟 —— 只扫根这条规则永远不触发，
     * 而它恰恰是最常见、最容易改的一类问题。
     *
     * @param array<string, mixed> $facts
     */
    private static function groupFinding(array $facts): ?Finding
    {
        $cascade = self::mapOf($facts, 'cascade');
        $roots = self::listOf($cascade, 'roots');
        if ($roots === []) {
            return null;
        }

        $group = self::biggestGroup($roots);
        if ($group === []) {
            return null;
        }

        $count = (int) ($group['count'] ?? 1);
        $type = (string) ($group['type'] ?? '?');
        $frameId = (int) ($cascade['frame'] ?? 0);

        return new Finding(
            'unvirtualized-group',
            \sprintf(
                '一个 %s 底下有 %d 个同形兄弟一起重建（每个 %d 个节点）',
                $type,
                $count,
                (int) ($group['subtree'] ?? 1),
            ),
            self::addressOf($frameId, (int) ($group['id'] ?? 0)),
            (string) $count,
            '≥ ' . self::GROUP_SAME,
            \sprintf('cascade(%d).roots[].count 沿 children 往下找', $frameId),
        );
    }

    /**
     * 树上份数最大的同形组。
     *
     * 深度上限只是**防环的保险**，不负责裁剪：树本身已经被 `cascade` 的 `maxDepth`
     * 截过，并且截断处带 `cut` / `omitted`。这里再按"看起来够深了"另设一个上限，
     * 就会与查询层的深度打架 —— 第一次写的时候限成 4，而表格页的 12 个同形兄弟
     * 在第 8 层，规则于是永远不触发，报告看起来"没问题"。
     *
     * @param list<array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private static function biggestGroup(array $nodes, int $depth = 0): array
    {
        if ($depth > 32) {
            return [];
        }

        $best = [];
        $bestCount = 0;
        foreach ($nodes as $node) {
            $count = (int) ($node['count'] ?? 1);
            if ($count >= self::GROUP_SAME && $count > $bestCount) {
                $best = $node;
                $bestCount = $count;
            }
            $kids = self::listOf($node, 'children');
            if ($kids === []) {
                continue;
            }
            $deep = self::biggestGroup($kids, $depth + 1);
            $deepCount = (int) ($deep['count'] ?? 0);
            if ($deepCount > $bestCount) {
                $best = $deep;
                $bestCount = $deepCount;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<Finding>
     */
    private static function repeatFindings(array $facts): array
    {
        $window = self::mapOf($facts, 'window');
        $count = (int) ($window['count'] ?? 0);
        if ($count < 5) {
            return [];
        }

        $need = (int) \ceil($count * self::REPEAT_PERCENT / 100);
        $out = [];
        foreach (self::listOf($facts, 'nodes') as $node) {
            $rebuilds = (int) ($node['rebuilds'] ?? 0);
            if ($rebuilds < $need) {
                continue;
            }
            $out[] = new Finding(
                'repeat-rebuild',
                \sprintf(
                    '%s 在 %d/%d 帧都重建（自写 %d 次）',
                    self::tailPath((string) ($node['path'] ?? ('#' . $node['id'])), 48),
                    $rebuilds,
                    $count,
                    (int) ($node['self'] ?? 0),
                ),
                \sprintf('node:%d', (int) ($node['id'] ?? 0)),
                \sprintf('%d/%d', $rebuilds, $count),
                \sprintf('≥ %d', $need),
                'nodes(window).rebuilds ÷ window.count',
            );
            if (\count($out) >= 2) {
                break;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ 几何

    /**
     * 父子约束改写：子盒收到的宽度上限与父内容盒给出的上限不同。
     *
     * 这是"这个表格为什么只有 240 宽"那类问题的机器版答案：不说"看起来不对"，
     * 只说在哪两跳之间、从多少变成多少、由什么改的（宽度修饰或自定义布局），
     * 并给出 `constraints(node, frame)` 这个可复算地址。
     *
     * 两个方向各最多报一条，并按键（父类型 → 子类型 → 改变量 → 原因）去重：
     * 表格 12 行同形单元格会给出同一条事实 12 次，列 12 遍不是 12 条发现。
     * 排序按绝对量降序、node id 升序；同量时给小 id，读者追问时地址稳定。
     *
     * @param array<string, mixed> $facts
     * @return list<Finding>
     */
    private static function reshapeFindings(array $facts): array
    {
        $geometry = self::mapOf($facts, 'geometry');
        $rows = self::listOf($geometry, 'rows');
        if ($rows === []) {
            return [];
        }

        $byNode = [];
        foreach ($rows as $row) {
            $byNode[(int) ($row['node'] ?? 0)] = $row;
        }

        $candidates = [];
        foreach ($rows as $row) {
            $node = (int) ($row['node'] ?? 0);
            $parentId = (int) ($row['parent'] ?? 0);
            if ($node <= 0 || $parentId <= 0 || !isset($byNode[$parentId])) {
                continue;
            }
            $parent = $byNode[$parentId];
            $offered = self::finiteMax(self::mapOf($parent, 'inner'), 'maxW');
            $received = self::finiteMax(self::mapOf($row, 'applied'), 'maxW');
            $actual = self::finiteMax(self::mapOf($row, 'size'), 'w');
            if ($offered === null || $received === null || $actual === null
                || $offered <= 0.0 || $actual < self::RESHAPE_MIN_SIZE) {
                continue;
            }
            // `INF` 不参与比较：父允许任意宽时，"子比它窄"不是改写。
            $delta = $received - $offered;
            if (\abs($delta) < self::RESHAPE_PX
                || \abs($delta) * 100.0 < self::RESHAPE_PERCENT * $offered) {
                continue;
            }
            $width = self::finiteMax(self::mapOf($row, 'box'), 'w');
            $candidates[] = [
                'node' => $node,
                'delta' => $delta,
                'offered' => $offered,
                'received' => $received,
                'from' => (string) ($parent['type'] ?? '?'),
                'to' => (string) ($row['type'] ?? '?'),
                'cause' => $width !== null ? \sprintf('宽度修饰 %.0f', $width) : '自定义布局',
            ];
        }
        \usort($candidates, static fn(array $a, array $b): int => (\abs($b['delta']) <=> \abs($a['delta']))
            ?: ($a['node'] <=> $b['node']));

        $frame = (int) ($geometry['frame'] ?? 0);
        $out = [];
        $directions = [];
        foreach ($candidates as $candidate) {
            $wide = $candidate['delta'] > 0;
            $dir = $wide ? 'wide' : 'tight';
            if (isset($directions[$dir])) {
                continue;
            }
            $directions[$dir] = true;

            $out[] = new Finding(
                $wide ? 'constraint-widened' : 'constraint-tightened',
                \sprintf(
                    '%s → %s 宽被%s到 %.0f（父内容 %.0f，%+.0fpx，%s）',
                    $candidate['from'],
                    $candidate['to'],
                    $wide ? '放宽' : '收紧',
                    $candidate['received'],
                    $candidate['offered'],
                    $candidate['delta'],
                    $candidate['cause'],
                ),
                self::addressOf($frame, (int) $candidate['node']),
                \sprintf('%.0f → %.0f（%+.0fpx）', $candidate['offered'], $candidate['received'], $candidate['delta']),
                \sprintf('≥ %.0fpx 且 ≥ 父内容宽 %.0f%%', self::RESHAPE_PX, self::RESHAPE_PERCENT),
                \sprintf(
                    'constraints(%d, %d).chain 的 %s → %s 跳；boxModel(%d, %d)',
                    (int) $candidate['node'], $frame, $candidate['from'], $candidate['to'],
                    (int) $candidate['node'], $frame,
                ),
            );
            if (\count($out) >= 2) {
                break;
            }
        }

        return $out;
    }

    /**
     * 几何没覆盖目标帧时，必须说"没看见"。
     *
     * 几何窗口比帧窗口短（60 对 600），而"几何没变"本来就不记新行 —— 所以
     * `window.to < frame` 是正常情况，不是缺口；只有一整帧都没有任何盒、或
     * 请求的帧早于窗口起点，才算规则没有看到几何。
     *
     * @param array<string, mixed> $facts
     */
    private static function geometryGapFinding(array $facts): ?Finding
    {
        $geometry = self::mapOf($facts, 'geometry');
        $rows = self::listOf($geometry, 'rows');
        $frame = (int) ($geometry['frame'] ?? 0);
        $window = self::mapOf($geometry, 'window');
        $from = (int) ($window['from'] ?? 0);
        $to = (int) ($window['to'] ?? 0);
        if ($rows !== [] && ($from === 0 || $frame === 0 || $from <= $frame)) {
            return null;
        }

        $address = $frame > 0
            ? 'frame:' . $frame
            : \sprintf('window:%d-%d', $from, $to);

        return new Finding(
            'geometry-inconclusive',
            $frame > 0 && $from > $frame
                ? \sprintf('几何窗口从 #%d 开始，#%d 不在其中，布局规则没有看到任何盒', $from, $frame)
                : '没有几何事实，布局规则一条也没有看',
            $address,
            '0 个盒',
            '至少 1 个盒',
            'boxes(frame).window 看窗口范围；换一帧或加大几何窗口',
        );
    }

    /** `'INF'` 是字符串，不能当有限数参与比较。 */
    private static function finiteMax(array $constraints, string $key): ?float
    {
        $value = $constraints[$key] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            return null;
        }

        return \is_finite((float) $value) ? (float) $value : null;
    }

    // ------------------------------------------------------------ 覆盖

    /**
     * 规则到底看全了没有。
     *
     * 这是第 6 条校订的落点：`cascade` 的 `maxDepth` 会截断树，截断处标 `cut`。
     * 规则顺着 `children` 走，走不到的地方就是**没看到**——如果报告不区分
     * "扫完了没有"和"扫到的地方没问题"，截断就伪装成了结论。
     *
     * `requested` 取自 `cascadeQuery`（gather 时原样记下），而不是重新猜一个数：
     * 扫描深度与查询深度必须同源，两边各写一个常量迟早会打架 —— 原型第一版
     * 就是这个 bug，表格页的同形兄弟在第 8 层，扫描器却停在 4 层。
     *
     * @param array<string, mixed> $facts
     * @return array{seen: int, cut: int, requested: int, complete: bool}
     */
    private static function coverage(array $facts): array
    {
        $cascade = self::mapOf($facts, 'cascade');
        $roots = self::listOf($cascade, 'roots');
        if ($roots === []) {
            // 没有树 = 这一帧没有重建，不是"没查到"。空树本身就是完整的。
            return ['seen' => 0, 'cut' => 0, 'requested' => 0, 'complete' => true];
        }

        $cut = 0;
        $seen = self::depthScan($roots, 1, $cut);
        $requested = (int) (self::mapOf($facts, 'cascadeQuery')['maxDepth'] ?? $seen);

        // 顶层 `omitted` / `hidden` 是 `maxChildren` 造成的（同层分支太多），
        // `cut` 只是"还有孩子的节点没往下展开"。两者都算不完整。
        $lost = (int) ($cascade['omitted'] ?? 0) + (int) ($cascade['hidden'] ?? 0);

        return [
            'seen' => $seen,
            'cut' => $cut,
            'requested' => $requested,
            'complete' => $cut === 0 && $lost === 0,
        ];
    }

    /**
     * 最深走到第几层，以及有多少处被标了 `cut`。
     *
     * 深度上限 32 只是**防环的保险**（真出现环说明记录层坏了，报告不该陪着一起死）。
     * 它不承担裁剪职责：能不能往下走由 `children` 决定，而 `children` 已经由
     * 查询层按 `maxDepth` 裁过。
     *
     * @param list<array<string, mixed>> $nodes
     */
    private static function depthScan(array $nodes, int $depth, int &$cut): int
    {
        if ($depth > 32) {
            return $depth;
        }

        $deepest = $depth;
        foreach ($nodes as $node) {
            if (($node['cut'] ?? false) === true) {
                ++$cut;
            }
            $kids = self::listOf($node, 'children');
            if ($kids === []) {
                continue;
            }
            $deep = self::depthScan($kids, $depth + 1, $cut);
            if ($deep > $deepest) {
                $deepest = $deep;
            }
        }

        return $deepest;
    }

    // ------------------------------------------------------------ 排版

    /**
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function header(array $facts, int $width): array
    {
        $stats = self::mapOf($facts, 'stats');
        $window = self::mapOf($facts, 'window');
        $digest = self::mapOf($facts, 'digest');

        $title = \sprintf(
            '证据 · %s · 窗口 #%d–#%d / %d 帧 / 容量 %d / 节点 %d',
            (string) ($facts['label'] ?? '?'),
            (int) ($window['from'] ?? 0),
            (int) ($window['to'] ?? 0),
            (int) ($window['count'] ?? 0),
            (int) ($stats['capacity'] ?? 0),
            (int) ($stats['nodes'] ?? 0),
        );

        $finger = (string) ($digest['digest'] ?? '');
        $line = \sprintf(
            '指纹 %s · 规则 慢帧 %.1fms / 扇出 %d / 重复 %d%%',
            $finger === '' ? '—' : \substr($finger, 0, 12),
            self::SLOW_MS,
            self::FANOUT_NODES,
            self::REPEAT_PERCENT,
        );

        return [
            Fit::clip($title, $width),
            Fit::clip($line, $width),
            Fit::rule($width),
        ];
    }

    /**
     * 明细：最贵帧的段账、最大写入点、最热节点。三行封顶，多了也不是信息。
     *
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function detail(array $facts, int $width): array
    {
        $out = [];

        $explain = self::mapOf($facts, 'explain');
        $frameId = (int) ($explain['id'] ?? 0);
        if ($frameId > 0) {
            $parts = [];
            foreach (self::mapOf($explain, 'stageMs') as $stage => $ms) {
                $parts[] = \sprintf('%s %.1f', (string) $stage, (float) $ms);
                if (\count($parts) >= 3) {
                    break;
                }
            }
            $parts[] = \sprintf('段外 %.1f', (float) ($explain['unattributedMs'] ?? 0.0));
            $out[] = Fit::spread(
                \sprintf(
                    '#%d %.2fms  %s',
                    $frameId,
                    (float) ($explain['cost'] ?? 0.0),
                    \implode(' · ', $parts),
                ),
                \sprintf('重建 %d', \count(self::listOf($explain, 'rebuilt'))),
                $width,
            );
        }

        $hotspots = self::listOf($facts, 'hotspots');
        if ($hotspots !== []) {
            $top = $hotspots[0];
            $out[] = Fit::clip(\sprintf(
                '最大写入点 %s → %d 个节点（%d 帧里出现 %d 次）',
                (string) ($top['site'] ?? '?'),
                (int) ($top['subtree'] ?? 0),
                (int) ($top['frames'] ?? 0),
                (int) ($top['writes'] ?? 0),
            ), $width);
        }

        $nodes = self::listOf($facts, 'nodes');
        if ($nodes !== []) {
            $top = $nodes[0];
            // 路径从**尾部**裁：`… > Table > TableBox` 才认得出是哪个节点，
            // 从头裁只剩 `PageShell > Card > Column > …`，每个节点都长这样。
            $path = self::tailPath((string) ($top['path'] ?? '?'), \max(20, $width - 34));
            $out[] = Fit::clip(\sprintf(
                '最热节点 %s 重建 %d 次（自写 %d / 继承 %d）',
                $path,
                (int) ($top['rebuilds'] ?? 0),
                (int) ($top['self'] ?? 0),
                (int) ($top['inherited'] ?? 0),
            ), $width);
        }

        return $out;
    }

    /** 长路径保留**尾部**（最能定位的那几段），前面用 `… > ` 表示省略。 */
    private static function tailPath(string $path, int $width): string
    {
        if (Fit::width($path) <= $width) {
            return $path;
        }

        $parts = \explode(' > ', $path);
        $tail = '';
        while ($parts !== []) {
            $segment = (string) \array_pop($parts);
            $candidate = $tail === '' ? $segment : $segment . ' > ' . $tail;
            if (Fit::width('… > ' . $candidate) > $width) {
                break;
            }
            $tail = $candidate;
        }

        return $tail === '' ? Fit::clip($path, $width) : '… > ' . $tail;
    }

    /**
     * 几何域的扫描范围，写进裁剪账。
     *
     * "窗口外"和"这一帧没变所以没记"不是同一件事：前者要明说，后者正常。
     *
     * @param array<string, mixed> $facts
     */
    private static function geometryScope(array $facts): string
    {
        $geometry = self::mapOf($facts, 'geometry');
        $rows = self::listOf($geometry, 'rows');
        if ($rows === []) {
            return '几何未采集';
        }

        $frame = (int) ($geometry['frame'] ?? 0);
        $window = self::mapOf($geometry, 'window');
        $from = (int) ($window['from'] ?? 0);
        $to = (int) ($window['to'] ?? 0);
        if ($frame > 0 && $from > $frame) {
            return \sprintf('几何窗口 #%d–#%d 不含 #%d', $from, $to, $frame);
        }

        return \sprintf('几何覆盖 #%d（%d 个盒 / %d 帧窗口）', $frame, \count($rows), (int) ($window['capacity'] ?? 0));
    }

    /**
     * 裁剪账：裁了什么、裁了多少、怎么取回。**这一行不能省**。
     *
     * @param array<string, mixed> $facts
     */
    private static function omissions(array $facts, int $droppedFindings, int $droppedDetail, int $width): string
    {
        $stats = self::mapOf($facts, 'stats');
        $window = self::mapOf($facts, 'window');

        $parts = [];
        if ($droppedFindings > 0) {
            $parts[] = \sprintf('发现 %d 条未显示', $droppedFindings);
        }
        if ($droppedDetail > 0) {
            $parts[] = \sprintf('明细 %d 行未显示', $droppedDetail);
        }
        // 因果树的**扫描范围**必须写出来，哪怕没有节点被省：
        // "扫到第 2 层没发现问题"和"扫到第 12 层没发现问题"是两句不同的话。
        $cov = self::coverage($facts);
        if ($cov['seen'] > 0) {
            // 不写 "seen/requested"：一个是节点层级（从 1 数），一个是展开次数
            // （从 0 数），并排写会让人以为 `13/12` 是"超了"。给请求值本身即可。
            $parts[] = $cov['complete']
                ? \sprintf('因果树扫到第 %d 层（完整）', $cov['seen'])
                : \sprintf(
                    '因果树扫到第 %d 层，%d 处未展开（请求 maxDepth=%d）',
                    $cov['seen'],
                    $cov['cut'],
                    $cov['requested'],
                );
        }
        // 几何也要自报扫描范围：60 帧的几何窗口比 600 帧的因果窗口短，
        // 不写这一句，读者会把"没报布局问题"当成"全窗口都没有布局问题"。
        $parts[] = self::geometryScope($facts);

        $capacity = (int) ($stats['capacity'] ?? 0);
        $count = (int) ($window['count'] ?? 0);
        if ($capacity > 0 && $count < $capacity) {
            // 窗口没满时，"窗口外没有"是事实；满了就要明说更早的帧已被丢弃。
            $parts[] = \sprintf('窗口未满（%d/%d），更早的帧尚未产生', $count, $capacity);
        } elseif ($capacity > 0) {
            $parts[] = \sprintf('窗口已满（%d/%d），更早的帧已被丢弃', $count, $capacity);
        }
        if ($parts === []) {
            $parts[] = '无裁剪';
        }

        // 先保"裁了什么"，再保"怎么取回"：提示被截掉只是少一条指路，
        // 计数被截掉就是"裁剪账不完整"，而这一行唯一不能少的就是计数。
        $core = '裁剪：' . \implode('；', $parts);
        $hint = '（--budget=N 看更多，--json 取结构化）';

        return Fit::width($core . $hint) <= $width
            ? $core . $hint
            : Fit::clip($core, $width);
    }

    // ------------------------------------------------------------ 取值

    /**
     * 证据地址：帧 + 节点。
     *
     * 节点 id 为 0 时**不给 `node:` 段**。0 不在有效 id 域里，印出来既指不到东西，
     * 又长得像一个正常地址 —— 原型第一版从 `ids[0]`（那份数组只在 `count > 1` 时
     * 存在）读出了 0，报告里照印 `node:0`，没有任何一处报错。
     * 缺 id 就说缺 id，让读者回到 `frame:` 这一层重取。
     */
    private static function addressOf(int $frameId, int $nodeId): string
    {
        return $nodeId > 0
            ? \sprintf('frame:%d node:%d', $frameId, $nodeId)
            : \sprintf('frame:%d', $frameId);
    }

    /**
     * @param array<string, mixed> $source
     * @return list<array<string, mixed>>
     */
    private static function listOf(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function mapOf(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return \is_array($value) ? $value : [];
    }
}
