<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 绘制域查询：这一帧画了谁、省了谁、哪些边界反复重录。
 *
 * 只读 {@see Recorder} 的绘制窗口（独立 60 帧）。
 *
 * ## 为什么与几何分成两个类而不是一个 `frameFacts()`
 *
 * 几何是"节点 × 帧"的二维事实，绘制是"帧 × 节点集合"的一维事实。合成一个对象
 * 会让两边都开始按对方的口径补字段（几何跟着报 `hits`、绘制跟着报 `applied`），
 * 而它们根本不是同一件事。
 *
 * ## 没有"往前找最近一帧"
 *
 * 几何按变化存，所以几何域会往前找；绘制**每帧都落一条**（哪怕这帧什么都没画，
 * 也是 `painted: []`）。因此查不到就是"那一帧没采"，不存在"用上一帧代替"——
 * 往前找会把"这帧没画"说成"这帧画了上一帧的那些"。
 *
 * @internal 门面 {@see Query} 的实现细节，不是应用开发者的 API 面
 */
final class PaintQuery
{
    public function __construct(private readonly Recorder $recorder)
    {
    }

    /**
     * 一帧的**绘制事实**：画了哪些节点、层缓存命中几次、哪些边界因什么重录。
     *
     * `captured=false` 与"这一帧没画"分开报：前者是预算没开，后者是 `painted: []`。
     *
     * @return array<string, mixed>
     */
    public function paint(?int $frame = null): array
    {
        $window = $this->recorder->paintCoverage();
        // 缺省帧落在**自己的**覆盖窗口里。绘制窗口比帧窗口窄得多，拿帧窗口的最新帧
        // 当缺省，静止一会儿就查不到，而页面会把它读成"这帧没画"。
        $frameId = $frame
            ?? ((int) ($window['to'] ?? 0) ?: $this->recorder->lastFrameId());

        if (($window['captured'] ?? false) !== true) {
            // 未开预算时不能给空集合：那会被读成"这帧什么都没画"，而真相是没采。
            return [
                'frame' => $frameId,
                'found' => false,
                'reason' => 'paint-not-captured',
                'window' => $window,
            ];
        }

        $record = $this->recorder->paintFrame($frameId);
        if ($record === null) {
            return [
                'frame' => $frameId,
                'found' => false,
                'reason' => $frameId < (int) $window['from'] || $frameId > (int) $window['to']
                    ? 'frame-out-of-window'
                    : 'no-paint-record',
                'window' => $window,
            ];
        }

        return ['found' => true] + $record->toArray() + ['window' => $window];
    }

    /**
     * 跨帧聚合**重画**：谁每帧都在画、哪些边界每次都在重录。
     *
     * ## 为什么需要聚合，而不是让投影层逐帧读 `paint()`
     *
     * 绘制窗口 60 帧，单帧最多 4096 个节点。逐帧读 = 60 次查询、24 万个节点号，
     * 远端会话还要 60 个往返。而规则要问的只有"这个节点在窗口里被画了几帧"——
     * 聚合在查询层做一次，答案是一个几十行的表。
     *
     * ## `frames` 是分母，必须回显
     *
     * `nodes[].frames` 是"窗口里有几帧画过它"，`frames` 是"窗口里有几帧"。读者
     * 拿前者比后者才知道是不是"每帧都画"。少了分母，`frames: 5` 看着像"画了 5 次
     * 挺少的"，其实窗口里只有 5 帧。
     *
     * ## 命中率的分子分母都是**边界次数**，不是节点数
     *
     * 一次命中意味着整棵子树一条 `paint()` 都没跑。把省掉的节点算进分母，会让
     * "边界放在大树顶上"看起来命中率极低 —— 那是把收益算成了成本。
     *
     * @param int $frames 只看最近 N 个绘制帧（`<= 0` = 全窗口）
     * @param int $limit  节点表最多几行（`0` = 不截断）；`nodesTotal` 永远给真实行数
     * @return array<string, mixed>
     */
    public function repaints(int $frames = 300, int $limit = 10): array
    {
        $window = $this->recorder->paintCoverage();
        if (($window['captured'] ?? false) !== true) {
            return [
                'captured' => false,
                'frames' => 0,
                'hits' => 0,
                'misses' => 0,
                'hitRate' => 0.0,
                'byReason' => [],
                'nodes' => [],
                'nodesTotal' => 0,
                'window' => $window,
            ];
        }

        $ids = $this->recorder->paintFrames();
        if ($frames > 0 && \count($ids) > $frames) {
            $ids = \array_slice($ids, -$frames);
        }

        $hits = 0;
        $misses = 0;
        $byReason = [];
        /** @var array<int, array{node: int, frames: int, misses: int, reason: string}> $agg */
        $agg = [];
        foreach ($ids as $id) {
            $record = $this->recorder->paintFrame($id);
            if ($record === null) {
                continue;
            }
            $hits += $record->layerHits;
            $misses += \count($record->misses);

            foreach ($record->misses as $node => $reason) {
                $agg[$node] ??= ['node' => $node, 'frames' => 0, 'misses' => 0, 'reason' => ''];
                ++$agg[$node]['misses'];
                // 留**最近一次**原因：窗口里原因可能变（先 resized 后 invalidated），
                // 而"最近一次为什么"才是下一帧要修的那个。
                $agg[$node]['reason'] = $reason;
                $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            }
            foreach ($record->painted as $node) {
                $agg[$node] ??= ['node' => $node, 'frames' => 0, 'misses' => 0, 'reason' => ''];
                ++$agg[$node]['frames'];
            }
        }

        $rows = \array_values($agg);
        // 三级排序：先按"画过几帧"（每帧都画的最该看），再按"重录几次"（边界反复失效），
        // 最后按节点号保证输出稳定 —— 否则同一份证据两次查询的行序可能不同。
        \usort($rows, static fn(array $a, array $b): int => ($b['frames'] <=> $a['frames'])
            ?: ($b['misses'] <=> $a['misses'])
            ?: ($a['node'] <=> $b['node']));

        $total = \count($rows);
        $boundary = $hits + $misses;

        // 路径与类型在**裁完之后**才查：`pathOf()` 要沿父链拼一串，4000 个节点全查
        // 一遍而只显示 10 行，是把代价花在没人看的行上。排序只用标量，所以裁在前。
        $shown = $limit > 0 ? \array_slice($rows, 0, $limit) : $rows;
        foreach ($shown as $i => $row) {
            $shown[$i] = [
                'node' => $row['node'],
                'path' => $this->recorder->pathOf($row['node']),
                'type' => $this->recorder->typeOf($row['node']),
                'frames' => $row['frames'],
                'misses' => $row['misses'],
                'reason' => $row['reason'] === '' ? null : $row['reason'],
            ];
        }

        return [
            'captured' => true,
            'frames' => \count($ids),
            'hits' => $hits,
            'misses' => $misses,
            'hitRate' => $boundary === 0 ? 0.0 : $hits / $boundary,
            'byReason' => $byReason,
            'nodes' => $shown,
            'nodesTotal' => $total,
            'window' => $window,
        ];
    }
}
