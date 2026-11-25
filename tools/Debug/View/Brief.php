<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Rules\Rule;
use Drift\Dev\Debug\View\Rules\RuleRegistry;
use Drift\Dev\Debug\View\Rules\RuleSelection;
use Drift\Dev\Debug\View\Rules\Severity;

/**
 * 证据简报：把已经记下来的事实压成"预算内可读、且自报裁剪"的一段文本。
 *
 * ## 它不采集任何东西
 *
 * 全部输入来自查询面（`frames` / `explain` / `hotspots` / `nodes` / `cascade` /
 * `digest` / `stats` / `boxes` / `paint` / `repaints`）。这不是省事，是这一层存在
 * 的意义：如果简报要新开采集，它就成了第二份真相，"同一份证据不同消费者结论一致"
 * 立刻不成立。
 *
 * ## 它也不实现判据
 *
 * 十条规则住在 `Rules\`，每条一个类，各自声明编号、严重度与判据；本类只做三件事：
 * **按表顺序跑、把兜底说清楚、算覆盖度**。这么切是因为规则是会一直加的东西，
 * 而"加一条规则"与"改投影纪律"是两种完全不同的改动频率 —— 混在一个 850 行的
 * 类里，前者每加一次都要重读后者。
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
 * @internal 证据层投影；`tools/debug-brief.php` 与 `View\Web` 是它的调用方
 */
final class Brief
{
    // 阈值不住在这里：它们随调用方走（CLI 可传、Web 可传另一组、证据包要带着它导出），
    // 见 {@see Thresholds}。常量只有一份，换个刷新率或换类项目就只能改代码。

