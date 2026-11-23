<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 无障碍客户端要求激活一个语义节点（UIA `IInvokeProvider::Invoke()`）。
 *
 * 必须走输入事件：Invoke 跑在 COM RPC 线程，不能重入 PHP（进程会静默消失）；
 * 在消息泵里直接跑 PHP 又可能下一帧不跑，状态变了但画面不动。
 * 事件入队后交 `EventLoop`，紧接着 `FrameClock::advance()`，重建当轮落盘。
 *
 * `Application::dispatchInput()` 在节点中心派发合成 tap，读屏点与鼠标点共用一条路。
 * 索引是推给平台的那份语义快照的前序下标；越界 / 零面积 ⇒ 消费方返回 false。
 *
 * @api
 */
final readonly class SemanticsInvokeEvent implements InputEvent
{
    /**
     * @param int $index 语义节点索引（`SemanticsTree::toArrays()['nodes']` 的下标）
     */
    public function __construct(
        public int $index,
    ) {
    }
}
