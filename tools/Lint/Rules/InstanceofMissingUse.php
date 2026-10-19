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
    /** @var list<string> 语言结构关键字，不是类名 */
    private const KEYWORDS = ['self', 'static', 'parent'];

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
        // 小写短名 => FQN 列表（同名类在多个命名空间下都存在时全部列出）
        $byShort = [];
        foreach ($project->symbols() as $fqn => $_) {
            $short = strtolower(substr($fqn, (int) strrpos($fqn, '\\') + 1));
            $byShort[$short][] = $fqn;
        }
        $byLowerFqn = [];
        foreach ($project->symbols() as $fqn => $_) {
            $byLowerFqn[strtolower($fqn)] = $fqn;
        }

        $found = [];

        foreach ($project->files() as $file) {
            foreach ($this->instanceofNames($file->tokens()) as [$name, $line]) {
                $resolved = $file->resolveName($name);
                if ($this->visible($project, $byLowerFqn, $resolved)) {
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

    /**
     * 解析后的 FQN 是否"看得见"：精确命中，或大小写不敏感命中
     * （PHP 类名大小写不敏感，`use` 的大小写不必与声明一致）。
     */
    private function visible(Project $project, array $byLowerFqn, string $resolved): bool
    {
        return $project->symbol($resolved) !== null || isset($byLowerFqn[strtolower($resolved)]);
    }

    /**
     * 全部 instanceof 右侧的候选类名（短名 / 相对限定名）+ 行号。
     *
     * @param list<mixed> $tokens
     * @return list<array{0: string, 1: int}>
     */
    private function instanceofNames(array $tokens): array
    {
        $out = [];

        foreach ($tokens as $i => $tok) {
            if (!is_array($tok) || $tok[0] !== T_INSTANCEOF) {
                continue;
            }
            $nextI = SourceQuery::nextIndex($tokens, $i + 1);
            if ($nextI === null) {
                continue;
            }
            $next = $tokens[$nextI];
            if (!is_array($next)) {
                continue; // `$x instanceof $y`（变量右侧）不判
            }
            if ($next[0] !== T_STRING && $next[0] !== T_NAME_QUALIFIED) {
                continue; // 全限定名（写全了不会错）/ 其它形态不判
            }
            if (in_array(strtolower($next[1]), self::KEYWORDS, true)) {
                continue;
            }
            $out[] = [$next[1], $next[2]];
        }

        return $out;
    }
}
