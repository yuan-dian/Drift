<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * AGENTS 铁律「中文串单引号」的机械版（G2）。
 *
 * 判据：变量名含非 ASCII 字节 ⇒ error。成因几乎只有一种 —— 双引号插值里
 * 变量紧跟中文，词法把中文吃进变量名（`"$n项"` ⇒ 变量 `$n项`）⇒ 静默取到
 * 未定义变量。正确写法：单引号拼接，或 `"{$n}项"`。
 *
 * ★ 不跳 toolchain：夹具本身住在 `tools/` 下，跳过就永远不响（本规则与产物纪律无关）。
 * ★ 无豁免分支：合法代码不会用非 ASCII 变量名，不需要 `@internal-use-allowed`。
 */
final class CjkVarName implements Rule
{
    public function name(): string
    {
        return 'cjk-var-name';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return '变量名不许含非 ASCII 字节 —— 双引号插值里变量紧跟中文会把中文吃进变量名（`"$n项"`）';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            foreach ($file->tokens() as $tok) {
                // `T_STRING_VARNAME` = `"${name}"` 里的 name
                if (!is_array($tok) || ($tok[0] !== T_VARIABLE && $tok[0] !== T_STRING_VARNAME)) {
                    continue;
                }
                if (preg_match('/[\x80-\xff]/', $tok[1]) !== 1) {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $tok[2],
                    sprintf(
                        '变量名 `%s` 含非 ASCII 字节 —— 多半是双引号插值把中文吃进了变量名，静默取到未定义变量；改用单引号拼接或 `"{$n}中文"`',
                        $tok[1]
                    )
                );
            }
        }

        return $found;
    }
}
