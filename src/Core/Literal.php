<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 把常量值装成 `Readable` —— 给"参数二象性"的值形态用。
 *
 * ★ 用途单一：控件参数既能收通道（`Cell`）也能收值；值形态包成本类，
 *   控件内部就**只有一条读取路径**（`Readable::get()`），不必到处判类型。
 *   AOT 下 union 会退化成 `php::Var`，所以走"统一接口 + 包装"而不是 `T|Readable`。
 * ★ `get()` 不碰 `Tracker`（没有可订阅的东西）⇒ 包常量不会给读取方添依赖。
 *
 * @internal 框架内部适配用；应用传值走控件自己的 `xxx(值)` 方法
 */
final class Literal implements Readable
{
    public function __construct(private mixed $value)
    {
    }

    public function get(): mixed
    {
        return $this->value;
    }
}
