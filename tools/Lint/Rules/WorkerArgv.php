<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * worker 开关 `--drift-worker` 只许在 `WorkerProcess` 一处拼（进程角色分流收口）。
 *
 * ★ 真事故的形状：三份组合根曾各抄一遍 argv 扫描 + 开关名 + `ProcessWorker::serve()`，
 *   逐字相同。漏一个 `break`、把开关拼错、或在第四份里改了名，症状是"worker 跑成 UI"
 *   或"UI 跑成 worker"，两者都不报错 —— worker 建窗会卡住、UI 不建窗则一个任务也跑不了。
 *   2026-11-22 收进 `WorkerProcess` 后，这条闸门守住"不许再抄第四份"。
 *
 * 判据：PHP 字符串字面量（`T_CONSTANT_ENCAPSED_STRING`）里出现 `--drift-worker`。
 * - 注释里提到不算（`T_COMMENT` / `T_DOC_COMMENT` 是文档，四处 Bootstrap 都改过注释）；
 * - `.py` / `.cc` 探针按进程名计 worker 数，**不归本规则管**（本仓只扫 PHP）。
 *
 * 豁免（精确路径，不按文件名通配）：
 * - `src/App/WorkerProcess.php`：原语自身，`SWITCH` 常量在这里定义；
 * - `tests/Unit/App/WorkerProcessTest.php`：判据要断言常量值，必须写出字面量；
 * - `tools/Lint/fixtures/`：本规则的夹具（Bad 侧故意写它，见 selftest）。
 *
 * @api 闸门：`tools/lint.php`
 */
final class WorkerArgv implements Rule
{
    private const SWITCH = '--drift-worker';

    /** 允许写出开关字面量的文件（相对仓根，路径结尾匹配） */
    private const ALLOWED = [
        'src/App/WorkerProcess.php',
        'tests/Unit/App/WorkerProcessTest.php',
        'tools/Lint/Rules/WorkerArgv.php', // 规则自身定义了 SWITCH 常量与诊断文案
        'tools/Lint/fixtures/',
    ];

    public function name(): string
    {
        return 'worker-argv';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return 'worker 开关 --drift-worker 只许在 WorkerProcess 一处拼 —— 禁止第四份 argv 扫描';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            $relative = $file->path();
            foreach (self::ALLOWED as $allowed) {
                if (str_ends_with($relative, $allowed)) {
                    continue 2;
                }
            }

            foreach ($file->tokens() as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                if (!str_contains((string) $token[1], self::SWITCH)) {
                    continue;
                }
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $relative,
                    $token[2],
                        'worker 开关 --drift-worker 在这里被重新拼了一遍 —— 角色分流的 argv 扫描'
                        . '只许在 `Drift\\App\\WorkerProcess` 一处。'
                        . '修法：组合根继承 `AppEntry`（角色判定由基类做），本文件删掉整段扫描；'
                        . '后台任务表用 `jobs()` 声明，UI 侧建池用 `AppScope::useBackgroundJobs()`。',
                );
            }
        }

        return $found;
    }
}
