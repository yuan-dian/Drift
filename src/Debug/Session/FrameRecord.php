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
     * @param list<Edge>   $causes  本帧的因果边（to 指向被重建的节点）
     * @param list<int>    $rebuilt 本帧重建过的节点 id，先序
     * @param list<string> $stages  本帧跑过的段名
     */
    public function __construct(
        public int $id,
        public float $t,
        public float $costMs,
        public array $causes,
        public array $rebuilt,
        public array $stages,
        public bool $settleSkipped,
        public bool $paintSkipped,
    ) {
    }

    public function rebuilt(int $nodeId): bool
    {
        return \in_array($nodeId, $this->rebuilt, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            't' => $this->t,
            'cost' => $this->costMs,
            'stages' => $this->stages,
            'settleSkipped' => $this->settleSkipped,
            'paintSkipped' => $this->paintSkipped,
            'rebuilt' => $this->rebuilt,
            'causes' => \array_map(static fn(Edge $e): array => $e->toArray(), $this->causes),
        ];
    }
}
