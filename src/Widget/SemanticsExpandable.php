<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 能响应"无障碍客户端要求展开/折叠某一行"的控件（§65）。
 *
 * 与 `SemanticsValueEditable` 同形、同用途：`Application` 沿命中 Element 的父链
 * 找第一个实现者，把动作交给它 —— 而不是认识具体控件类。
 *
 * ★ 为什么按 **Key** 而不是按语义节点索引：一行对应一个语义节点，但"哪一行"是
 *   控件自己的身份（`TreeView` 的 `TreeRow::$id`），语义节点只是它的一次快照。
 *   索引在两次推送之间会变，Key 不会。
 * ★ 为什么不是 `toggle()`：UIA 的 `Expand()` 与 `Collapse()` 是两条命令。
 *   读屏在已展开的行上再调 `Expand()` 必须无事发生。
 *
 * @api
 */
interface SemanticsExpandable
{
    /**
     * @param string $key 语义节点的 Key（`SemanticsNode::$key`；行控件自己的身份）
     * @param bool $expand true = 展开，false = 折叠
     * @return bool 真的处理了（未声明回调 / Key 不认识 ⇒ false，消费方据此回
     *   `UIA_E_ELEMENTNOTAVAILABLE`，不能假装成功）
     */
    public function setSemanticsExpanded(string $key, bool $expand): bool;
}
