<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;

/**
 * trait 单独成文件的纪律（§2.4 纪律 7；判据 2026-10-28 改写）。
 *
 * ## 原来守的是什么
 *
 * GUI 仓的坑：「只有 trait 的文件不产出翻译单元（`cl` 收到空文件名）」。
 * 当时的对策是**禁止 trait 单独成文件** —— 那是"绕过问题"而不是"表达本意"，
 * 而且把一条**可以实测**的假设写成了硬规则。
 *
 * ## 2026-10-28 实测：那条坑在今天的 tpc 上不复现
 *
 * `src/Widget/Traits/` 下 4 个只含 trait 的文件，一起走
 * `tools/drift-build.php showcase`：
 *
 * - 构建通过（12.2 MB，135s）；`_codegen/build/src/Widget/Widget.cc` 里能找到被
 *   内联进去的 trait 方法，**没有**为空文件产出翻译单元；
 * - 真机启动**逐像素一致**（`showcase.exe` 1920×1017 截图 md5 与拆分前完全相同）。
 *
 * ⇒ "不许单独成文件"这条前提消失了。但**它想防的事仍然存在**：
 *   一个没人 `use` 的 trait 既是死代码，又会让编译器为一个空文件白跑一遍。
 *
 * ## 现在的判据：**trait 必须被某个类 use**
 *
 * 判定用"类体内的 `use Name;`"（**短名**）。本仓约定 import 一律写全限定名
 * （`use Drift\…\X;`）⇒ 短名出现只可能是 trait 混入，不会与 import 混淆。
 * ★ 这是**结构性判据**：不枚举文件名、不维护白名单，新增 trait 自动纳入。
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
        return 'trait 单独成文件（必须被某个类 use，否则是死代码）';
    }

    public function check(Project $project): array
    {
        $found = [];

        /** @var list<array{0: string, 1: SourceFile, 2: int}> */
        $orphanFiles = [];
        $allCode = [];

        foreach ($project->files() as $file) {
            $allCode[] = $file->code();

            $types = $file->types();
            if ($types === []) {
                continue;
            }
            foreach ($types as $type) {
                if ($type['kind'] !== SourceFile::KIND_TRAIT) {
                    continue 2; // 有具体类陪着，这个文件本来就有翻译单元
                }
            }
            foreach ($types as $type) {
                $orphanFiles[] = [$type['name'], $file, $type['line']];
            }
        }

        $joined = implode("\n", $allCode);

        foreach ($orphanFiles as [$traitName, $file, $line]) {
            // ★ 行首（可带缩进）必须是 `use`：注释掉的 `// use X;` 不算混入。
            //   第一版用 `(^|[\s;{])use\s+X;` ⇒ 注释行也命中 ⇒ 规则**零牙齿**
            //   （反例自检时把 `use WidgetSemantics;` 注释掉照样 0 error）。
            if (preg_match('/^[ \t]*use[ \t]+' . preg_quote($traitName, '/') . '[ \t]*;/m', $joined) === 1) {
                continue;
            }

            $found[] = new Diagnostic(
                $this->name(),
                Diagnostic::ERROR,
                $file->path(),
                $line,
                sprintf(
                    'trait %s 没有任何类 use —— 它是死代码，且编译器要为这个空文件白跑一遍。'
                    . '修法：从某个类里 `use %s;`，或删掉这个 trait',
                    $traitName,
                    $traitName
                )
            );
        }

        return $found;
    }
}
