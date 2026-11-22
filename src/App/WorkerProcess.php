<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 同 exe 双角色的分流：一次启动要么是 worker，要么是 UI 进程。
 *
 * ★ 本类是**全框架唯一读 `$_SERVER['argv']` 的地方**。三个应用的组合根原先各抄
 *   一遍扫描（`$argv` 在 tpc 编译期被拒，只能走 `$_SERVER`），漏一个 `break` 或
 *   改错开关名就是"worker 跑成 UI"或反之，且都能静默起来。收在这里，开关名
 *   也只剩一处 —— UI 侧拼子进程命令与 worker 侧认身份共用同一个常量。
 *
 * @api
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
     * worker 角色 ⇒ 起行协议循环（stdin 关才返回）并给出 `true`；UI 角色 ⇒ `false`。
     *
     * ★ `serve()` 内部是阻塞循环，正常情况下不返回；返回 `true` 就是"这次是 worker，
     *   调用方到此为止"，不会往下建窗、进消息循环。
     *
     * @param array<string, callable(array<string, mixed>): Job> $registry
     */
    public static function serveIf(array $registry): bool
    {
        if (!self::isWorker()) {
            return false;
        }

        ProcessWorker::serve($registry);

        return true;
    }
}
