<?php
declare(strict_types=1);

namespace Drift\Route;

/**
 * 一次 `update()` 的返回：新 model + 一串 effect。
 *
 * model 是不可变的（新值，不是原地改），effect 是待执行的值。把两者一起返回，
 * `RouteState` 才知道"画面变成什么"与"顺带做点什么"是一件事的两个面。
 *
 * @api
 */
final readonly class RouteUpdate
{
    /** @param list<RouteEffect> $effects */
    public function __construct(
        public RouteModel $model,
        public array $effects = [],
    ) {
    }
}
