<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Widget\TableSelection;

/**
 * 承接"控件上报的选中集"—— 受控表的应用侧就是 `use (&$next)` 那个引用。
 *
 * ★ 不直接把 `&$sink` 传进 `Fixtures::selectable()`：PHP 的引用参数在返回类型
 *   与箭头函数里都会被静默破坏，出问题的现象是"断言永远是 null"。用一个一字段
 *   的对象把身份固定住，读的时候一眼看出是谁在接。
 */
final class SelectionSink
{
    public ?TableSelection $last = null;

    public int $notifyCount = 0;

    public function receive(TableSelection $next): void
    {
        $this->last = $next;
        ++$this->notifyCount;
    }
}
