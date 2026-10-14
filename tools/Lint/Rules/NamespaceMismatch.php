<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * §4.4 纪律 3：文件的 `namespace` 声明必须与 PSR-4 路径一致。
 *
 * 有它兜底，"目录移动不需要改 use"才成立 —— 移动后 lint 立刻指出漏改的声明。
 */
final class NamespaceMismatch implements Rule
{
    public function name(): string
    {
        return 'namespace-mismatch';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return 'namespace 声明与 composer.json 的 PSR-4 路径不符';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if ($file->types() === []) {
                continue; // PSR-4 管的是类文件；入口脚本（main.php / tools/*.php）不归它管
            }

            $expected = $file->expectedNamespace($project->dirToPrefix());
            if ($expected === null) {
                continue; // 不归任何 PSR-4 前缀管
            }
            $actual = $file->namespace();
            if ($actual === $expected) {
                continue;
            }

            $found[] = new Diagnostic(
                $this->name(),
                Diagnostic::ERROR,
                $file->path(),
                max(1, $file->namespaceLine()),
                sprintf('namespace 声明为 %s，PSR-4 路径要求 %s', $actual === '' ? '(全局)' : $actual, $expected)
            );
        }

        return $found;
    }
}
