<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 无障碍客户端要求**展开/折叠**一个语义节点（UIA `IExpandCollapseProvider`，§65）。
 *
 * 触发场景：读屏软件在树行上按 `→` / 双击、自动化脚本调 `ExpandCollapsePattern`。
 *
 * ★ 与 `SemanticsInvokeEvent` 同构（同为"平台信号 ⇒ 框架语义"、同走输入队列），
 *   两处差别各有理由：
 *
 *   1. **带方向**：`Expand()` 与 `Collapse()` 是两条命令，**不是 toggle**。
 *      读屏在已展开的行上再调 `Expand()` 必须无事发生；做成 toggle 就会把它折起来。
 *   2. **不能靠"合成 tap"落地**：行的 tap 语义是**选中**（`onSelected`），
 *      展开是另一件事。所以它落到 `Application::setSemanticsExpanded()`，
 *      沿命中 Element 的父链找 `TreeView`（与 `setSemanticsValue()` 找
 *      `SemanticsValueEditable` 同形）。
 *
 * ★ 索引口径与 `SemanticsInvokeEvent` 一致：推给平台那份快照的前序下标。
 *   越界 / 目标不支持展开 ⇒ `Application` 返回 `false`，壳回
 *   `UIA_E_ELEMENTNOTAVAILABLE`（不能假装成功）。
 *
 * @api
 */
final readonly class SemanticsExpandEvent implements InputEvent
{
    /**
     * @param int $index 语义节点索引（`SemanticsTree::toArrays()['nodes']` 的下标）
     * @param bool $expand true = 展开，false = 折叠
     */
    public function __construct(
        public int $index,
        public bool $expand,
    ) {
    }
}
