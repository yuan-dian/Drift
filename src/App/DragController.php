<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Widget\Drag\Draggable;
use Drift\Widget\Drag\DragTarget;
use Drift\Widget\Element;

/**
 * **应用内拖放的状态机**（§A.3.5 / §12.4：零平台端口）。
 *
 * ```text
 * begin(draggable) ──▶ hoverTo(命中元素) ──▶ drop() / cancel()
 *                            │
 *                            └─ 目标变化才派发 onHover(true/false)（§9.1 语义 #5 同源）
 * ```
 *
 * ★ **与焦点链同源**：拖动目标靠"命中的 Element"，而命中测试住
 *   `FocusManager`（§9.1「一次命中，三方消费」）⇒ 本类只做**状态与通知**，
 *   指针坐标 → Element 的反查由 `Application` 做（它有焦点链）。
 *
 * ★ **指针驱动已接线**（2026-10-17，§24.2 #16）：`PointerEvent` 四相位
 *   （down/move/up/leave）经 `Application::dispatchPointer()` 驱动本状态机 ——
 *   `down` 记阈值起点（**按下 ≠ 拖动**）、`move` 过 6 逻辑像素才 `begin`、
 *   `up` 放下、`leave` 取消（无 `SetCapture` 的诚实边界：拖出窗口即取消）。
 *   宿主 / 测试仍可直接调 `begin()` / `hoverTo()` / `drop()` / `cancel()`。
 *
 * @internal 实现细节（公共面是 `Draggable` / `DragTarget` 两个声明）
 */
final class DragController
{
    private ?Draggable $dragging = null;

    private ?Element $source = null;

    /** 当前悬停的 `DragTarget` 声明者（`null` = 不在任何目标上） */
    private ?Element $hover = null;

    public function begin(Draggable $draggable, ?Element $source = null): void
    {
        $this->dragging = $draggable;
        $this->source = $source;
        $this->hover = null;
    }

    public function isDragging(): bool
    {
        return $this->dragging !== null;
    }

    public function active(): ?Draggable
    {
        return $this->dragging;
    }

    /** 拖动源（拖起来的那棵子树） */
    public function source(): ?Element
    {
        return $this->source;
    }

    public function hoverTarget(): ?Element
    {
        return $this->hover;
    }

    /**
     * 指针（解析出的）当前在哪个 Element 上 ⇒ 换目标才通知。
     *
     * ★ **只在目标变化时派发**（进入 / 离开各一次）：回调里可以放心 `setState`，
     *   不会每帧重画（§9.1 语义 #5 的同一条纪律）。
     */
    public function hoverTo(?Element $element): void
    {
        if ($this->dragging === null) {
            return;
        }

        $next = self::targetOwner($element);

        if ($next === $this->hover) {
            return;
        }

        $this->leave($this->hover);
        $this->hover = $next;
        $this->enter($this->hover);
    }

    /**
     * 放下：命中目标 ⇒ 交出负载并结束拖动。
     *
     * @return bool 是否被某个 `DragTarget` 接收（`false` = 拖空了，调用方自己决定怎么提示）
     */
    public function drop(): bool
    {
        if ($this->dragging === null) {
            return false;
        }

        $payload = $this->dragging->payload();
        $target = self::targetOf($this->hover);

        // 先结束状态再回调：回调里可能开关浮层 / 重建树，此时"仍在拖动"是错的
        $this->clear();

        if ($target === null) {
            return false;
        }

        $accept = $target->acceptHandler();
        if ($accept === null) {
            return false;
        }

        $accept($payload);

        return true;
    }

    /** 取消拖动（ESC / 指针离开窗口）—— 不派发任何回调 */
    public function cancel(): void
    {
        $this->leave($this->hover);
        $this->clear();
    }

    private function clear(): void
    {
        $this->dragging = null;
        $this->source = null;
        $this->hover = null;
    }

    private function enter(?Element $owner): void
    {
        $target = self::targetOf($owner);
        $handler = $target?->hoverHandler();

        if ($handler !== null) {
            $handler(true);
        }
    }

    private function leave(?Element $owner): void
    {
        $target = self::targetOf($owner);
        $handler = $target?->hoverHandler();

        if ($handler !== null) {
            $handler(false);
        }
    }

    /** 沿父链找**最近**的 `DragTarget` 声明者（子声明覆盖祖先） */
    private static function targetOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            if ($node->widget()->dragTargetValue() !== null) {
                return $node;
            }
        }

        return null;
    }

    private static function targetOf(?Element $owner): ?DragTarget
    {
        return $owner?->widget()->dragTargetValue();
    }
}
