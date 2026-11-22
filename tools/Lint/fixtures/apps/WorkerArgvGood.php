<?php
declare(strict_types=1);

namespace App;

use Drift\App\AppEntry;

/**
 * @internal lint 夹具：`worker-argv` 与 `app-worker-role` 共用的"不该响"一侧。
 *
 * 三件"看着像正解但不是错"的事一起钉住：
 *   ① 注释里提 `--drift-worker` 不算重拼开关（只有字符串字面量才报）；
 *   ② 应用继承 `AppEntry`、全文件不出现 `WorkerProcess` —— 角色交给基类，不该报；
 *   ③ 不写 `jobs()`：它默认空表（"用后台才写"要真能省，不是文档说说）。
 */
final class WorkerArgvGood extends AppEntry
{
    /** 角色由 `AppEntry::run()` 判，本文件不碰 argv，也不拼 --drift-worker。 */
    protected function start(): void
    {
        // 装配（夹具里留空）
    }
}
