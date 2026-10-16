<?php
declare(strict_types=1);

namespace Drift\Widget\Drag;

/**
 * 拖动**目标**的声明（§A.3.5）。
 *
 * ```php
 * Container::make()->dragTarget(
 *     DragTarget::make()
 *         ->onAccept(fn(int $id) => $this->users->remove($id))
 *         ->onHover(fn(bool $hot) => $this->setState(fn() => $this->hot = $hot))
 * )
 * ```
 *
 * ★ **`onHover` 驱动"高亮"**：拖动经过 ⇒ `true`，移出 ⇒ `false`。只在**目标变化
 *   时**派发（与 §9.1 语义 #5 同源：不重复通知），所以回调里可以放心 `setState`。
 *   注意它与 §9.1 的 `HoverHandler`（鼠标悬停，Input 层）**不是一回事** ——
 *   这里是拖动态，归 `DragController`。
 *
 * ★ `accepts`（按 payload 类型拒收）留给需要它的应用自己接：当前只在
 *   `onAccept` 里判（不该收的就不动）。
 *
 * @api
 */
final class DragTarget
{
    /** @var null|callable(mixed): void */
    private $acceptHandler = null;

    /** @var null|callable(bool): void */
    private $hoverHandler = null;

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * 放置回调：`fn(mixed $payload) => void`。
     *
     * @param callable(mixed): void $handler
     */
    public function onAccept(callable $handler): self
    {
        $this->acceptHandler = $handler(...);

        return $this;
    }

    /**
     * 悬停回调：`fn(bool $hot) => void`（进入 / 离开各一次）。
     *
     * @param callable(bool): void $handler
     */
    public function onHover(callable $handler): self
    {
        $this->hoverHandler = $handler(...);

        return $this;
    }

    public function acceptHandler(): ?\Closure
    {
        return $this->acceptHandler;
    }

    public function hoverHandler(): ?\Closure
    {
        return $this->hoverHandler;
    }
}
