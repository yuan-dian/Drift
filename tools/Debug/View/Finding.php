<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

use Drift\Dev\Debug\View\Rules\Severity;

/**
 * 一条**发现**：规则名 + 证据地址 + 度量 + 阈值 + 复算方式。
 *
 * 五样缺一，它就从"证据"退化成"意见"：
 *
 * | 缺 | 后果 |
 * |---|---|
 * | 规则名 | 读者不知道这是哪条判断，无法判断该不该信 |
 * | 证据地址 | 只能重跑整页去找，找回来也未必是同一帧 |
 * | 度量值 | "很慢"不是事实，"42.05ms"才是 |
 * | 阈值 | 不知道跟什么比，也就不知道超了多少 |
 * | 复算方式 | 结论无法被否证，等于要求读者相信 |
 *
 * 地址用 `<域>:<键>` 的形式（`frame:2`、`node:341`、`stage:settle`），与查询面的
 * 参数一一对应 —— "说得出地址"和"问得回去"必须是同一件事，否则发现只是好看的句子。
 *
 * @internal 证据层原型；规则形状定下来之前不进 `bin/drift`
 */
final readonly class Finding
{
    /**
     * 规则名 => 版本。改判据方向、阈值语义或规则边界时必须 +1。
     *
     * 为什么要：同一份事实在两次运行里给出不同结论时，先要分清"事实变了"还是
     * "规则改了"。没有版本，这两件事都是"报告不一样"，而修复动作完全相反。
     * 版本不进指纹（发现是派生视图），只进发现本身。
     *
     * @var array<string, int>
     */
    public const RULE_VERSIONS = [
        'slow-frame' => 1,
        'stage-dominance' => 1,
        'fanout' => 1,
        'unvirtualized-group' => 1,
        'repeat-rebuild' => 1,
        'repaint-storm' => 1,
        'layer-miss' => 1,
        'constraint-widened' => 1,
        'constraint-tightened' => 1,
        'geometry-inconclusive' => 1,
        'clean' => 1,
        'inconclusive' => 1,
        // 规则触发了但被 `--rule` / `--without` / `--min-severity` 挡掉。
        // 它必须是一条**发现**而不是"空报告"：空报告读起来像"没问题"。
        'suppressed' => 1,
    ];

    public function __construct(
        public string $rule,
        public string $title,
        /** 形如 `frame:2` / `node:341` / `frame:2 stage:settle` */
        public string $address,
        public string $metric,
        public string $threshold,
        public string $recheck,
        /**
         * 严重度。放在末位且有默认值，是为了让"忘了传"退化成 `info` 而不是
         * 构造失败：一条发现少了个级别标签仍然可读，而少了一条发现是报告缺一段。
         * 规则类通过 `Rule::finding()` 自动盖章，不手写。
         */
        public Severity $severity = Severity::Info,
    ) {
    }

    /** 这条规则的版本；未登记的规则给 `0`（判据会咬，等于逼人登记）。 */
    public function version(): int
    {
        return self::RULE_VERSIONS[$this->rule] ?? 0;
    }

    /**
     * 证据地址：帧 + 节点。
     *
     * 节点 id 为 0 时**不给 `node:` 段**。0 不在有效 id 域里，印出来既指不到东西，
     * 又长得像一个正常地址 —— 原型第一版从 `ids[0]`（那份数组只在 `count > 1` 时
     * 存在）读出了 0，报告里照印 `node:0`，没有任何一处报错。缺 id 就说缺 id，
     * 让读者回到 `frame:` 这一层重取。
     *
     * 放在 `Finding` 上而不是某个规则里：地址语法是这个数据形状的一部分，
     * 十条规则共用一份，否则总会有一条写成 `node:%d frame:%d` 而没人发现。
     */
    public static function at(int $frameId, int $nodeId = 0): string
    {
        return $nodeId > 0
            ? \sprintf('frame:%d node:%d', $frameId, $nodeId)
            : \sprintf('frame:%d', $frameId);
    }

    /** 一行文本：结论在左、地址在右，放不下先丢地址（地址还能从 `--json` 取）。 */
    public function line(int $width): string
    {
        return Fit::spread('· ' . $this->title, $this->address, $width);
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'version' => $this->version(),
            'severity' => $this->severity->value,
            'title' => $this->title,
            'address' => $this->address,
            'metric' => $this->metric,
            'threshold' => $this->threshold,
            'recheck' => $this->recheck,
        ];
    }
}
