<?php
declare(strict_types=1);

namespace App;

use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Widget\Element;

/**
 * 夹具：`callback-arity` 的不该响 —— 四个正确签名 + 三件"看着像但不是"。
 *
 * ① `->keyboard` 只出现在字符串里；② 非槽方法的单参闭包；
 * ③ 间接工厂返回的闭包是双参（间接形态也必须合规）。
 */
final class CallbackArityGood
{
    public function ok(): void
    {
        $this->keyboard(function (KeyEvent $event, Element $self): bool {
            return false;
        });
        $this->pointer(static function (PointerEvent $event, Element $self): void {
        });
        $this->hover(static fn(bool $entered) => null);
        $this->keyboard($this->keyFactory());

        $literal = '->keyboard(function (KeyEvent $event) {})';
        $this->other(function (KeyEvent $event): bool {
            return false;
        });
    }

    private function keyFactory(): \Closure
    {
        return function (KeyEvent $event, Element $self): bool {
            return false;
        };
    }
}
