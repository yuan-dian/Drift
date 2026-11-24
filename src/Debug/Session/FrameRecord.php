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
    ) {
    }

    public function rebuilt(int $nodeId): bool
    {
        return \in_array($nodeId, $this->rebuilt, true);
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
            'rebuilt' => $this->rebuilt,
            'causes' => \array_map(static fn(Edge $e): array => $e->toArray(), $this->causes),
        ];
    }
}
