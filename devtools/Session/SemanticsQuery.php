<?php
declare(strict_types=1);

namespace Drift\DevTools\Session;

use Drift\Input\SemanticsTree;

/**
 * 无障碍域（A77）：把**生产语义树**读成可追问的形状，L2 内省，不是证据。
 *
 * ## 为什么单独一域
 *
 * 语义树既不是记录域（不写 `Recorder`、不进包、不进指纹），也不是 `LiveQuery`
 * 那种"反射读某个对象的字段"：它回答的是"读屏看到什么"，有自己的口径——
 * 前序下标、三态字段、零面积节点、命中取最深。混进 `LiveQuery` 会让这些口径
 * 被旁边 `props` 的节点号语义带跑，而跑偏的表现是"报告说按钮可用、读屏说不可用"。
 *
 * ## 下标不是结构节点号
 *
 * `index` 是 `toArrays()['nodes']` 的**前序下标**，与推给 UIA 的快照下标同源。
 * 语义节点不持 `Element`，这里不伪造一个结构节点号去喂 `why(node)` /
 * `props(node)`；两个坐标系各自单列，谁想要跨界跳转得先有中立 id 桥（A77 失效条件）。
 *
 * @internal 门面 {@see Query} 的实现细节，不是应用开发者的 API 面
 */
final class SemanticsQuery
{
    public function __construct(private readonly History $history)
    {
    }

    /**
     * 整棵树（前序扁平行）。
     *
     * ★ 字段直接取 {@see SemanticsTree::toArrays()}：那是 `SemanticsNode` 的搬运工，
     *   在 devtools 里再抄一份字段表，加字段时只会漏这一边。语义树的规模是
     *   "有角色的控件数"（几十），先导出再截断的代价可以接受。
     *
     * @return array<string, mixed>
     */
    public function tree(?SemanticsTree $tree, int $limit = 200): array
    {
        $base = [
            'liveFrame' => $this->history->lastFrameId(),
            'indexBasis' => 'uia-snapshot',
        ];
        if ($tree === null) {
            return $base + [
                'found' => false,
                'reason' => 'semantics-unavailable',
                'rows' => [],
                'count' => 0,
            ];
        }

        $all = $tree->toArrays();
        $count = (int) $all['count'];
        $take = $limit > 0 ? \min($limit, $count) : $count;
        $rows = [];
        for ($i = 0; $i < $take; $i++) {
            $rows[] = ['index' => $i] + $all['nodes'][$i];
        }

        return $base + [
            'found' => true,
            'reason' => null,
            'rows' => $rows,
            'count' => $count,
            'limit' => $limit,
            'truncated' => $take < $count,
        ];
    }

    /**
     * 单个语义节点。下标越界与"没有活树"分开报：前者是地址写错，后者是离线包。
     *
     * @return array<string, mixed>
     */
    public function node(?SemanticsTree $tree, int $index): array
    {
        $base = [
            'index' => $index,
            'liveFrame' => $this->history->lastFrameId(),
        ];
        if ($tree === null) {
            return $base + ['found' => false, 'reason' => 'semantics-unavailable'];
        }
        if ($index < 0 || $index >= $tree->count()) {
            return $base + [
                'found' => false,
                'reason' => 'semantics-index-out-of-range',
                'count' => $tree->count(),
            ];
        }

        $node = $tree->toArrays()['nodes'][$index];

        return $base + ['found' => true, 'reason' => null] + $node;
    }

    /**
     * 按视口坐标取包含该点的最深语义节点（与 `SemanticsTree::hitTest()` 同一实现）。
     *
     * ★ 零面积节点跳过、重叠取最深：用生产命中器而不是在 devtools 里重扫一遍，
     *   否则"读屏点不中"与"报告说点中了"会同时成立。
     *
     * @return array<string, mixed>
     */
    public function hit(?SemanticsTree $tree, float $x, float $y): array
    {
        $base = [
            'x' => $x,
            'y' => $y,
            'liveFrame' => $this->history->lastFrameId(),
        ];
        if ($tree === null) {
            return $base + ['found' => false, 'reason' => 'semantics-unavailable', 'index' => -1];
        }

        // 语义节点坐标是逻辑像素整数；先四舍五入再交生产命中器，别在查询层
        // 自己写包含判断（那会与 UIA 的 BoundingRectangle 口径分叉）。
        $index = $tree->hitTest((int) \round($x), (int) \round($y));
        if ($index < 0) {
            return $base + ['found' => false, 'reason' => 'no-semantic-hit', 'index' => -1];
        }

        $node = $tree->toArrays()['nodes'][$index];

        return $base + ['found' => true, 'reason' => null, 'index' => $index] + $node;
    }
}
