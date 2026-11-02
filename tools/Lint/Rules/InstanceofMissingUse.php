<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\SourceQuery;

/**
 * instanceof 的短名必须在本文件可见（use 过 / 同命名空间）—— 否则**恒 false**。
 *
 * 背景：这是本仓咬过 ≥3 次的静默缺陷模式（ThemeChangedEvent 死分支、
 * SemanticsBuilder 去虚化、RenderFlex Stretch 从未执行）。共性不是"写错"，
 * 而是 **PHP 不报未导入类的名**：`instanceof ThemeChangedEvent` 在没 `use` 的
 * 文件里解析到 `当前命名空间\ThemeChangedEvent`，运行期恒 false、编译期无声，
 * 于是"声明了却从不执行"的分支能活到合并之后。
 *
 * 判据（三条，缺一不可）：
 *   ① instanceof 右侧是**短名**（`T_STRING`）或相对限定名（`T_NAME_QUALIFIED`）；
 *      全限定名写全了不会错，跳过。
 *   ② 解析（use 别名 / 同命名空间回退）后的 FQN **不在**本仓符号表；
 *   ③ 但该短名在本仓**某个命名空间下确实存在**。
 * ⇒ ②③ 同时成立 = 类就在仓里、只是这个文件看不到它 ⇒ 恒 false，报 error。
 *
 * ★ 诚实的边界：短名在整个项目都不存在（PHP 内建 / 第三方库）⇒ **不报** ——
 *   静态无从区分"漏 use"与"就用全局类"，宁漏勿误（误报会训练人忽略规则）。
 * ★ `self` / `static` / `parent` / 变量右侧（`$x instanceof $y`）不判。
 */
final class InstanceofMissingUse implements Rule
{
    public function name(): string
    {
        return 'instanceof-missing-use';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return 'instanceof 的短名类必须在本文件可见（use / 同命名空间）—— 否则恒 false，且不报错';
    }

    public function check(Project $project): array
    {
        $byShort = $project->symbolsByShortName();
        $found = [];

        foreach ($project->files() as $file) {
            foreach (SourceQuery::instanceofNames($file->tokens()) as [$name, $line]) {
                $resolved = $file->resolveName($name);
                if ($project->symbolFqcn($resolved) !== null) {
                    continue; // use 过 / 同命名空间 / 全局项目类 ⇒ 看得见
                }
                $cands = $byShort[strtolower($name)] ?? [];
                if ($cands === []) {
                    continue; // 内置 / 第三方 ⇒ 诚实的边界，不报
                }
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $line,
                    sprintf(
                        'instanceof %s 恒 false —— 本仓有该类（%s），但本文件既没 use、也不在它的命名空间 ⇒ 解析落到 %s。补 use 或写全限定名',
                        $name,
                        implode(' / ', $cands),
                        $resolved
                    )
                );
            }
        }

        return $found;
    }
}
