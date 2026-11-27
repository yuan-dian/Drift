<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

use Drift\DevTools\View\Rules\RuleRegistry;
use Drift\DevTools\View\Rules\Severity;

/**
 * 基线对比：两份证据按**同一条规则、同一个地址**配对，判断变好还是变差。
 *
 * ## 为什么配对键是 `(规则, 地址)` 而不是标题
 *
 * 标题里带数值（`慢帧 #2 42.05ms`），拿它当键等于"数值变了就配不上" ——
 * 而那正是要比较的东西。地址（`frame:2` / `node:341` / `frame:2 node:5 w`）
 * 是查询参数，同一份脚本重跑时稳定；它变了说明**根本不是同一个位置**，
 * 那时报"新增 + 消失"比强行配对诚实。
 *
 * ## 为什么规则换版就不可比
 *
 * 同一地址在 v1 报 40、v2 报 50，可能只是判据换了口径，也可能是真的变差了。
 * 没有版本时这两种情况都读作"变差 25%"，而修法完全相反（一个去查应用，
 * 一个去查规则改动）。所以版本不同单独成一类，不计入回归。
 *
 * ## 为什么只有"可复现"的度量才进断言
 *
 * 帧耗时在同一台机器上重跑，实测抖动 ±7%（36.98–42.42ms），而重建数、重画帧数、
 * 约束收紧 px 五次跑逐值相同。把耗时计入 `regressions` 的后果是报告每天红一次，
 * 每次都是假的 —— 假红三次之后没人再读它。所以耗时类规则照样算状态、照样列行，
 * 只是不计入断言，行首标 `~` 并在段尾自报。
 *
 * ## 为什么指纹不同仍然继续比
 *
 * 指纹不同意味着应用或脚本变了 —— 但"我改了代码，指标好了没有"正是基线对比
 * 最常用的场景，那时指纹**必然**不同。所以它只作为一行头部信息报出来，
 * 由读者判断这次差异是不是预期的。
 *
 * @internal 证据层投影；`devtools/bin/debug-brief.php --baseline` 是它唯一的调用方
 */
final class Baseline
{
    /** 基线文件格式。结构一变就 +1；读到不认识的版本直接报错，不猜。 */
    public const int FORMAT = 1;

    /** 数值变大（或新增）—— 值得回去查。 */
    public const string WORSE = 'worse';

    /** 数值变小 —— 修好了，不需要动作。 */
    public const string BETTER = 'better';

    /** 差异在容差内。 */
    public const string SAME = 'same';

    /** 只在当前这份里出现。 */
    public const string NEW = 'new';

    /** 只在基线里出现。 */
    public const string FIXED = 'fixed';

    /** 同地址但规则版本不同：数值不可比。 */
    public const string RULE_CHANGED = 'rule-changed';

    /** 一侧没有数值，没法比。 */
    public const string UNCOMPARABLE = 'uncomparable';

    /**
     * 呈现顺序：先看要修的，再看新出现的，最后看持平与修好的。
     *
     * @var array<string, int>
     */
    private const array ORDER = [
        self::WORSE => 0,
        self::NEW => 1,
        self::RULE_CHANGED => 2,
        self::UNCOMPARABLE => 3,
        self::SAME => 4,
        self::BETTER => 5,
        self::FIXED => 6,
    ];

    /**
     * 把当前这一份收成内存形状，给 {@see compare()} 当左/右操作数。
     *
     * @param list<Finding> $findings
     * @param array<string, float|int> $thresholds
     * @param array<string, mixed> $selection
     * @return array{label: string, digest: string, thresholds: array<string, float|int>, selection: array<string, mixed>, excluded: int, findings: list<Finding>}
     */
    public static function capture(
        string $label,
        string $digest,
        array $findings,
        array $thresholds,
        array $selection,
    ): array {
        return [
            'label' => $label,
            'digest' => $digest,
            'thresholds' => $thresholds,
            'selection' => $selection,
            'excluded' => 0,
            'findings' => $findings,
        ];
    }

