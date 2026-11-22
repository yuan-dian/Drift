<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * @internal lint 夹具桩：给 `apps/WorkerRoleBad.php` 的 `use` 一个可解析的目标。
 *
 * ★ 真实项目里 `Drift\App\WorkerProcess` 是内部类（无公共面标注）
 *   ⇒ 应用引用它会同时踩 `app-worker-role` 与 `internal-leak`。
 *   fixtures 项目本来没有这个符号，`isInternal()` 查不到就静默放过，
 *   "第二层兜底"这条期望便无法被证明 —— 所以要有个桩。
 */
final class WorkerProcess
{
    public static function isWorker(): bool
    {
        return false;
    }
}
