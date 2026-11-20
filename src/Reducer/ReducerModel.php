<?php
declare(strict_types=1);

namespace Drift\Reducer;

/**
 * 页面状态快照的标记接口。
 *
 * 具体 model 用 `final readonly class` + 构造参数给初值：绕开 "属性默认值必须是
 * 常量表达式" 的 AOT 约束，也就不再需要 `xxxNow()` 惰性初始化哨兵。
 * 这里刻意不规定字段——状态是页面自己的业务知识，框架不碰。
 *
 * @api
 */
interface ReducerModel
{
}
