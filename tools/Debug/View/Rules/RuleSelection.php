<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View\Rules;

/**
 * 这一次投影要跑哪些规则。
 *
 * ## 为什么要有"只跑这几条"
 *
 * 简报的用途有两个，而它们的默认集合不同：**扫一遍找问题**要全部规则，
 * **盯住一个已知问题**只要那一条（CI 里断言某条规则不再触发，尤其如此）。
 * 前者是默认，后者靠 `--rule=`；没有它，读者只能读完整份报告再自己挑。
 *
 * ## 为什么 `without` 与 `only` 都要有
 *
 * 它们不是互逆的。`only` 是"我只要这些"（集合从零开始），`without` 是
 * "除了这些都要"（集合从全部开始）。用 `only` 表达后者要列出其余九条 ——
 * 而规则表一扩容，那条命令就悄悄少看了新规则，报告却仍然说"没问题"。
 *
 * ## 为什么严重度过滤不算裁剪
 *
 * `--min-severity=warning` 之后报告里少了几行，但那几行**没有触发过任何判据**
 * 的结论不受影响：`clean` / `inconclusive` 兜底仍然按全部规则算过之后才给。
 * 反过来，如果过滤发生在判据之前，报告就会把"我没看"说成"没有问题"。
 */
final readonly class RuleSelection
{
    /**
     * @param list<string> $only   只跑这些编号（空 = 不限制）
     * @param list<string> $without 跳过这些编号
     * @param ?Severity    $min    低于它的发现不呈现（判据仍然跑过）
     */
    public function __construct(
        public array $only = [],
        public array $without = [],
        public ?Severity $min = null,
    ) {
    }

    /** 默认：全部规则、全部级别。 */
    public static function all(): self
    {
        return new self();
    }

    /**
     * 有没有任何过滤；没有时编排层可以走更短的路径。
     *
     * `min: Info` 也算"没过滤"：`Info` 是最低一级，`--min-severity=info` 一条也
     * 挡不掉。把它当成过滤，表头就会在完全没筛选的报告上写"规则 9/9 条"，
     * 读者会以为少了什么。
     */
    public function isEverything(): bool
    {
        return $this->only === []
            && $this->without === []
            && ($this->min === null || $this->min === Severity::Info);
    }

    /**
     * 这条规则是不是**整条**都不用跑。
     *
     * 只在整个规则的产出都被排除时才跳过：一条规则可以产多种发现
     * （约束改写同时给放宽与收紧），而 `--without=constraint-widened` 只想去掉
     * 其中一个。若按"有交集就跳过"，收紧的那条也一起没了 —— 读者以为它在
     * 应用里不存在。精确到发现的过滤在 {@see accepts()}。
     *
     * @param list<string> $ids
     */
    public function skipsIds(array $ids): bool
    {
        if ($this->only !== [] && \array_intersect($ids, $this->only) === []) {
            return true;
        }

        return \array_diff($ids, $this->without) === [];
    }

    /** 这一条发现留不留。`only` 精确到发现编号，不是到规则。 */
    public function accepts(string $findingId): bool
    {
        if ($this->only !== [] && !\in_array($findingId, $this->only, true)) {
            return false;
        }

        return !\in_array($findingId, $this->without, true);
    }

    /**
     * 严重度够不够。
     *
     * 判据**已经跑过**才走到这里 —— 过滤发生在产出之后。反过来的话，报告里
     * 少了 `warning` 以下的几行，兜底的 `clean` 就会把"我没看"说成"没问题"。
     */
    public function acceptsSeverity(Severity $severity): bool
    {
        return $this->min === null || $severity->rank() >= $this->min->rank();
    }
}
