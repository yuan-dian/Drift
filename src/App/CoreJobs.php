<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 框架自带任务的注册表 —— UI 侧 `ProcessTaskHost` 与 worker 侧 `ProcessWorker` 都从这里合并。
 *
 * 核心任务若交给各应用登记，N 个应用抄 N 遍；漏一个只在那个应用里静默失败
 * （网络图永远占位，没有任何报错）。合并点因此放在端口自己身上。
 */
final class CoreJobs
{
    /**
     * @param array<string, callable(array<string, mixed>): Job> $registry
     * @return array<string, callable(array<string, mixed>): Job>
     */
    public static function register(array $registry): array
    {
        if (!isset($registry[FetchImageJob::NAME])) {
            // 应用同名登记时以应用为准（允许覆盖核心实现）。
            $registry[FetchImageJob::NAME] = static fn(array $args): Job => FetchImageJob::fromArgs($args);
        }

        return $registry;
    }
}
