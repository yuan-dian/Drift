<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 同 exe 双角色的分流：一次启动要么是 worker，要么是 UI 进程。
 *
 * ★ 本类是**全框架唯一读 `$_SERVER['argv']` 的地方**，也是开关名的唯一定义处
 *   （UI 侧拼子进程命令与 worker 侧认身份共用 `SWITCH`）。
 *
 * ★ **内部类，不进契约面**：消费者只有 `AppEntry::run()` 与
 *   `AppScope::useBackgroundJobs()`，都在框架内。应用引用它会被 `internal-leak`
 *   报 error —— 这是 `app-worker-role` 之外的第二层：那条只认常见写法，越界到
 *   `use ... as W` 这类形态时由这层兜住。
 *
 * ★ 本类注释刻意不出现契约面标注的字面量：解析器按文本匹配，写一次就会把本类
 *   算成公共面，上一条约束当场失效。
 */
final class WorkerProcess
{
    /** worker 角色开关（UI 拼命令、worker 认身份同源） */
    public const SWITCH = '--drift-worker';

    /** 本 exe 路径（AOT 下由启动器给）；空 ⇒ 调用方不装后台端口，不静默崩 */
    public static function executable(): string
    {
        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv) && isset($argv[0]) && is_string($argv[0]) ? $argv[0] : '';
    }

    /** 这次启动是不是 worker 角色 */
    public static function isWorker(): bool
    {
        $argv = $_SERVER['argv'] ?? [];
        if (!is_array($argv)) {
            return false;
        }
        foreach ($argv as $arg) {
            if ($arg === self::SWITCH) {
                return true;
            }
        }

        return false;
    }

    /**
     * worker 角色 ⇒ 起行协议循环（stdin 关才返回）。
     *
     * ★ 调用前必须自己 `isWorker()`：UI 进程调它会**阻塞在读 stdin 上**（控制台里
     *   表现为卡住，直到输入流关闭）。判定与执行的顺序由 `AppEntry::run()` 一处写死。
     *
     * @param array<string, callable(array<string, mixed>): Job> $registry
     */
    public static function serve(array $registry): void
    {
        ProcessWorker::serve($registry);
    }
}
