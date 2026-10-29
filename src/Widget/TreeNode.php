<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 树里的一个节点（`TreeView` 的数据单元，A3 第二件，2026-10-29）。
 *
 * ```php
 * TreeNode::branch('root', '项目', [
 *     TreeNode::make('src', 'src'),
 *     TreeNode::branch('docs', 'docs', [TreeNode::make('readme', 'README.md')]),
 * ]);
 * ```
 *
 * ## 为什么是 `id` + `label`，不是一个 `label`、也不是 `value`
 *
 * 与 `Option` 同一条纪律：**身份与显示名分开**。展开集合与选中值都按 `id` 认，
 * 不按下标 —— 树一旦被过滤 / 重排 / 局部替换，"第 3 个节点"就不是原来那个了。
 * 名字会改（改名后仍是同一份文件），下标会漂（插一个兄弟就全错位），`id` 不会。
 *
 * ## 为什么空 `id` 要响亮失败
 *
 * `id === ''` 的节点**永远无法被展开、也永远无法被选中**：展开集合里空串与
 * "没有集合"分不开，而"没选中"的哨兵是 `null`。症状是"这一行点了没反应"，
 * 报错位置离现场十万八千里。与 `Option::normalize()` 拒空键同族。
 *
 * ★ **纯值对象**（同 `Option`）：`public readonly`、无 `mutate()`、不是 Widget
 *   —— 它不需要封存位，也不需要 `key`。
 *
 * @api
 */
final class TreeNode
{
    /** @var list<TreeNode> 子节点（叶子为空表；顺序即显示顺序） */
    public readonly array $children;

    /**
     * @param string         $id       稳定身份（展开集合 / 选中值都用它）
     * @param string         $label    给人看的显示名
     * @param list<TreeNode> $children 子节点
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        array $children = [],
    ) {
        if ($id === '') {
            Errors::report(
                'TreeNode 的 id 不能是空串 —— 展开集合与选中值都靠它认节点，'
                    . "\n" . '空串的节点点了没反应、也不报错。给它一个稳定身份（路径 / 主键 / 名字都行）。',
            );
        }

        self::assertNodes($children, 'TreeNode::branch() 的 children');

        $this->children = \array_values($children);
    }

    /** 叶子节点 */
    public static function make(string $id, string $label): self
    {
        return new self($id, $label);
    }

    /**
     * 有子的节点。
     *
     * ★ 与 `make()` 的差别只在**读得出来**（构造器两处都收 `children`）：写成
     *   `branch(..., [...])` 时"这一层有子"在调用点就看得见，不必数方括号。
     *
     * @param list<TreeNode> $children
     */
    public static function branch(string $id, string $label, array $children): self
    {
        return new self($id, $label, $children);
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /**
     * 校验"一组节点"—— 本类的 `children` 与 `TreeView::make()` 的顶层共用。
     *
     * ★ 抽出来而不是两处各写一遍：报错口径必须一致，否则同一份数据在"顶层"和
     *   "某一层"里看到的提示不一样，应用会以为自己写错了两类东西。
     *
     * @param array<array-key, mixed> $nodes
     *
     * @internal
     */
    public static function assertNodes(array $nodes, string $where): void
    {
        foreach ($nodes as $index => $node) {
            if ($node instanceof self) {
                continue;
            }

            // ★ 不用 `get_debug_type()` / `gettype()`：前者的函数映射在 tpc 侧没有
            //   先例，后者对对象只给 'object'。手写三元与 `Option::fromRows()`
            //   同口径（那里也是 `is_array ? 'array' : (is_object ? 'object' : 'null')`）。
            $kind = 'null';
            if (\is_array($node)) {
                $kind = 'array';
            } elseif (\is_object($node)) {
                $kind = 'object';
            } elseif (\is_string($node)) {
                $kind = 'string';
            } elseif (\is_scalar($node)) {
                $kind = 'scalar';
            }

            Errors::report(\sprintf(
                '%s 的第 %s 个元素不是 TreeNode（是 %s）。'
                    . "\n" . '叶子写 `TreeNode::make($id, $label)`，有子写 `TreeNode::branch($id, $label, [...])`。',
                $where,
                \var_export($index, true),
                $kind,
            ));
        }
    }
}
