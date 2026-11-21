<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * "读它就有依赖"的最小契约（读点即边界的入口）。
 *
 * ★ 只有 `get()`：写不在这里（`Cell` 才有 `set()`），缓存不在这里（`Computed` 自己管）。
 *   控件的参数日后可以收 `T|Readable` —— 传值是结构配置，传 `Readable` 是通道，
 *   由控件内部在读点订阅（A3）。
 * ★ `get()` 的副作用是**在 build 期登记依赖**，帧外读只是取值；这条语义由实现者
 *   保证，接口不管采集栈。
 *
 * @api
 */
interface Readable
{
    /** @api */
    public function get(): mixed;
}
