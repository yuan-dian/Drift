<?php
declare(strict_types=1);

/**
 * 解释器形态的 worker 入口（`php tools/app-workers/users-worker.php`）。
 *
 * ★ 生产形态是**同一个 exe** 的 `--drift-worker` 分支（`bin/win32/Bootstrap.php`）；
 *   这个脚本只是让 smoke / 探针能在不用构建 exe 的情况下跑**同一条协议**。
 * ★ 它在 `tools/` 下 ⇒ ① 不进 AOT 的 `sources`（工具链目录）② lint 的产物纪律不适用
 *   （跑在 php.exe 里，顶层执行代码 + require autoload）。
 */

require dirname(__DIR__) . '/autoload.php';

\Drift\App\ProcessWorker::serve(\App\Users\Jobs\JobRegistry::make());
