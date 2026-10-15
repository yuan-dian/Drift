<?php
declare(strict_types=1);

namespace App;

use Drift\Widget\Each;
use Drift\Widget\Text;

/**
 * 夹具：`alias-mutate` 的已知错误（§16.6.3 #11 / A12）。
 *
 * 该响：
 * - `children([$t->title(), $t->bold()])` —— 同一个 `$t` 用了两次 ⇒ error
 *
 * 不该响：
 * - `children([$t->title(), Text::make('y')])` —— 不同根
 * - `children([Each::make(fn(User $u) => $u->name())->items($users)])`
 *   —— `$u` / `$users` 都不在元素起点，不许误响
 */
final class AliasChildrenFixture
{
    public function build(): void
    {
        $t = Text::make('x');

        $this->row->children([$t->title(), $t->bold()]);
        $this->row->children([$t->title(), Text::make('y')]);
        $this->row->children([Each::make(fn(User $u) => $u->name())->items($users)]);
    }
}
