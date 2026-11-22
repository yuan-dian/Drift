<?php

declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * 应用侧不许自己判进程角色 —— `WorkerProcess` 只给 `AppEntry` 用。
 *
 * ★ 为什么还要这条（`worker-argv` 已经守住开关字面量了）：那条守的是"开关名被重抄"，
 *   这条守的是"角色判定被搬回应用层"。组合根写成
 *   `if (WorkerProcess::isWorker()) { ... }` 时开关名仍只有一处，闸门全绿，
 *   但应用又得理解"同一个 exe 跑两种角色"——正是这次收口要消掉的心智负担。
 *
 * 判据：`apps/` 下出现 `WorkerProcess` 标识符（`T_STRING` 与以 `\WorkerProcess`
 * 结尾的全限定名）。`use Drift\App\WorkerProcess;` 是 `T_NAME_QUALIFIED`，**不算**
 * —— 光 import 不用没有行为，报它只会逼人改成全限定写法。
 *
 * ★ 漏报面由 `internal-leak` 兜住：`WorkerProcess` 不是 `@api`，所以
 *   `use ... as W; W::isWorker()` 与相对限定名 `Drift\App\WorkerProcess::...`
 *   这类本规则认不出的形态，会以"引用内部类型"报 error。两层分工：本规则给业务向
 *   的修法（继承 `AppEntry`），那层守"不只是写法问题"的结构性越界。
 *
 * @api 闸门：`tools/lint.php`
 */
final class AppWorkerRole implements Rule
{
    /** 应用代码根（`apps/` 下的 PHP 文件） */
    private const APP_PATH = '#^apps/#';

    private const CLASS_NAME = 'WorkerProcess';

    public function name(): string
    {
        return 'app-worker-role';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return '应用不许自己判进程角色 —— `WorkerProcess` 只给 `AppEntry` 用；组合根继承 `AppEntry`，用 `jobs()` / `start()` 声明';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            $relative = $file->path();
            if (preg_match(self::APP_PATH, $relative) !== 1) {
                continue;
            }

            foreach ($file->tokens() as $token) {
                if (!is_array($token)) {
                    continue;
                }

                // T_STRING：`WorkerProcess::isWorker()`；T_NAME_FULLY_QUALIFIED：
                // `\Drift\App\WorkerProcess::isWorker()`。`use` 的 T_NAME_QUALIFIED 不算。
                if ($token[0] === T_STRING) {
                    $hit = $token[1] === self::CLASS_NAME;
                } elseif ($token[0] === T_NAME_FULLY_QUALIFIED) {
                    $hit = str_ends_with((string) $token[1], '\\' . self::CLASS_NAME);
                } else {
                    continue;
                }

                if (!$hit) {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $relative,
                    $token[2],
                    '应用自己判了进程角色 —— `WorkerProcess` 只给 `AppEntry::run()` 用，'
                        . '应用不需要知道"同一个 exe 跑两种角色"。'
                        . "\n" . '修法：组合根 `extends AppEntry`，`jobs()` 声明后台任务表、'
                        . '`start()` 写 UI 装配；`main.php` 只留 `(new Bootstrap())->run();`。',
                );
            }
        }

        return $found;
    }
}
