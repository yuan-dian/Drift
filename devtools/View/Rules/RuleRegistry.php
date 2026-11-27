<?php
declare(strict_types=1);

namespace Drift\DevTools\View\Rules;

use Drift\DevTools\View\Finding;

/**
 * 规则表：谁在跑、按什么顺序跑、以及"登记"与"实现"是否对得上。
 *
 * ## 为什么顺序写死在表里
 *
 * 规则之间的顺序是**因果顺序**，不是字母序：先看单帧超没超预算（`slow-frame`），
 * 再看超在哪一段（`stage-dominance`），再看是谁把它撑起来的（`fanout` /
 * `unvirtualized-group` / `repeat-rebuild`），最后看绘制与布局的细节。
 * 按 id 排序会把"哪里慢"和"为什么慢"打散，读者得自己重新排一遍。
 *
 * 顺序与严重度是两件事：`--min-severity` 只少看几行，不重排 —— 同一份证据
 * 换个过滤条件就换一种叙述顺序，会让两份报告无法对照。
 *
 * ## 为什么闭合校验在运行时
 *
 * `Finding::RULE_VERSIONS` 是"发现"这个数据形状的属性（版本随发现一起进 JSON、
 * 进证据包），而规则实现住在规则类里。两张表天然会漂：加规则忘登记 ⇒ 版本为 0；
 * 删规则忘清理 ⇒ 版本表留下永远不会出现的孤儿 id。此前靠单元测试双向闭合守，
 * 但测试只在有人跑的时候才守 —— 而漏登记的代价是"规则改了，报告说自己没变"，
 * 这恰好是最需要当场发现的一类错。所以校验放在第一次建表时，跑一次就炸。
 *
 * @internal 证据层投影；`Brief` 是它唯一的调用方
 */
final class RuleRegistry
{
    /**
     * 兜底结论的编号。它们不是规则：不可开关、不参与 `--rule` 过滤 ——
     * 关掉兜底等于"报告为空"，那不是配置，是故障。列在这里是为了让反向闭合
     * 知道"版本表里这两个 id 不归规则管"，而不是把它们当成漏登记。
     *
     * @var list<string>
     */
    public const FALLBACK_IDS = ['clean', 'inconclusive', 'suppressed'];

    /** @var list<Rule>|null 规则无状态，建一次复用；`null` = 还没建 */
    private static ?array $rules = null;

    /**
     * 全部规则，顺序即输出顺序。
     *
     * @return list<Rule>
     */
    public static function all(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $rules = [
            new SlowFrameRule(),
            new StageDominanceRule(),
            new FanoutRule(),
            new UnvirtualizedGroupRule(),
            new RepeatRebuildRule(),
            new RepaintStormRule(),
            new LayerMissRule(),
            new ConstraintRewriteRule(),
            new GeometryGapRule(),
        ];

        self::verify($rules);

        return self::$rules = $rules;
    }

    /**
     * 所有可能出现的发现编号（含一条规则产多种发现的变体）。
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        $ids = [];
        foreach (self::all() as $rule) {
            foreach ($rule->ids() as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * 发现编号 => 度量是否可复现。基线对比据此决定哪些能进断言。
     *
     * @return array<string, bool>
     */
    public static function determinism(): array
    {
        $map = [];
        foreach (self::all() as $rule) {
            foreach ($rule->ids() as $id) {
                $map[$id] = $rule->deterministic();
            }
        }

        return $map;
    }

    /**
     * 按选择集挑出要跑的规则。未知编号在这里就报错。
     *
     * 报错而不是静默忽略：`--rule=slowframe` 少一个连字符，静默忽略的表现是
     * "这条规则没触发"，读者会去查应用，而不是查自己拼错了参数。
     *
     * @return list<Rule>
     */
    public static function select(RuleSelection $selection): array
    {
        $known = self::ids();
        $asked = [...$selection->only, ...$selection->without];
        $unknown = \array_values(\array_diff($asked, $known));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(\sprintf(
                "未知规则：%s。可用：%s",
                \implode(', ', $unknown),
                \implode(', ', $known),
            ));
        }

        $out = [];
        foreach (self::all() as $rule) {
            if (!$selection->skipsIds($rule->ids())) {
                $out[] = $rule;
            }
        }

        return $out;
    }

    /** 某条发现编号属于哪条规则；兜底与未知编号给 `null`。 */
    public static function ownerOf(string $findingId): ?Rule
    {
        foreach (self::all() as $rule) {
            if (\in_array($findingId, $rule->ids(), true)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * 两张表必须双向闭合。
     *
     * @param list<Rule> $rules
     */
    private static function verify(array $rules): void
    {
        $seen = [];
        foreach ($rules as $rule) {
            $ids = $rule->ids();
            if (!\in_array($rule->id(), $ids, true)) {
                throw new \LogicException(\sprintf(
                    '规则 %s 的 ids() 里没有它自己 —— 按编号反查会漏掉它',
                    $rule->id(),
                ));
            }
            foreach ($ids as $id) {
                if (isset($seen[$id])) {
                    throw new \LogicException(\sprintf(
                        '规则编号 %s 被 %s 与 %s 同时声明',
                        $id,
                        $seen[$id],
                        $rule->id(),
                    ));
                }
                $seen[$id] = $rule->id();

                if (!isset(Finding::RULE_VERSIONS[$id])) {
                    throw new \LogicException(\sprintf(
                        '规则 %s 产出的 %s 没有在 Finding::RULE_VERSIONS 登记版本',
                        $rule->id(),
                        $id,
                    ));
                }
            }
        }

        foreach (\array_keys(Finding::RULE_VERSIONS) as $id) {
            if (isset($seen[$id]) || \in_array($id, self::FALLBACK_IDS, true)) {
                continue;
            }
            throw new \LogicException(\sprintf(
                'Finding::RULE_VERSIONS 里的 %s 既没有规则实现，也不是兜底结论',
                $id,
            ));
        }
    }
}
