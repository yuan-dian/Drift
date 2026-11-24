<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

use Drift\Debug\Session\Thresholds;

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
    // 阈值不住在这里：它们随调用方走（CLI 可传、Web 可传另一组、证据包要带着它导出），
    // 见 {@see Thresholds}。常量只有一份，换个刷新率或换类项目就只能改代码。

    /**
     * 从事实里算出发现（确定性规则，按固定顺序）。
     *
     * @param array<string, mixed> $facts
     * @return list<Finding>
     */
    public static function findings(array $facts, ?Thresholds $t = null): array
    {
        $t ??= Thresholds::default();
        $found = [];

        foreach (self::slowFindings($facts, $t) as $one) {
            $found[] = $one;
        }

        $stage = self::stageFinding($facts);
        if ($stage !== null) {
            $found[] = $stage;
        }

        foreach (self::fanoutFindings($facts, $t) as $one) {
            $found[] = $one;
        }

        $group = self::groupFinding($facts, $t);
        if ($group !== null) {
            $found[] = $group;
        }

        $repeat = self::repeatFindings($facts, $t);
        foreach ($repeat as $one) {
            $found[] = $one;
        }

        foreach (self::reshapeFindings($facts, $t) as $one) {
            $found[] = $one;
        }

        // 几何没覆盖到目标帧时，"没有布局发现"不是结论，而是没看见。
        $gap = self::geometryGapFinding($facts);
        if ($gap !== null) {
            $found[] = $gap;
        }

        if ($found === []) {
            $window = Facts::mapOf($facts, 'window');
            $cov = self::coverage($facts);
            $address = \sprintf('window:%d-%d', (int) ($window['from'] ?? 0), (int) ($window['to'] ?? 0));

            // "没触发规则"与"没问题"是两句话。树被截断时只能说前者 —— 后者是
            // 把"我没看到"当成"不存在"，而这恰好是这类报告最容易犯、最难发现的错。
            $found[] = $cov['complete']
                ? new Finding(
                    'clean',
                    \sprintf(
                        '窗口内没有触发任何规则（慢帧 %.1fms / 扇出 %d / 重复 %d%%）',
                        $t->slowMs,
                        $t->fanoutNodes,
                        $t->repeatPercent,
                    ),
                    $address,
                    '0 条',
                    '—',
                    '换更长的窗口或降低阈值再看一次',
                )
                : new Finding(
                    'inconclusive',
                    \sprintf(
                        '扫过的那部分没有触发规则，但因果树不完整：展开到第 %d 层、%d 处深度截断、%d 支同层分支被折叠',
                        $cov['seen'],
                        $cov['cut'],
                        (int) ($cov['nested'] ?? 0),
                    ),
                    $address,
                    \sprintf('%d 处未展开', $cov['cut'] + (int) ($cov['nested'] ?? 0)),
                    '扫描完整度 = 100%',
                    '调大 cascade 的 maxDepth / maxChildren 重取一次，或直接 cascade(' . (int) ($window['to'] ?? 0) . ')',
                );
        }

        return $found;
    }

    /**
     * 投影：事实 → 结论。**只排序，不裁剪**。
     *
     * 裁剪仍然会发生，但它属于呈现：终端受 40 行预算约束，Web 能滚动。把预算塞进
     * 这里的后果是签名里多两个排版参数（`budget` / `width`），而"同一份证据给同一
     * 结论"与行宽毫无关系 —— 第二个消费者进来时，它得先假装自己有 100 列。
     *
     * `coverage` 一并给出。裁剪账要写"规则看全了没有"，而那是投影算的统计；
     * 呈现层自己再算一遍必然漂，漂的表现是"报告说扫全了，其实是猜的"。
     *
     * @param array<string, mixed> $facts
     * @return array{findings: list<Finding>, coverage: array<string, mixed>, thresholds: array<string, float|int>}
     */
    public static function project(array $facts, ?Thresholds $t = null): array
    {
        $t ??= Thresholds::default();

        return [
            'findings' => self::findings($facts, $t),
            'coverage' => self::coverage($facts),
            'thresholds' => $t->toArray(),
        ];
    }

    // ------------------------------------------------------------ 规则

    /**
     * @param array<string, mixed> $facts
     * @return list<Finding>
     */
    private static function slowFindings(array $facts, Thresholds $t): array
    {
        $slow = [];
        foreach (Facts::listOf($facts, 'frames') as $frame) {
            if ((float) ($frame['cost'] ?? 0.0) > $t->slowMs) {
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
                \sprintf('慢帧 #%d %.2fms，超预算 %.1f×', $id, $cost, $cost / $t->slowMs),
                'frame:' . $id,
                \sprintf('%.2fms', $cost),
                \sprintf('%.1fms', $t->slowMs),
                \sprintf('explain(%d).cost 应给出同一数值', $id),
            );
        }

        return $out;
    }

    /** @param array<string, mixed> $facts */
    private static function stageFinding(array $facts): ?Finding
    {
        $explain = Facts::mapOf($facts, 'explain');
        $frameId = (int) ($explain['id'] ?? 0);
        $cost = (float) ($explain['cost'] ?? 0.0);
        if ($frameId <= 0 || $cost <= 0.0) {
            return null;
        }

        // `stageMs` 已按耗时降序（记录层做的），取第一段就是最大段。
        $name = '';
        $ms = 0.0;
        foreach (Facts::mapOf($explain, 'stageMs') as $stage => $value) {
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
    private static function fanoutFindings(array $facts, Thresholds $t): array
    {
        $cascade = Facts::mapOf($facts, 'cascade');
        $rebuilt = (int) ($cascade['rebuilt'] ?? 0);
        if ($rebuilt < $t->fanoutNodes) {
            return [];
        }

        $roots = Facts::listOf($cascade, 'roots');
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
        if ($share < $t->fanoutShare) {
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
            '≥ ' . $t->fanoutNodes . ' 且单支 ≥ ' . $t->fanoutShare . '%',
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
    private static function groupFinding(array $facts, Thresholds $t): ?Finding
    {
        $cascade = Facts::mapOf($facts, 'cascade');
        $roots = Facts::listOf($cascade, 'roots');
        if ($roots === []) {
            return null;
        }

        $group = self::biggestGroup($roots, $t);
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
            '≥ ' . $t->groupSame,
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
    private static function biggestGroup(array $nodes, Thresholds $t, int $depth = 0): array
    {
        if ($depth > 32) {
            return [];
        }

        $best = [];
        $bestCount = 0;
        foreach ($nodes as $node) {
            $count = (int) ($node['count'] ?? 1);
            if ($count >= $t->groupSame && $count > $bestCount) {
                $best = $node;
                $bestCount = $count;
            }
            $kids = Facts::listOf($node, 'children');
            if ($kids === []) {
                continue;
            }
            $deep = self::biggestGroup($kids, $t, $depth + 1);
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
    private static function repeatFindings(array $facts, Thresholds $t): array
    {
        $window = Facts::mapOf($facts, 'window');
        $count = (int) ($window['count'] ?? 0);
        if ($count < 5) {
            return [];
        }

        $need = (int) \ceil($count * $t->repeatPercent / 100);
        $out = [];
        foreach (Facts::listOf($facts, 'nodes') as $node) {
            $rebuilds = (int) ($node['rebuilds'] ?? 0);
            if ($rebuilds < $need) {
                continue;
            }
            $out[] = new Finding(
                'repeat-rebuild',
                \sprintf(
                    '%s 在 %d/%d 帧都重建（自写 %d 次）',
                    Fit::tailPath((string) ($node['path'] ?? ('#' . $node['id'])), 48),
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
    private static function reshapeFindings(array $facts, Thresholds $t): array
    {
        $geometry = Facts::mapOf($facts, 'geometry');
        $rows = Facts::listOf($geometry, 'rows');
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
            // 两个轴各算一次。只比宽度会漏掉"行高被压到 12"那一类 —— 高度改写
            // 与宽度改写是同一个问题在不同轴上的表现，规则却只看了宽度。
            foreach (['w', 'h'] as $axis) {
                $one = self::reshapeAxis($parent, $row, $axis, $t);
                if ($one !== null) {
                    $candidates[] = $one;
                }
            }
        }
        \usort($candidates, static fn(array $a, array $b): int => (\abs($b['delta']) <=> \abs($a['delta']))
            ?: ($a['node'] <=> $b['node'])
            ?: \strcmp((string) $a['axis'], (string) $b['axis']));

        $frame = (int) ($geometry['frame'] ?? 0);
        $out = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $wide = $candidate['delta'] > 0;
            $axisName = $candidate['axis'] === 'w' ? '宽' : '高';
            // 去重按"轴 + 方向"：宽放宽 / 宽收紧 / 高放宽 / 高收紧各最多一条。
            // 只看方向会把"高收紧"和"宽收紧"当成同一条，后者被丢掉。
            $key = $candidate['axis'] . ':' . ($wide ? 'wide' : 'tight');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = new Finding(
                $wide ? 'constraint-widened' : 'constraint-tightened',
                \sprintf(
                    '%s → %s %s被%s到 %.0f（父内容%s %.0f，%+.0fpx，%s）',
                    $candidate['from'],
                    $candidate['to'],
                    $axisName,
                    $wide ? '放宽' : '收紧',
                    $candidate['received'],
                    $axisName,
                    $candidate['offered'],
                    $candidate['delta'],
                    $candidate['cause'],
                ),
                self::addressOf($frame, (int) $candidate['node']),
                \sprintf(
                    '%s %.0f → %.0f（%+.0fpx）',
                    $axisName,
                    $candidate['offered'],
                    $candidate['received'],
                    $candidate['delta'],
                ),
                \sprintf('≥ %.0fpx 且 ≥ 父内容%s %.0f%%', $t->reshapePx, $axisName, $t->reshapePercent),
                \sprintf(
                    'constraints(%d, %d).chain 的 %s → %s 跳；boxModel(%d, %d)',
                    (int) $candidate['node'], $frame, $candidate['from'], $candidate['to'],
                    (int) $candidate['node'], $frame,
                ),
            );
            if (\count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    /**
     * 一个轴上的约束改写候选（宽度或高度）。
     *
     * 两轴共用同一组阈值：`RESHAPE_MIN_SIZE` 挡的是"盒子太小，相对百分比没有意义"，
     * 对高度同样成立 —— 12px 的行报"收紧 8px"只是噪音。
     *
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function reshapeAxis(array $parent, array $row, string $axis, Thresholds $t): ?array
    {
        $key = $axis === 'w' ? 'maxW' : 'maxH';
        $sizeKey = $axis === 'w' ? 'w' : 'h';

        $offered = self::finiteMax(Facts::mapOf($parent, 'inner'), $key);
        $received = self::finiteMax(Facts::mapOf($row, 'applied'), $key);
        $actual = self::finiteMax(Facts::mapOf($row, 'size'), $sizeKey);
        if ($offered === null || $received === null || $actual === null
            || $offered <= 0.0 || $actual < $t->reshapeMinSize) {
            return null;
        }
        // `INF` 不参与比较：父允许任意尺寸时，"子比它小"不是改写。
        $delta = $received - $offered;
        if (\abs($delta) < $t->reshapePx
            || \abs($delta) * 100.0 < $t->reshapePercent * $offered) {
            return null;
        }
        $box = self::finiteMax(Facts::mapOf($row, 'box'), $sizeKey);

        return [
            'node' => (int) ($row['node'] ?? 0),
            'axis' => $axis,
            'delta' => $delta,
            'offered' => $offered,
            'received' => $received,
            'from' => (string) ($parent['type'] ?? '?'),
            'to' => (string) ($row['type'] ?? '?'),
            'cause' => $box !== null
                ? \sprintf('%s修饰 %.0f', $axis === 'w' ? '宽度' : '高度', $box)
                : '自定义布局',
        ];
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
        $geometry = Facts::mapOf($facts, 'geometry');
        $rows = Facts::listOf($geometry, 'rows');
        $frame = (int) ($geometry['frame'] ?? 0);
        $window = Facts::mapOf($geometry, 'window');
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
        $cascade = Facts::mapOf($facts, 'cascade');
        $roots = Facts::listOf($cascade, 'roots');
        if ($roots === []) {
            // 没有树 = 这一帧没有重建，不是"没查到"。空树本身是完整的 ——
            // 除非帧自己标了不完整（异常中断 / 因果边超限），那时"没有重建"
            // 可能只是"还没走到那一步"。
            $broken = self::brokenFrames($facts);

            return [
                'seen' => 0,
                'cut' => 0,
                'nested' => 0,
                'requested' => 0,
                'broken' => $broken,
                'complete' => $broken === [],
            ];
        }

        $cut = 0;
        $nested = 0;
        $seen = self::depthScan($roots, 1, $cut, $nested);
        $requested = (int) (Facts::mapOf($facts, 'cascadeQuery')['maxDepth'] ?? $seen);

        // 顶层 `omitted` / `hidden` 是**根层**被 `maxChildren` 裁掉的（同层分支太多），
        // 那些根根本不在 `roots` 里，递归扫不到，只能单独补。
        // `cut` 是"还有孩子的节点没往下展开"（`maxDepth` 到顶）。
        $lost = (int) ($cascade['omitted'] ?? 0) + (int) ($cascade['hidden'] ?? 0) + $nested;

        // 帧级不完整同样让"没触发规则"变成假结论：`aborted` 的帧因果是半截的，
        // `causes-truncated` 的帧树是缺的 —— 两种情况下树看起来都很正常。
        $broken = self::brokenFrames($facts);

        return [
            'seen' => $seen,
            'cut' => $cut,
            'nested' => $nested,
            'requested' => $requested,
            'broken' => $broken,
            'complete' => $cut === 0 && $lost === 0 && $broken === [],
        ];
    }

    /**
     * 帧级不完整的原因集合（空 = 窗口里每一帧都完整）。
     *
     * 来源是 `FrameRecord::$incomplete`：录制器在"因果边被上限拒收"和"帧内抛异常"
     * 时各自留痕。这里只做去重汇总 —— 读者要知道"是哪类不完整"，不需要知道
     * "哪一帧"，后者由 `frames()` 逐帧给。
     *
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function brokenFrames(array $facts): array
    {
        $kinds = [];
        foreach (Facts::listOf($facts, 'frames') as $frame) {
            foreach (Facts::listOf($frame, 'incomplete') as $kind) {
                $kinds[(string) $kind] = true;
            }
        }

        return \array_keys($kinds);
    }

    /**
     * 最深走到第几层、有多少处被标了 `cut`、内层一共丢了多少支。
     *
     * 深度上限 32 只是**防环的保险**（真出现环说明记录层坏了，报告不该陪着一起死）。
     * 它不承担裁剪职责：能不能往下走由 `children` 决定，而 `children` 已经由
     * 查询层按 `maxDepth` 裁过。
     *
     * ★ 内层裁剪必须递归累加：`foldGroups()` 在**每一层**的 node 上都写了
     *   `omitted`（`maxChildren` 裁掉几支），只看顶层会把"内层被裁"漏成
     *   `complete=true` —— 报告说"树是完整的"，而实际上中间某层砍掉了半棵树。
     *   只累 `omitted` 不累 `hidden`：后者是**节点**口径，内层节点的规模已经
     *   算在根的 `subtree` 里，递归累加会重复。
     *
     * @param list<array<string, mixed>> $nodes
     */
    private static function depthScan(array $nodes, int $depth, int &$cut, int &$nested): int
    {
        if ($depth > 32) {
            return $depth;
        }

        $deepest = $depth;
        foreach ($nodes as $node) {
            if (($node['cut'] ?? false) === true) {
                ++$cut;
            }
            $nested += (int) ($node['omitted'] ?? 0);
            $kids = Facts::listOf($node, 'children');
            if ($kids === []) {
                continue;
            }
            $deep = self::depthScan($kids, $depth + 1, $cut, $nested);
            if ($deep > $deepest) {
                $deepest = $deep;
            }
        }

        return $deepest;
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
}
