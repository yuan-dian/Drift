<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 能响应"无障碍客户端要求展开 / 折叠某一行"的控件。
 *
 * Application 沿命中 Element 父链找第一个实现者，不认具体控件类。
 * 用 Key 而不是语义节点索引：索引两次推送之间会变，Key 是控件自己的身份。
 * Expand 与 Collapse 是两条命令；已展开再 Expand 必须无事发生。
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
