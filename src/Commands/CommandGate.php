<?php
declare(strict_types=1);

namespace Drift\Commands;

/**
 * 命令门控：这条命令**现在**能不能派发。
 *
 * ★ 与 `CommandHandler` 同律：具名类实例，不用闭包（可枚举 / 可单测 / 静态可达）。
 * ★ 判定住在这里而不是 `AcceleratorTable`：启用态是命令的属性，不是键位的属性。
 *   参照 Flutter 改版后的 `Action.isEnabled` / WPF `CanExecute` / Qt `QAction`。
 *
 * ★ 不替代 handler 内部校验：字段合法性属业务状态，门控只管整条命令可不可用。
 *
 * @api
 */
interface CommandGate
{
    /** false ⇒ 派发被拦、不调用 handler，框架留一条 DIAGNOSTIC */
    public function allows(string $name, CommandArg $arg): bool;
}
