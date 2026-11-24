<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 查询面：把录制记录读成"能回答问题的形状"。
 *
 * 纯函数：只读 {@see Recorder} 的不可变帧记录，不改状态、不碰元素树。
 * 所以可以重试、可以缓存、可以在任意线程问卷（记录本身在写时拷贝）。
 *
 * ## 两个聚合的口径不同，不要合并
 *
 * - `hotspots()` 回答"谁在写"：只聚合 `kind=state` 的根因边，按调用点 + State 类排名。
 * - `nodes()` 回答"谁在重建"：按节点聚合，含父传播。
 *
 * 一个 `setState` 会让整棵子树逐层产生 `parent` 边。把传播算进根因，排名会全是
 * 下游节点，真正的原因反而沉底。反过来只看根因，又看不出"哪个子树被牵连最重"。
 *
 * @api
 */
final class Query
{
    public function __construct(private readonly Recorder $recorder)
    {
    }

    /** @return array<string, mixed> 帧数 / 节点数 / 容量 / 最近帧耗时与重建数 */
    public function stats(): array
    {
        return $this->recorder->stats();
    }

    /**
     * 最近 N 帧的摘要，按帧号升序。
     *
     * @return list<array<string, mixed>>
     */
    public function frames(int $limit = 30, bool $rebuiltOnly = false): array
    {
        $window = $this->window($limit);
        $out = [];
        foreach ($window as $frame) {
            if ($rebuiltOnly && $frame->rebuilt === []) {
                continue;
            }
            $out[] = [
                'id' => $frame->id,
                't' => $frame->t,
                'cost' => $frame->costMs,
                'rebuilt' => \count($frame->rebuilt),
                'causes' => \count($frame->causes),
                'stageMs' => FrameRecord::toMs($frame->stageNs),
                'unattributedMs' => $frame->unattributedMs(),
                'settleSkipped' => $frame->settleSkipped,
                'paintSkipped' => $frame->paintSkipped,
            ];
        }

        return $out;
    }

    /**
     * 单帧展开：谁重建了、根因怎么分布。
     *
     * @return array<string, mixed>
     */
    public function explain(int $frameId): array
    {
        $frame = $this->recorder->frame($frameId);
        if ($frame === null) {
            throw new \InvalidArgumentException("没有这一帧：{$frameId}（缓冲窗口之外或尚未发生）");
        }

        $rebuilt = [];
        foreach ($frame->rebuilt as $id) {
            $rebuilt[] = ['id' => $id, 'path' => $this->recorder->pathOf($id)];
        }

        $byKind = ['state' => 0, 'parent' => 0, 'unknown' => 0];
        $byRoot = [];
        foreach ($frame->causes as $edge) {
            if (isset($byKind[$edge->kind])) {
                ++$byKind[$edge->kind];
            }
            if ($edge->kind !== 'state') {
                continue;
            }
            $key = $edge->site . "\0" . $edge->detail;
            $byRoot[$key] ??= ['site' => $edge->site, 'detail' => $edge->detail, 'count' => 0];
            ++$byRoot[$key]['count'];
        }

        $roots = \array_values($byRoot);
        \usort($roots, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'id' => $frame->id,
            't' => $frame->t,
            'cost' => $frame->costMs,
            'stages' => $frame->stages,
            'stageMs' => FrameRecord::toMs($frame->stageNs),
            'unattributedMs' => $frame->unattributedMs(),
            'settleSkipped' => $frame->settleSkipped,
            'paintSkipped' => $frame->paintSkipped,
            'rebuilt' => $rebuilt,
            'byKind' => $byKind,
            'roots' => $roots,
        ];
    }

    /**
     * 某个节点在某一帧为什么重建（D0 `why()` 的转发，帧缺省为最近一帧）。
     *
     * @return array<string, mixed>
     */
    public function why(int $nodeId, ?int $frameId = null): array
    {
        return $this->recorder->why($nodeId, $frameId);
    }

    /**
     * 跨帧聚合**根因**：同一调用点写同一 State 的次数。
     *
     * 只算 `kind=state`。`parent` / `unknown` 是传播与兜底，算进来会让排名失真。
     *
     * @return list<array<string, mixed>>
     */
    public function hotspots(int $frames = 300, int $limit = 10): array
    {
        $agg = [];
        foreach ($this->window($frames) as $frame) {
            foreach ($frame->causes as $edge) {
                if ($edge->kind !== 'state') {
                    continue; // 传播与兜底不是根因：算进来会让排名全是下游节点
                }
                $key = $edge->site . "\0" . $edge->detail;
                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'site' => $edge->site,
                        'detail' => $edge->detail,
                        'writes' => 0,
                        'frames' => [],
                        'nodes' => [],
                    ];
                }
                ++$agg[$key]['writes'];
                $agg[$key]['frames'][$frame->id] = true;
                $agg[$key]['nodes'][$edge->toId] = true;
            }
        }

        return $this->rank(
            \array_values(\array_map(
                static fn(array $row): array => [
                    'site' => $row['site'],
                    'detail' => $row['detail'],
                    'writes' => $row['writes'],
                    'frames' => \count($row['frames']),
                    'nodes' => \count($row['nodes']),
                ],
                $agg,
            )),
            'writes',
            'site',
            $limit,
        );
    }

    /**
     * 跨帧聚合**节点**：谁被重建得最多，以及其中多少是自身写入、多少是父传播。
     *
     * @return list<array<string, mixed>>
     */
    public function nodes(int $frames = 300, int $limit = 10): array
    {
        $agg = [];
        foreach ($this->window($frames) as $frame) {
            foreach ($frame->rebuilt as $id) {
                $agg[$id] ??= [
                    'id' => $id,
                    'path' => $this->recorder->pathOf($id),
                    'rebuilds' => 0,
                    'self' => 0,
                    'inherited' => 0,
                    'first' => $frame->id,
                    'last' => $frame->id,
                ];
                ++$agg[$id]['rebuilds'];
                $agg[$id]['last'] = $frame->id;
            }
            foreach ($frame->causes as $edge) {
                $id = $edge->toId;
                if (!isset($agg[$id])) {
                    continue;
                }
                if ($edge->kind === 'state') {
                    ++$agg[$id]['self'];
                } else {
                    ++$agg[$id]['inherited'];
                }
            }
        }

        return $this->rank(\array_values($agg), 'rebuilds', 'path', $limit);
    }

    // ------------------------------------------------------------------ 内部

    /**
     * 最近 N 帧（`$frames <= 0` 表示全窗口），按帧号升序。
     *
     * @return array<int, FrameRecord>
     */
    private function window(int $frames): array
    {
        $all = $this->recorder->frames();
        if ($frames <= 0 || \count($all) <= $frames) {
            return $all;
        }

        return \array_slice($all, -$frames, null, true);
    }

    /**
     * 按次数字段降序；并列时按键字段升序，保证输出稳定（同名同数不随帧序抖）。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function rank(array $rows, string $by, string $tie, int $limit): array
    {
        \usort($rows, static fn(array $a, array $b): int => ($b[$by] <=> $a[$by])
            ?: \strcmp((string) $a[$tie], (string) $b[$tie]));

        return $limit > 0 ? \array_slice($rows, 0, $limit) : $rows;
    }
}
