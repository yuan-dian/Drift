<?php
declare(strict_types=1);

namespace App;

use Drift\Widget\Widget;

/**
 * 夹具：方法级 `internal-leak` 的**反例**（不该响）
 *
 * 三种"看着像泄漏但不是"的形态：
 *   ① 调 `@api` 的继承方法
 *   ② 调**自己覆写**的同名方法（`@internal` 在基类，覆写后就是应用自己的方法）
 *   ③ 接收者解析不出来（`foo()->m()`）⇒ 规则**不猜**，宁可漏也不误报
 */
final class MethodGood extends Widget
{
    #[\Override]
    public function internalHook(): void
    {
    }

    public function run(): void
    {
        $this->publicHook();
        $this->internalHook();
        $this->chain()->internalHook();
    }

    private function chain(): Widget
    {
        return $this;
    }
}