    /**
     * 把内存形状写成一份基线文件。
     *
     * 只收有数值的发现：`clean` / `inconclusive` / `suppressed` 是结论不是度量，
     * 存进去会让"干净的应用"与"没测过"在文件里长得一样。`excluded` 因此要写：
     * 它解释一份空基线是"真干净"还是"全被排除了"。
     *
     * @param array{label: string, digest: string, thresholds: array<string, float|int>, selection: array<string, mixed>, excluded: int, findings: list<Finding>} $captured
     * @return array<string, mixed>
     */
    public static function snapshot(array $captured): array
    {
        $kept = [];
        foreach ($captured['findings'] as $one) {
            if ($one->isFallback()) {
                continue;
            }
            $kept[] = $one->toArray();
        }

        return [
            'format' => self::FORMAT,
            'label' => $captured['label'],
            'digest' => $captured['digest'],
            'thresholds' => $captured['thresholds'],
            'selection' => $captured['selection'],
            'excluded' => \count($captured['findings']) - \count($kept),
            'findings' => $kept,
        ];
    }

    /**
     * 读一份基线文件。
     *
     * 缺 `rule` 或 `address` 就报错，不猜：这两个字段是配对的全部依据，
     * 猜出来的结果会是"每条都新增"或"每条都对上了"，两种都看不出错。
     * 其余展示字段允许缺席，缺席就是空串 —— 手工写一份最小基线是合法用法。
     *
     * @param array<string, mixed> $file
     * @return array{label: string, digest: string, thresholds: array<string, mixed>, selection: array<string, mixed>, excluded: int, findings: list<Finding>}
     */
    public static function restore(array $file): array
    {
        $format = $file['format'] ?? null;
        if ($format !== self::FORMAT) {
            throw new \InvalidArgumentException(\sprintf(
                '基线格式版本不认识：%s（本工具支持 %d）',
                \is_scalar($format) ? (string) $format : \gettype($format),
                self::FORMAT,
            ));
        }

        $rows = $file['findings'] ?? null;
        if (!\is_array($rows)) {
            throw new \InvalidArgumentException('基线缺少 findings 数组');
        }

        $findings = [];
        foreach ($rows as $i => $row) {
            if (!\is_array($row)) {
                throw new \InvalidArgumentException(\sprintf('基线第 %d 条不是对象', $i + 1));
            }
            $rule = $row['rule'] ?? null;
            $address = $row['address'] ?? null;
            if (!\is_string($rule) || $rule === '' || !\is_string($address) || $address === '') {
                throw new \InvalidArgumentException(\sprintf(
                    '基线第 %d 条缺 rule 或 address（配对键不能猜）',
                    $i + 1,
                ));
            }
            $value = $row['value'] ?? null;
            if ($value !== null && !\is_int($value) && !\is_float($value)) {
                throw new \InvalidArgumentException(\sprintf('基线第 %d 条的 value 不是数字', $i + 1));
            }

            $findings[] = new Finding(
                $rule,
                (string) ($row['title'] ?? ''),
                $address,
                (string) ($row['metric'] ?? ''),
                (string) ($row['threshold'] ?? ''),
                (string) ($row['recheck'] ?? ''),
                Severity::parse((string) ($row['severity'] ?? '')) ?? Severity::Info,
                $value === null ? null : (float) $value,
                \is_int($row['version'] ?? null) ? $row['version'] : null,
            );
        }

        return [
            'label' => (string) ($file['label'] ?? ''),
            'digest' => (string) ($file['digest'] ?? ''),
            'thresholds' => \is_array($file['thresholds'] ?? null) ? $file['thresholds'] : [],
            'selection' => \is_array($file['selection'] ?? null) ? $file['selection'] : [],
            'excluded' => (int) ($file['excluded'] ?? 0),
            'findings' => $findings,
        ];
    }

