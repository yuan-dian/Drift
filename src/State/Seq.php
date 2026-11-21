<?php
declare(strict_types=1);

namespace Drift\State;

/**
 * attempt 发号器：每次请求 / 定时器领一个号，答案带号回来才知道过不过期。
 *
 * ★ 从 1 起，0 留给"没有在途请求"——状态里 int 0 就是 Idle，不必用 nullable
 *   （`?Attempt` 在 AOT 侧退化 `php::Var`，见 PITFALLS）。
 * ★ 号是 int 而非 opaque 对象：PHP 是动态语言，意图靠命名（`attempt`）表达即可；
 *   唯一性由本类保证，调用方不自己 ++。
 *
 * @api
 */
final class Seq
{
    private int $last = 0;

    /** @api */
    public function next(): int
    {
        return ++$this->last;
    }
}