    /**
     * 从事实里算出发现（确定性规则，按注册表顺序）。
     *
     * @param array<string, mixed> $facts
     * @return list<Finding>
     */
    public static function findings(array $facts, ?Thresholds $t = null, ?RuleSelection $selection = null): array
    {
        $t ??= Thresholds::default();
        $selection ??= RuleSelection::all();

        $ran = RuleRegistry::select($selection);
        $found = [];
        // 过滤前产出过几条。它决定"报告为空"该说哪句话：
        // 判据跑过但被过滤，与判据跑过且没触发，是两件完全不同的事。
        $evaluated = 0;

        foreach ($ran as $rule) {
            $severity = $rule->severity();
            foreach ($rule->evaluate($facts, $t) as $one) {
                ++$evaluated;
                // 两条过滤都在产出之后：`--min-severity` 只少看几行，不改变
                // "判据跑过没有"这个事实 —— 否则兜底会把"我没看"说成"没问题"。
                if (!$selection->accepts($one->rule) || !$selection->acceptsSeverity($severity)) {
                    continue;
                }
                $found[] = $one;
            }
        }

        if ($found !== []) {
            return $found;
        }

        return [self::fallback($facts, $t, $selection, $ran, $evaluated)];
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
     * `selection` 也一并给出：读者看到"只有 2 条发现"，需要知道这是全部规则的
     * 结论还是只跑了两条。少这一项，`--rule=` 的报告与完整报告长得一模一样。
     *
     * @param array<string, mixed> $facts
     * @return array{findings: list<Finding>, coverage: array<string, mixed>, thresholds: array<string, float|int>, selection: array<string, mixed>}
     */
    public static function project(array $facts, ?Thresholds $t = null, ?RuleSelection $selection = null): array
    {
        $t ??= Thresholds::default();
        $selection ??= RuleSelection::all();

        return [
            'findings' => self::findings($facts, $t, $selection),
            'coverage' => self::coverage($facts),
            'thresholds' => $t->toArray(),
            'selection' => self::selectionOf($selection),
        ];
    }

    // ------------------------------------------------------------ 兜底

    /**
     * 一条发现都没有时，报告仍然必须说一句话 —— 而且要说对是哪一句。
     *
     * 三种空报告，三种修法：
     * - `clean`：全部规则跑过、都没触发、树是完整的。这时"没问题"是结论。
     * - `inconclusive`：规则没触发，但树被截断 / 帧不完整。"扫过的地方没问题"
     *   不等于"没问题"，读者该去把树取全再问一次。
     * - `suppressed`：规则**触发了**，但被 `--rule` / `--without` / `--min-severity`
     *   挡掉了。这是最容易写成"没问题"的一种 —— 实现上它和 clean 都表现为
     *   "发现列表为空"，而结论完全相反。
     *
     * @param array<string, mixed> $facts
     * @param list<Rule> $ran
     */
    private static function fallback(
        array $facts,
        Thresholds $t,
        RuleSelection $selection,
        array $ran,
        int $evaluated,
    ): Finding {
        $window = Facts::mapOf($facts, 'window');
        $address = \sprintf('window:%d-%d', (int) ($window['from'] ?? 0), (int) ($window['to'] ?? 0));

        if ($evaluated > 0) {
            return Finding::fallback(
                'suppressed',
                \sprintf('%d 条发现被过滤掉了，不是没有问题', $evaluated),
                $address,
                \sprintf('%d 条', $evaluated),
                self::filterText($selection),
                '去掉过滤条件重跑：不加 --rule / --without / --min-severity',
                // 有发现被藏起来，比"什么都没触发"更值得看一眼 —— 它不是干净的结论。
                Severity::Warning,
            );
        }

        $cov = self::coverage($facts);
        $scope = self::scopeText($t, $ran);

        // "没触发规则"与"没问题"是两句话。树被截断时只能说前者 —— 后者是
        // 把"我没看到"当成"不存在"，而这恰好是这类报告最容易犯、最难发现的错。
        if ($cov['complete']) {
            return Finding::fallback(
                'clean',
                \sprintf('窗口内没有触发任何规则（%s）', $scope),
                $address,
                '0 条',
                '—',
                '换更长的窗口或降低阈值再看一次',
            );
        }

        return Finding::fallback(
            'inconclusive',
            \sprintf(
                '扫过的那部分没有触发规则，但因果树不完整：展开到第 %d 层、%d 处深度截断、%d 支同层分支被折叠（%s）',
                $cov['seen'],
                $cov['cut'],
                (int) ($cov['nested'] ?? 0),
                $scope,
            ),
            $address,
            \sprintf('%d 处未展开', $cov['cut'] + (int) ($cov['nested'] ?? 0)),
            '扫描完整度 = 100%',
            '调大 cascade 的 maxDepth / maxChildren 重取一次，或直接 cascade(' . (int) ($window['to'] ?? 0) . ')',
        );
    }

    /** 这次跑了哪些规则、按什么级别过滤 —— 报告头部与 `--json` 都用它。 */
    private static function selectionOf(RuleSelection $selection): array
    {
        $ids = [];
        foreach (RuleRegistry::select($selection) as $rule) {
            $ids[] = $rule->id();
        }

        return [
            'rules' => $ids,
            'total' => \count(RuleRegistry::all()),
            'minSeverity' => $selection->min?->value,
            'filtered' => !$selection->isEverything(),
        ];
    }

    /**
     * 兜底文案里的"看了什么"。
     *
     * 全跑时列阈值（读者据此判断要不要调低），只跑一部分时列条数 ——
     * 只跑一条规则的报告说"慢帧 16.7ms / 扇出 100 / 重复 50%"会让人以为
     * 那三条都看过了。
     *
     * @param list<Rule> $ran
     */
    private static function scopeText(Thresholds $t, array $ran): string
    {
        $total = \count(RuleRegistry::all());
        if (\count($ran) < $total) {
            return \sprintf('只跑了 %d/%d 条规则', \count($ran), $total);
        }

        return \sprintf('慢帧 %.1fms / 扇出 %d / 重复 %d%%', $t->slowMs, $t->fanoutNodes, $t->repeatPercent);
    }

    /** 过滤条件写成一行，`suppressed` 的"阈值"栏用它。 */
    private static function filterText(RuleSelection $selection): string
    {
        $parts = [];
        if ($selection->only !== []) {
            $parts[] = '--rule=' . \implode(',', $selection->only);
        }
        if ($selection->without !== []) {
            $parts[] = '--without=' . \implode(',', $selection->without);
        }
        if ($selection->min !== null) {
            $parts[] = '--min-severity=' . $selection->min->value;
        }

        return $parts === [] ? '—' : \implode(' ', $parts);
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
     * @return array{seen: int, cut: int, nested: int, requested: int, broken: list<string>, complete: bool}
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
}
