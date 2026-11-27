<?php
declare(strict_types=1);

namespace Drift\DevTools\View\Rules;

use Drift\Debug\Session\Thresholds;
use Drift\DevTools\View\Finding;

/**
 * 一条发现规则：编号、严重度、判据、以及一句"它在看什么"。
 *
 * ## 为什么规则是一个类，而不是 `Brief` 里的一个私有方法
 *
 * 规则加进 `Brief` 的时候是三处同时改：`findings()` 里加一行调用、
 * `Finding::RULE_VERSIONS` 里加一行版本、测试里加一个分组。三处都手写，
 * 就必然有一处漏 —— 而漏掉版本的表现是"规则改了，报告却说自己没变"。
 * 收成一个类之后，新增规则只碰一个文件，注册表在**运行时**校验闭合，
 * 不再依赖测试替它守。
 *
 * ## 为什么 `evaluate()` 返回列表而不是可空单条
 *
 * 十种规则里有八种最多出一条，两种（慢帧、同形兄弟）能出多条。用 `?Finding`
 * 就得给多产出的那两种单独开一条路，编排代码于是要写两种形状的合并 ——
 * 而两种形状的合并是最容易写成"其中一种被静默丢掉"的地方。
 *
 * ## 为什么规则不带状态
 *
 * `RuleRegistry` 对每条规则只造一个实例并复用。规则一旦持有"上次算到哪"
 * 之类的字段，两次 `project()` 就会给出不同结果，而简报的第一条纪律是
 * 纯函数。需要缓存的东西应该进 `Facts`，那里已经是"算好的输入"。
 */
abstract class Rule
{
    /** 规则编号，与 `Finding::RULE_VERSIONS` 的键同一套字符串。 */
    abstract public function id(): string;

    /** 这条规则产出的**全部**发现编号；默认与 {@see id()} 相同。 */
    public function ids(): array
    {
        return [$this->id()];
    }

    abstract public function severity(): Severity;

    /** 一句话说清它在看什么。`--rules` 直接打印它，不再另写一份说明。 */
    abstract public function summary(): string;

    /** 判据版本；改判据方向、阈值语义或规则边界时覆盖并 +1。 */
    public function version(): int
    {
        return 1;
    }

    /**
     * 这条规则的度量在同一份脚本重跑时是否可复现。
     *
     * 耗时随调度抖动（本机实测 ±7%），拿它当断言等于每天制造一次假红；而假红三次
     * 之后没人再读报告。默认 `true`：新增规则忘了声明，应该退化成"可比"，
     * 而不是"悄悄不比" —— 后者会让一条本该进 CI 的判据静默失效。
     */
    public function deterministic(): bool
    {
        return true;
    }

    /**
     * 从事实里算出发现。**不裁剪、不排序跨规则**：返回顺序即呈现顺序。
     *
     * @param array<string, mixed> $facts
     * @return list<Finding>
     */
    abstract public function evaluate(array $facts, Thresholds $t): array;

    /**
     * 造一条发现，严重度由规则自己盖。
     *
     * 规则类一律走这里而不是 `new Finding(...)`：严重度是**规则的**属性，
     * 让每条 `new` 自己带一次，总有一条写成 `Severity::Info` 而规则声明的是
     * `Warning` —— 那种不一致在报告里看不出来，只有 `--min-severity` 会漏行。
     */
    protected function finding(
        string $rule,
        string $title,
        string $address,
        string $metric,
        string $threshold,
        string $recheck,
        ?float $value = null,
    ): Finding {
        return new Finding($rule, $title, $address, $metric, $threshold, $recheck, $this->severity(), $value);
    }
}
