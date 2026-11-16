<?php
declare(strict_types=1);

namespace App\Watermark\Jobs;

use Drift\App\Job;

/**
 * 任务注册表 —— **UI 侧与 worker 侧共用同一份**。
 *
 * ★ 它是"组合根的配置"：`Bootstrap` 里两条路径（正常窗口 / `--drift-worker`）
 *   都调 `make()`，所以两侧的名字不可能对不上。
 *
 * ## 加一个后台任务的完整动作
 *
 * 1. `Jobs/` 下加一个 `NamedJob` 类：`use JobArgs` ＋ `private const FIELDS = [...]`
 *    （顺序 == 构造参数顺序）＋ 构造器 ＋ `run()`；
 * 2. **本文件加一行** `XxxJob::NAME => static fn (array $a): Job => XxxJob::fromArgs($a),`
 * 3. 没了。
 *
 * ★ **参数搬运不在这里**：以前每族要手写 `(string) ($args['…'] ?? 默认)` 三五行，
 *   而它和 `args()` 是同一份信息的两遍抄写 —— 键名一分叉就是**静默**错
 *   （漏掉的参数在两侧都退化成默认值，只有真机 worker 里跑错参数）。现在连
 *   `args()` / `fromArgs()` 本身也不用写了：`FIELDS` 一份名单驱动两个方向
 *   （2026-11-16）。本文件只剩「名字 → 怎么造」。漏登记、名单与构造器不一致，
 *   都由 `NamedJobContractTest` 直接判红。
 */
final class JobRegistry
{
    /**
     * @return array<string, callable(array<string, mixed>): Job>
     */
    public static function make(): array
    {
        return [
            FetchSignJob::NAME => static fn (array $args): Job => FetchSignJob::fromArgs($args),
            UploadBatchJob::NAME => static fn (array $args): Job => UploadBatchJob::fromArgs($args),
            AddTaskJob::NAME => static fn (array $args): Job => AddTaskJob::fromArgs($args),
            PollResultJob::NAME => static fn (array $args): Job => PollResultJob::fromArgs($args),
            DownloadResultsJob::NAME => static fn (array $args): Job => DownloadResultsJob::fromArgs($args),
        ];
    }
}