    /**
     * @param array{label: string, digest: string, thresholds: array<string, mixed>, selection: array<string, mixed>, findings: list<Finding>} $before
     * @param array{label: string, digest: string, thresholds: array<string, mixed>, selection: array<string, mixed>, findings: list<Finding>} $after
     * @param float $tolerance 百分比容差：差异不超过它就当持平
     * @param array<string, bool>|null $deterministic 发现编号 => 度量是否可复现
     * @return array<string, mixed>
     */
    public static function compare(
        array $before,
        array $after,
        float $tolerance = 5.0,
        ?array $deterministic = null,
    ): array {
        $det = $deterministic ?? RuleRegistry::determinism();

        // 兜底结论不参与配对：它们的地址是窗口（`window:1-1`），换个窗口就变，
        // 报"消失 + 新增"是噪音。它们仍然出现在简报里，只是不进这份账。
        [$b, $bDupes] = self::index(self::measurable($before['findings']));
        [$a, $aDupes] = self::index(self::measurable($after['findings']));

        $keys = \array_keys($b + $a);
        \sort($keys);

        $rows = [];
        $counts = [];
        $gate = ['stable' => [], 'unstable' => []];
        foreach (self::ORDER as $state => $_) {
            $counts[$state] = 0;
            // 两种确定性 × 七种状态都记：呈现层要报"持平 4（不含耗时类）"，
            // 只留 worse/new 两格的话，耗时类修好的那两条会与"修好"重复计数。
            $gate['stable'][$state] = 0;
            $gate['unstable'][$state] = 0;
        }

        foreach ($keys as $key) {
            $row = self::row($key, $b[$key] ?? null, $a[$key] ?? null, $tolerance, $det);
            ++$counts[$row['state']];
            ++$gate[$row['deterministic'] ? 'stable' : 'unstable'][$row['state']];
            $rows[] = $row;
        }

        // 回归 = 确定性的"变差 + 新增"。`--assert` 只看这个数：
        // 修好的、换版的、耗时的都不该让 CI 红。
        $stable = $gate['stable'][self::WORSE] + $gate['stable'][self::NEW];
        // 耗时类的"偏离"把变好也算上：+9% 与 -9% 同样不可复现，都不该被读成结论。
        $unstable = $gate['unstable'][self::WORSE]
            + $gate['unstable'][self::NEW]
            + $gate['unstable'][self::BETTER];

        // 变差的排最前，同状态内按幅度降序（差得最多的最该先看），再按地址保证稳定。
        \usort($rows, static fn(array $x, array $y): int => (self::ORDER[$x['state']] <=> self::ORDER[$y['state']])
            ?: (\abs((float) ($y['percent'] ?? 0.0)) <=> \abs((float) ($x['percent'] ?? 0.0)))
            ?: \strcmp((string) $x['rule'] . $x['address'], (string) $y['rule'] . $y['address']));

        return [
            'digest' => [
                'before' => $before['digest'],
                'after' => $after['digest'],
                'same' => $before['digest'] !== '' && $before['digest'] === $after['digest'],
            ],
            // 口径变了不改行状态，只在这里说一句：读者看到一堆"新增"，得先知道
            // 是不是把阈值调低了 —— 否则他会去查应用。
            'context' => [
                'thresholds' => self::diffOf($before['thresholds'], $after['thresholds']),
                'selection' => ($before['selection']['rules'] ?? []) !== ($after['selection']['rules'] ?? []),
            ],
            'tolerance' => $tolerance,
            'counts' => $counts,
            'findings' => [
                'before' => \count($before['findings']),
                'after' => \count($after['findings']),
            ],
            'excluded' => $before['excluded'] ?? 0,
            // 回归 = 确定性的"变差 + 新增"。`--assert` 只看这个数：
            // 修好的、换版的、耗时的都不该让 CI 红。
            'regressions' => $stable,
            // 耗时类变化照列，但不冒充结论。
            'unstable' => $unstable,
            // 分解给呈现层：它要把"新增 2"拆成"确定性 1 / 耗时 1"。
            'gates' => $gate,
            // 同键重复要报出来：地址不够唯一时，"新增"与"消失"会同时出错。
            'duplicates' => ['before' => $bDupes, 'after' => $aDupes],
            'rows' => $rows,
        ];
    }

