<?php
declare(strict_types=1);

/**
 * 冒烟用的 **worker 脚本**（§17.4.5 的最小形态）。
 *
 * ★ 生产形态是"同一份 AOT 产物的 `--drift-worker` 分支"（`$_SERVER['argv']` 分流）；
 *   这里用 `php.exe + 这个脚本` 是为了让 smoke 能测**真进程、真管道**，
 *   而不需要先构建一个 exe。
 * ★ 协议与 `ProcessWorker` 完全一致（同一份代码），所以测的是**生产路径**，
 *   区别只在"谁解释这段 PHP"。
 */

require dirname(__DIR__, 3) . '/tools/autoload.php';

\Drift\App\ProcessWorker::serve(\Drift\Dev\Smoke\App\SmokeJobRegistry::make());
