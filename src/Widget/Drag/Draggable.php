<?php
declare(strict_types=1);

namespace Drift\Widget\Drag;

use Drift\Widget\Widget;

/**
 * 拖动**源**的声明（§A.3.5，应用内拖放，零平台端口）。
 *
 * ```php
 * Row::make()->children([...])->draggable(Draggable::make(payload: $user->id))
 * ```
 *
 * ★ **拖的是数据，不是控件**：`$payload` 原样交给命中的 `DragTarget` ——
 *   框架不认识业务类型（同 §11.4.3 命令的"意图"纪律）。
 *
 * ★ **指针驱动的诚实边界**（§9.1 输入层分期）：拖动要 `move`（过阈值才开始）与
 *   `up`（放置），而 `PointerEvent` 今天只有 `down()`（M4 补）⇒ 本轮落地的是
 *   **声明 + 状态机**（`Application::beginDrag()` / `updateDrag()` / `dropDrag()`），
 *   由宿主或测试驱动；真机指针序列接在 M4。与 `Spinner` 的 `tick()`、
 *   `Button` 的指针激活同一个分期形态。
 *
 * ★ `feedback`（拖动时跟着指针画的浮层）**本轮不画**：它要"跟随指针的第三棵树"
 *   （GUI 仓 2026-10-07 叠的提示层），归 M4 的 `FrameClock` + 悬停层一起做。
 *
 * @api
 */
final class Draggable
{
    private mixed $data = null;

    /** 拖动反馈的**形状描述**（本轮只存不画，见类 docblock） */
    private ?Widget $feedback = null;

    private function __construct()
    {
    }

    public static function make(mixed $payload = null, ?Widget $feedback = null): self
    {
        $self = new self();
        $self->data = $payload;
        $self->feedback = $feedback;

        return $self;
    }

    public function payload(): mixed
    {
        return $this->data;
    }

    public function feedback(): ?Widget
    {
        return $this->feedback;
    }
}
