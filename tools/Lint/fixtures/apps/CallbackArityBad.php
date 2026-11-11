<?php
declare(strict_types=1);

namespace App;

use Drift\Input\KeyEvent;

/** 夹具：`callback-arity` 的已知错误 —— 内联 3 条 + 间接工厂 1 条。 */
final class CallbackArityBad
{
    public function inline(): void
    {
        $this->keyboard(function (KeyEvent $event): bool {
            return false;
        });
        $this->pointer(static fn($event) => null);
        $this->hover(function (bool $entered, bool $extra): void {
        });
    }

    private function keyFactory(): \Closure
    {
        return function (KeyEvent $event): bool {
            return false;
        };
    }

    public function indirect(): void
    {
        $this->keyboard($this->keyFactory());
    }
}
