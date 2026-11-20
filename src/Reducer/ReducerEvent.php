<?php
declare(strict_types=1);

namespace Drift\Reducer;

/**
 * 用户事件的标记接口。
 *
 * 具体事件是页面自己的值对象（`final readonly class`），`update()` 按具体类型分派。
 * 不规定字段：事件语义归页面，框架只负责把 "派发 → update → 新 model" 这条路走通。
 *
 * @api
 */
interface ReducerEvent
{
}
