<?php
declare(strict_types=1);

namespace Drift\Reducer;

/**
 * 一次 `update()` 的返回：新 model + 一串 effect。
 *
 * model 是不可变的（新值，不是原地改），effect 是待执行的值。把两者一起返回，
 * `ReducerState` 才知道"画面变成什么"与"顺带做点什么"是一件事的两个面。
 *
 * @api
 */
final readonly class ReducerUpdate
{
    /** @param list<ReducerEffect> $effects */
    public function __construct(
        public ReducerModel $model,
        public array $effects = [],
    ) {
    }
}
