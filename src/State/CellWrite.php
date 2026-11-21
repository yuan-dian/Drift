<?php
declare(strict_types=1);

namespace Drift\State;

/**
 * 一次**值写入**，包装成无参可调用对象，直接交给事件 API。
 *
 * ```php
 * Button::make('确定')->onPressed($this->lastPressed->setTo('已点'));
 * ```
 *
 * ★ 存在的理由只是 DX：事件处理器大多不是"一段逻辑"，而是"把某个格子写成某个值"。
 *   这种写入没有分支、没有局部变量，不值得让人写一层 `fn() => $cell->set(...)`。
 *   一等可调用 `$this->handler(...)` 适合有逻辑的处理器；`setTo()` 适合没有逻辑的。
 * ★ `__invoke()` 是稳定的公开契约，IDE 能给出签名提示；它返回 `CellWrite`，
 *   接收方仍按 `callable` 处理，不需要新事件 API。
 *
 * @api
 */
final class CellWrite
{
    public function __construct(
        private readonly Cell $cell,
        private readonly mixed $value,
    ) {
    }

    /** @api */
    public function __invoke(): void
    {
        $this->cell->set($this->value);
    }

    /** @internal 诊断用 */
    public function describe(): string
    {
        return $this->cell->describe() . ' <- ' . \get_debug_type($this->value);
    }
}
