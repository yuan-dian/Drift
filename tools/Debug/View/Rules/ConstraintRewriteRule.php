<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View\Rules;

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Facts;
use Drift\Dev\Debug\View\Finding;

/**
 * 约束改写：父盒给出的最大尺寸与子盒实际拿到的差了一截，宽 / 高各看一次。
 *
 * ## 为什么一条规则产两个编号
 *
 * `constraint-widened` 与 `constraint-tightened` 是同一个判据的两个方向，
 * 共用"两轴各算一次、每轴每方向最多一条"的去重逻辑 —— 拆成两个类，那段去重
 * 要么复制一份，要么抽成第三个类，而抽出来的东西只被这两条规则用。
 * 编号仍然分开：读者要看的是"被放宽"还是"被收紧"，那是两个不同的动作。
 *
 * `ids()` 因此返回两个编号，`--rule=constraint-widened` 也认（注册表按编号反查规则），
 * 但产出时只留被点名的那一个 —— 精确过滤在 `RuleSelection::accepts()`。
 *
 * ## 为什么是 `info`
 *
 * 约束改写多数是**正常布局**：容器把子盒压到内容宽度，本来就是这样设计的。
 * 这条规则的价值是"给出传播链"，让人一眼看出 800 → 120 是在哪一跳发生的，
 * 而不是宣布这是个 bug。
 */
final class ConstraintRewriteRule extends Rule
{
    public function id(): string
    {
        return 'constraint-widened';
    }

    public function ids(): array
    {
        return ['constraint-widened', 'constraint-tightened'];
    }

    public function severity(): Severity
    {
        return Severity::Info;
    }

    public function summary(): string
    {
        return '父子之间的最大尺寸约束被放宽 / 收紧（默认 ≥ 8px 且 ≥ 10%）';
    }

    public function evaluate(array $facts, Thresholds $t): array
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

            $out[] = $this->finding(
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
                // 地址带轴：同一节点在同一帧可能**同时**被放宽（宽）与被收紧（高），
                // 两条发现共用 `frame:N node:M` 就会撞成一条。轴是读者去查的下一步
                // （`constraints()` 的 chain 里就是按轴分的），所以它属于地址。
                Finding::at($frame, (int) $candidate['node']) . ' ' . $candidate['axis'],
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
                \abs($candidate['delta']),
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
     * 两轴共用同一组阈值：`reshapeMinSize` 挡的是"盒子太小，相对百分比没有意义"，
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

    /** `'INF'` 是字符串，不能当有限数参与比较。 */
    private static function finiteMax(array $constraints, string $key): ?float
    {
        $value = $constraints[$key] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            return null;
        }

        return \is_finite((float) $value) ? (float) $value : null;
    }
}