    /**
     * 兜底结论不参与配对。
     *
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private static function measurable(array $findings): array
    {
        return \array_values(\array_filter(
            $findings,
            static fn(Finding $one): bool => !$one->isFallback(),
        ));
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string> 变了的阈值名
     */
    private static function diffOf(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $name => $value) {
            $old = $before[$name] ?? null;
            // 基线走一趟 JSON，`8.0` 回来可能是 `8`。按数值比，否则每次都报
            // "阈值变了"——一个永远为真的警告等于没有警告。
            $same = \is_numeric($old) && \is_numeric($value)
                ? (float) $old === (float) $value
                : $old === $value;
            if (!$same) {
                $changed[] = (string) $name;
            }
        }

        return $changed;
    }

    /**
     * 按 `(规则, 地址)` 建索引，并数出重复。
     *
     * 同键重复时**保留第一条**并计数：正常情况不会重复，真重复了说明某条规则的
     * 地址不够唯一（约束改写的宽/高就曾经撞在一起）。让比较继续而不是抛错，
     * 但把重复数带出去 —— 静默丢一条会让"新增"与"消失"同时错。
     *
     * @param list<Finding> $findings
     * @return array{array<string, Finding>, int}
     */
    private static function index(array $findings): array
    {
        $map = [];
        $dupes = 0;
        foreach ($findings as $one) {
            $key = $one->rule . '|' . $one->address;
            if (isset($map[$key])) {
                ++$dupes;
                continue;
            }
            $map[$key] = $one;
        }

        return [$map, $dupes];
    }

    /**
     * @param array<string, bool> $deterministic
     * @return array<string, mixed>
     */
    private static function row(string $key, ?Finding $old, ?Finding $new, float $tolerance, array $deterministic): array
    {
        [$rule, $address] = \explode('|', $key, 2);

        $base = [
            'rule' => $rule,
            'address' => $address,
            'title' => ($new ?? $old)->title,
            'before' => $old?->value,
            'after' => $new?->value,
            'delta' => null,
            'percent' => null,
            'version' => [$old?->version() ?? 0, $new?->version() ?? 0],
            'deterministic' => $deterministic[$rule] ?? true,
        ];

        if ($old === null) {
            return \array_merge($base, ['state' => self::NEW]);
        }
        if ($new === null) {
            return \array_merge($base, ['state' => self::FIXED]);
        }
        if ($old->version() !== $new->version()) {
            return \array_merge($base, ['state' => self::RULE_CHANGED]);
        }
        // 两侧都没数值 = 同一句结论重复了一遍，那是持平，不是不可比。
        if ($old->value === null && $new->value === null) {
            return \array_merge($base, ['state' => self::SAME]);
        }
        if ($old->value === null || $new->value === null) {
            return \array_merge($base, ['state' => self::UNCOMPARABLE]);
        }

        $delta = $new->value - $old->value;
        // 基线为 0 时百分比没有意义（0 → 8 是"从无到有"，不是 +∞%）。
        $percent = $old->value === 0.0 ? null : $delta / \abs($old->value) * 100.0;

        $state = self::SAME;
        if ($percent !== null && \abs($percent) > $tolerance) {
            $state = $delta > 0.0 ? self::WORSE : self::BETTER;
        } elseif ($percent === null && $delta > 0.0) {
            // 0 → 正数：百分比算不出来，但"从没有变成有"是变差。
            $state = self::WORSE;
        }

        return \array_merge($base, ['state' => $state, 'delta' => $delta, 'percent' => $percent]);
    }
}
