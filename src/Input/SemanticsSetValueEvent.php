<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 无障碍客户端要求**写入**一个语义节点的值（UIA `IValueProvider::SetValue` /
 * `IRangeValueProvider::SetValue`）。
 *
 * ★ 与 `SemanticsInvokeEvent` 同构（同为"平台信号 ⇒ 框架语义"），区别是它带
 *   **两个**载荷：节点索引 + 新值（string —— 数值型已由壳格式化成串）。
 * ★ 消费方：`Application::dispatchInput()` ⇒ `setSemanticsValue()`，沿命中链
 *   找实现 `SemanticsValueEditable` 的控件，调它自己的回写路径（`onChanged`）。
 * ★ 为什么也走队列（而不是壳直接回调应用）：同 `SemanticsInvokeEvent` ——
 *   `SetValue()` 跑在 COM RPC 线程，重入 PHP 会让进程静默消失；且消息泵的契约
 *   要求"一次调用交一条事件"，在派发里跑完 PHP 就没人保证下一帧会跑。
 *
 * @api
 */
final readonly class SemanticsSetValueEvent implements InputEvent
{
    /**
     * @param int $index 语义节点索引（`SemanticsTree::toArrays()['nodes']` 的下标）
     * @param string $value 新值（数值型 Pattern 已由壳格式化）
     */
    public function __construct(
        public int $index,
        public string $value,
    ) {
    }
}
