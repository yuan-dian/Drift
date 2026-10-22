<?php
declare(strict_types=1);

namespace App;

use Drift\Widget\Widget;

/**
 * 夹具：调 `@internal` **方法** —— 该响（warn，方法级 `internal-leak`）
 *
 * 三种接收者各一条，覆盖 `SourceFile::calls()` 的三条解析路径：
 *   ① `$this->` ⇒ 沿 `extends` 链找声明
 *   ② `Widget::` ⇒ 静态调用，类名直接可解析
 *   ③ `$var->` ⇒ 类型来自参数声明（`noteTypedVariable`）
 */
final class MethodBad extends Widget
{
    public function run(Widget $target): void
    {
        $this->internalHook();
        Widget::internalStatic();
        $target->internalHook();
    }
}
