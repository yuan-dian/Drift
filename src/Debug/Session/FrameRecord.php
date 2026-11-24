<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一帧的不可变记录：时间、跑了哪些段、哪些节点重建、每条因果边。
 *
 * 只存标量与 int，可直接 `json_encode`；不持有 Element / State 引用，
 * 所以环形缓冲裁剪不会拖住一棵被卸载的树。
 *
 * @api
 */
final readonly class FrameRecord
{
    /**
     * @param list<Edge>              $causes  本帧的因果边（to 指向被重建的节点）
     * @param list<int>               $rebuilt 本帧重建过的节点 id，先序
     * @param list<string>            $stages  本帧跑过的段名
     * @param array<string, int>      $stageNs 段名 → 本帧累计纳秒（整数，跨进程序列化逐值相同）
     * @param list<string>            $incomplete 本帧证据为什么不全（空 = 全）
     */
    public function __construct(
        public int $id,
        public float $t,
        public float $costMs,
        public array $causes,
        public array $rebuilt,
        public array $stages,
        public array $stageNs,
        public bool $settleSkipped,
        public bool $paintSkipped,
        /**
         * 证据不完整的原因，值域见 {@see self::CAUSES_TRUNCATED} / {@see self::ABORTED}。
         *
         * ★ 为什么是一个列表而不是几个布尔：不完整的原因会越加越多（因果边超限、
         *   帧内抛异常、将来可能还有几何采样失败），每加一个就多一个字段，投影层
         *   就得多认一个名字 —— 而漏认一个，报告就把"不完整"显示成"干净"。
         *   一个列表 + 一组常量，新增原因不用改形状。
         *
         * ★ 默认 `[]` 是为了构造兼容：这个类被测试和反序列化路径直接 new。
         */
        public array $incomplete = [],
    ) {
    }

    /** 因果边撞到 `Recorder::MAX_CAUSES` 上限：这一帧的树是缺的。 */
    public const string CAUSES_TRUNCATED = 'causes-truncated';

    /** 帧内抛了异常：`rebuilt` / `costMs` 都是半截，不能当正常帧读。 */
    public const string ABORTED = 'aborted';

    public function rebuilt(int $nodeId): bool
    {
        return \in_array($nodeId, $this->rebuilt, true);
    }

    /**
     * 父边索引：`toId => fromId`，收**任何带 `from` 的边**。
     *
     * 树的一切结构都从这一张表长出来：单链、子树大小、根到目标的路径。
     * 放在记录上而不是查询里，是为了 `why` 与 `cascade` 不各算一遍父子关系
     * （同一件事写两遍必漂，这是本仓反复踩过的）。
     *
     * ★ 早先这里只收 `kind=parent`，于是"自己写了状态"的节点在树里没有父 ——
     *   它的父明明也重建了，却在 `cascade` 里另起一个根，同一次级联被画成两棵
     *   并行的树（表格页：`PageShell 18` 与 `Table 342`）。`state` 边的 `from`
     *   现在也记结构父，嵌套关系才保得住。因果链的终点由 `kind` 决定：
     *   `state` 是"为什么"的答案本身（见 {@see Recorder::why()}）。
     *
     * @return array<int, int>
     */
    public function parentOf(): array
    {
        $out = [];
        foreach ($this->causes as $edge) {
            if ($edge->fromId !== null) {
                $out[$edge->toId] = $edge->fromId;
            }
        }

        return $out;
    }

    /**
     * 每节点一条归因边：`toId => Edge`（录制时同帧同节点已去重）。
     *
     * @return array<int, Edge>
     */
    public function causeOf(): array
    {
        $out = [];
        foreach ($this->causes as $edge) {
            $out[$edge->toId] = $edge;
        }

        return $out;
    }

    /**
     * 纳秒账 → 毫秒，只保留有耗时的段并降序：0ns 的段列出来只会淹没真正的开销。
     *
     * @param array<string, int> $ns
     * @return array<string, float>
     */
    public static function toMs(array $ns): array
    {
        $out = [];
        foreach ($ns as $stage => $nanos) {
            if ($nanos > 0) {
                $out[(string) $stage] = \round($nanos / 1000000.0, 4);
            }
        }
        \arsort($out);

        return $out;
    }

    /** 段外耗时：帧内成本减掉所有已归段。负数视作 0（计时口径不同，不让负数骗人）。 */
    public function unattributedMs(): float
    {
        $sum = 0.0;
        foreach ($this->stageNs as $nanos) {
            $sum += $nanos / 1000000.0;
        }

        return \round(\max(0.0, $this->costMs - $sum), 4);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            't' => $this->t,
            'cost' => $this->costMs,
            'stages' => $this->stages,
            'stageMs' => self::toMs($this->stageNs),
            'unattributedMs' => $this->unattributedMs(),
            'settleSkipped' => $this->settleSkipped,
            'paintSkipped' => $this->paintSkipped,
            // 空列表也照样输出：消费者不必判"这个键在不在"，只需判它空不空。
            'incomplete' => $this->incomplete,
            'rebuilt' => $this->rebuilt,
            'causes' => \array_map(static fn(Edge $e): array => $e->toArray(), $this->causes),
        ];
    }

    /**
     * 证据包里的形状：**无损**，与 {@see self::toArray()} 刻意不同。
     *
     * `toArray()` 给的是投影（`stageMs` 四位小数 + 派生的 `unattributedMs`），
     * 拿它序列化会在往返里丢掉纳秒精度——导入后再导出数值就漂了，而"逐值相同"
     * 是证据包的全部意义。这里只写**构造器收到的那些值**。
     *
     * @return array<string, mixed>
     */
    public function encode(): array
    {
        return [
            'id' => $this->id,
            't' => $this->t,
            'cost' => $this->costMs,
            'stages' => $this->stages,
            'stageNs' => $this->stageNs,
            'settleSkipped' => $this->settleSkipped,
            'paintSkipped' => $this->paintSkipped,
            'incomplete' => $this->incomplete,
            'rebuilt' => $this->rebuilt,
            'causes' => \array_map(static fn(Edge $e): array => $e->encode(), $this->causes),
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function decode(array $raw): self
    {
        /** @var list<array<string, mixed>> $causes */
        $causes = \array_values(\array_filter((array) ($raw['causes'] ?? []), '\is_array'));

        $stageNs = [];
        foreach ((array) ($raw['stageNs'] ?? []) as $stage => $nanos) {
            $stageNs[(string) $stage] = (int) $nanos;
        }

        return new self(
            (int) ($raw['id'] ?? 0),
            (float) ($raw['t'] ?? 0.0),
            (float) ($raw['cost'] ?? 0.0),
            \array_map(static fn(array $edge): Edge => Edge::decode($edge), $causes),
            \array_map('\intval', \array_values((array) ($raw['rebuilt'] ?? []))),
            \array_map('\strval', \array_values((array) ($raw['stages'] ?? []))),
            $stageNs,
            (bool) ($raw['settleSkipped'] ?? false),
            (bool) ($raw['paintSkipped'] ?? false),
            \array_map('\strval', \array_values((array) ($raw['incomplete'] ?? []))),
        );
    }
}
