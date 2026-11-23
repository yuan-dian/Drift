<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 可跨进程描述的 `Job`：`ProcessTaskHost` 只能跑它。
 *
 * 跨进程只传名字 + JSON 参数数组，`name()` 与 `args()` 给 worker 侧重建地址；
 * UI 与 worker 必须共用同一份注册表。参数只能是标量或标量数组：对象、
 * resource、闭包一律不行，跨进程传对象需先摊平。
 *
 * @api
 */
interface NamedJob extends Job
{
    /** 注册表里的键（两侧必须一致；建议 `域.动作`，如 `users.fetch`） */
    public function name(): string;

    /**
     * 重建参数；键名和顺序必须等于构造器参数，缺字段或顺序错会静默装错参数。
     * 用 `JobArgs` + `private const FIELDS` 生成，判据用反射守。
     * 值只能是标量或标量数组（线程池只搬 int/str，进程池走 JSON）。
     *
     * @return array<string, mixed>
     */
    public function args(): array;
}
