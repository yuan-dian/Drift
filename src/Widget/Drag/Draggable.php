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
 * ★ **指针驱动已接线**（2026-10-17，§24.2 #16）：`down` 记拖动意图，
 *   `move` 过阈值（6 逻辑像素，平方比较免开方）begin，`up` 放置 ——
 *   `Application::dispatchPointer()` 四相位派发直达 `DragController`。
 *   诚实边界：拖出窗口（无 SetCapture）`leave` 即取消。
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
