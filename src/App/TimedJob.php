<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 自己声明墙钟上限的 `Job`—— 与 Celery 的 `time_limit` 同形。
 *
 * ★ 为什么由任务声明：只有它知道批大小与单条耗时上限。`ProcessTaskHost` 只看得到
 *   一行 JSON，而「一批 N 个文件、每个最多 300s」在组合根算不出来
 *   （`items` 由用户选文件产生，没有数量上界）。
 *
 * ★ 与 `NamedJob` 正交：一个说"怎么跨进程重建"，一个说"允许跑多久"。
 *   业务类通常两个都实现。
 *
 * ★ `0` = 不声明 ⇒ 回落到 `ProcessTaskHost` 的默认上限（30s），**不是**"不限"。
 *   要真正关掉清扫，仍然只能把 Host 的 `requestTerminateMs` 设成 0。
 *
 * @api
 */
interface TimedJob extends Job
{
    /**
     * 这条任务允许的最长墙钟（毫秒，从派发给 worker 起算）。
     *
     * ★ 写成「最坏耗时 + 余量」，不要写"我希望它多快"：它只用来判卡死，
     *   不是性能预算。
     */
    public function timeoutMs(): int;
}
