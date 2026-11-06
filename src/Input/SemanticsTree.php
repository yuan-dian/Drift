<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 语义树（§9 无障碍 / M5）—— **扁平节点数组 + 索引查询**。
 *
 * ★★ **树是数据，不是对象图**：节点是标量，查询一律返回 **int 索引**
 *   （-1 = 没找到），调用方拿索引再 `node($i)` 取内容。索引是标量 ⇒ 可安全放进
 *   数组、可作 Key、可跨 AOT 边界传递；返回对象数组则有退化风险。
 *
 * ★ **加性**：控件不声明角色 ⇒ 树就是空的（0 节点）。这个性质是"没接语义的
 *   应用零成本"的一半（另一半是"没人调 `SemanticsBuilder::build()` 就根本不建"）。
 *
 * ★ **查询全是线性扫** —— 语义树的规模是"有角色的控件数"（几十），不是
 *   Element 数（几千），线性足够且零维护成本（不建索引表就不存在索引过期）。
 *
 * @api
 */
final class SemanticsTree
{
    /** @var list<SemanticsNode> */
    public array $nodes = [];

    public function add(SemanticsNode $node): int
    {
        $this->nodes[] = $node;

        return count($this->nodes) - 1;
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    /**
     * 越界返回**空节点**而不是 null —— 调用方不必判空（AOT 下少一处可空对象），
     * 且"取到空节点"的语义就是"没有这个控件"，与"找到但字段为空"可区分。
     */
    public function node(int $i): SemanticsNode
    {
        if ($i < 0 || $i >= count($this->nodes)) {
            return new SemanticsNode();
        }

        return $this->nodes[$i];
    }

    /** @return int[] 直接子节点索引 */
    public function childrenOf(int $i): array
    {
        $out = [];
        foreach ($this->nodes as $k => $n) {
            if ($n->parent === $i) {
                $out[] = $k;
            }
        }

        return $out;
    }

    public function findByRole(string $role): int
    {
        foreach ($this->nodes as $k => $n) {
            if ($n->role === $role) {
                return $k;
            }
        }

        return -1;
    }

    public function findByRoleAndName(string $role, string $name): int
    {
        foreach ($this->nodes as $k => $n) {
            if ($n->role === $role && $n->name === $name) {
                return $k;
            }
        }

        return -1;
    }

    /** @return int[] */
    public function findAllByRole(string $role): array
    {
        $out = [];
        foreach ($this->nodes as $k => $n) {
            if ($n->role === $role) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /**
     * 命中测试：返回**包含 (x,y) 的最深节点**索引（-1 = 无）。
     *
     * ★ 取"最深"而非"最先"：节点是**前序**（父在子前）⇒ 首个包含该点的往往是
     *   父盒，而检查器要的是"光标下那个控件"。与 `RenderBox::hitTest()` 同口径。
     * ★ 无几何的节点（w/h ≤ 0）跳过 —— 它们"存在但不可见"，不该被点中。
     */
    public function hitTest(int $x, int $y): int
    {
        $best = -1;
        $bestDepth = -1;
        foreach ($this->nodes as $k => $n) {
            if ($n->w <= 0 || $n->h <= 0) {
                continue;
            }
            if ($x >= $n->x && $x < $n->x + $n->w && $y >= $n->y && $y < $n->y + $n->h) {
                if ($n->depth > $bestDepth) {
                    $bestDepth = $n->depth;
                    $best = $k;
                }
            }
        }

        return $best;
    }

    /**
     * 调试转储（人读的文本视图；结构化视图见 `toArrays()`）。
     *
     * ★ 用「数组 + implode」而**不是** `$s = $s . …` 增长累加 —— 后者在 tpc AOT 下
     *   遇到多字节 UTF-8 字段（控件名含中文）会**堆损坏**（崩溃延迟到后续才暴露）。
     */
    public function text(): string
    {
        $lines = [];
        $lines[] = 'semantics nodes=' . count($this->nodes);
        foreach ($this->nodes as $k => $n) {
            $lines[] = '  #' . $k . ' ' . $n->role . '/' . $n->type . '[' . $n->name . ']'
                . ' key=' . $n->key . ' v=' . $n->value . ' on=' . ($n->enabled ? '1' : '0')
                . ' rect=' . $n->x . ',' . $n->y . ',' . $n->w . ',' . $n->h
                . ' parent=' . $n->parent . ' d=' . $n->depth;
        }

        return implode("\n", $lines);
    }

    /**
     * 结构化导出（devtools 快照 `{schema,trees,semantics}` 的**语义段**形状）。
     *
     * ★ 字段的权威定义在 `SemanticsNode`，这里只是搬运工 —— 加一个语义字段只需改
     *   `SemanticsNode` + 本方法两处。关联数组是**方法局部**构造的，不进属性。
     *
     * @return array{count:int,nodes:array<int,array<string,mixed>>}
     */
    public function toArrays(): array
    {
        $ns = [];
        foreach ($this->nodes as $k => $n) {
            $ns[] = [
                'role' => $n->role,
                'type' => $n->type,
                'key' => $n->key,
                'name' => $n->name,
                'value' => $n->value,
                'rangeMin' => $n->rangeMin,
                'rangeMax' => $n->rangeMax,
                'enabled' => $n->enabled,
                'focused' => $n->focused,
                'focusable' => $n->focusable,
                // 三态各自独立成键（不要合成一个 bool）：0 = 无此概念，C++ 侧据此
                // 决定给不给对应 Pattern —— 合成 bool 会让每个节点都声称自己可选/可展开。
                'selectState' => $n->selectState,
                'expandState' => $n->expandState,
                'level' => $n->level,
                'x' => $n->x,
                'y' => $n->y,
                'w' => $n->w,
                'h' => $n->h,
                'parent' => $n->parent,
                'depth' => $n->depth,
            ];
        }

        return ['count' => count($this->nodes), 'nodes' => $ns];
    }
}
