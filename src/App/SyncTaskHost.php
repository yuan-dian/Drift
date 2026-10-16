<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * `TaskHost` 后端 B —— **同步三态**（§17.2.3，M1–M3 默认，永远可用）。
 *
 * ★ **它是"没有后台的后端"**：`run()` 就在**调用者线程**上跑完，`$onDone` 紧跟着
 *   同步调用 ⇒ 端口契约（"回调在 UI 线程"）**平凡成立**。
 *
 * ★ **诚实边界**（不假装异步）：同步直跑 ⇒ **`loading` 那一态对用户不可见**，
 *   三态里只有 `error` 一支真正可见（§11.5 已有同结论）。大响应时会**短暂停顿**。
 *   这是"同步直跑"的结构后果，**不要**靠"先广播一次 `Loading`"来假装异步 ——
 *   那只会让状态机撒谎。
 *
 * ★ **换后端是组合根一行的事**（§17.2.3）：
 *   ```php
 *   $src = new HttpUserSource(new SyncTaskHost());      // M1–M3：同步
 *   $src = new HttpUserSource(new ThreadPoolTaskHost()); // M4+：线程池（⛔ 当前 tpc 下不可实现）
 *   ```
 *   ⇒ 本类存在的意义是**把端口定下来**（页面 / Store / `Job` 零改动），不是"够用了"。
 *
 * @api
 */
final class SyncTaskHost implements TaskHost
{
    public function submit(Job $job, callable $onDone): void
    {
        $onDone($job->run());
    }
}
