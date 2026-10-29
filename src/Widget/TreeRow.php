<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * `TreeView` 的**展平行** —— 递归的树在这一层被压成定长的行描述。
 *
 * ★ **`@internal`，不是 API**：应用只写 `TreeNode`（递归、自然），展平是控件
 *   内部的事。这一层之所以是独立类型而不是 `array{node: TreeNode, depth: int}`：
 *
 *   1. `TreeView` 的行构建器要**按值**读 `id/label/depth/expanded` 四个字段。
 *      写成数组就是"取下标再取属性"——`Select::labelAt()` 的注释记着同款坑
 *      （AOT 侧会被推成 `php::Var`，解释器面全绿、真机才炸）。
 *   2. 行构建器是把 `LazyColumn` 留在渲染树上的闭包，它**不能捕获 `$this`
 *      （见 `LazyGrid::build()` 的注释）⇒ 只能拿"纯数据"。一个类型化的对象
 *      正好是"纯数据"的边界。
 *
 * ★ **没有 `node` 反向引用**：行只需要"怎么画"，不需要回到原节点。留住
 *   `TreeNode` 会让"改一行"变成"改整棵树"的别名——`TreeNode` 是 readonly 的
 *   所以不会错，但闭包会跟着把整棵树留在渲染树上。
 */
final class TreeRow
{
    public function __construct(
        /** 节点身份（也是 `LazyColumn::keyOf()` 的锚点键） */
        public readonly string $id,
        public readonly string $label,
        /** 层级深度（0 = 根层），缩进 = `depth × indent` */
        public readonly int $depth,
        /** 有子节点 ⇒ 画箭头；叶子画等宽空位（保对齐） */
        public readonly bool $hasChildren,
        /** 当前是否展开（`false` 且 `hasChildren` ⇒ 箭头朝右） */
        public readonly bool $expanded,
    ) {
    }
}
