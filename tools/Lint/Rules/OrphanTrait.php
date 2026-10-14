<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;

/**
 * §2.4 纪律 7：只含 trait 的文件不产出翻译单元（`cl` 收到空文件名）。
 *
 * 对策不是"记得别这么写"，而是让它在构建期响亮失败。
 */
final class OrphanTrait implements Rule
{
    public function name(): string
    {
        return 'orphan-trait';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return '一个文件只含 trait（不产出翻译单元）';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            $types = $file->types();
            if ($types === []) {
                continue;
            }
            foreach ($types as $type) {
                if ($type['kind'] !== SourceFile::KIND_TRAIT) {
                    continue 2; // 有具体类陪着，文件合法
                }
            }

            $found[] = new Diagnostic(
                $this->name(),
                Diagnostic::ERROR,
                $file->path(),
                $types[0]['line'],
                sprintf('文件只声明了 trait %s —— trait 必须与一个具体类同文件', $types[0]['name'])
            );
        }

        return $found;
    }
}
