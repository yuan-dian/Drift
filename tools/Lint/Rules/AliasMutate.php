<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * §16.6.3 #11 → §21 A12：同一个 Widget 被 mutate 两次（A12）。
 *
 * 判据：同一个 `children([...])` 数组里出现两次同一个根变量 ——
 * `children([$t->a(), $t->b()])`。链式写法 `mutate()` 原地改并 `return $this`（§15.2），
 * 所以第二处读到的是**已被第一处改过**的同一个实例：两个孩子共享一份配置，
 * 且改一处另一处跟着变。静默、难查、且不报错。
 *
 * ★ 只认**元素起点**的变量（紧跟 `[` 或 `,`），并把 `(...)` 深度压住：
 *   `Each::make(fn($u) => $u->name())` 里的 `$u` 出现在 `fn(` 之后，不是元素起点 ⇒ 不误响。
 * ★ 只扫 `children(`（§15.6.1 规定的批量子节点入口）；单子节点入口
 *   （`->child(...)`）不存在复用同一个根的问题。
 */
final class AliasMutate implements Rule
{
    public function name(): string
    {
        return 'alias-mutate';
    }

    public function serves(): string
    {
        return 'A12';
    }

    public function summary(): string
    {
        return '同一个 children([...]) 里两次用到同一根变量 ⇒ 链式原地改会互相污染';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue; // 工具跑在 php.exe 里、不进 AOT 产物 ⇒ 产物纪律不适用（同 nano-hygiene）
            }
            $tokens = $file->tokens();

            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                $tok = $tokens[$i];
                if (!is_array($tok) || $tok[0] !== T_STRING || strcasecmp($tok[1], 'children') !== 0) {
                    continue;
                }
                // 声明（`public function children(...)`）不是调用
                $prev = SourceQuery::prevIndex($tokens, $i - 1);
                if ($prev !== null && $tokens[$prev][0] === T_FUNCTION) {
                    continue;
                }
                $openI = SourceQuery::nextIndex($tokens, $i + 1);
                if ($openI === null || $tokens[$openI] !== '(') {
                    continue;
                }
                $argI = SourceQuery::nextIndex($tokens, $openI + 1);
                if ($argI === null || $tokens[$argI] !== '[') {
                    continue;
                }
                $closeI = SourceQuery::matchBracket($tokens, $argI);
                if ($closeI === null) {
                    continue;
                }

                foreach ($this->duplicateRoots($tokens, $argI, $closeI) as $hit) {
                    $found[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $file->path(),
                        $hit['line'],
                        sprintf(
                            "同一个 \$%s 在 children([...]) 里用了 %d 次 —— 链式 mutate() 原地改并 return \$this ⇒ 两个孩子共享一份配置（A12）",
                            $hit['name'],
                            $hit['count']
                        )
                    );
                }

                $i = $closeI;
            }
        }

        return $found;
    }

    /**
     * 数组字面量里，出现在**元素起点**的变量根；同一个根出现两次即报。
     *
     * @param list<mixed> $tokens
     * @return list<array{name: string, line: int, count: int}>
     */
    private function duplicateRoots(array $tokens, int $open, int $close): array
    {
        $seen = [];      // name => first line
        $dupes = [];     // name => [line, count]
        $arrDepth = 1;   // 已进入最外层数组
        $parenDepth = 0;
        $expectRoot = true;

        for ($i = $open + 1; $i < $close; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok)) {
                if ($tok[0] === T_WHITESPACE || $tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                    continue;
                }
                if ($expectRoot && $arrDepth === 1 && $parenDepth === 0 && $tok[0] === T_VARIABLE) {
                    $name = ltrim($tok[1], '$');
                    if (isset($seen[$name])) {
                        $dupes[$name] = ['line' => $tok[2], 'count' => ($dupes[$name]['count'] ?? 1) + 1];
                    } else {
                        $seen[$name] = $tok[2];
                    }
                    $expectRoot = false;
                }
                continue;
            }

            if ($tok === '[') {
                $arrDepth++;
                continue;
            }
            if ($tok === ']') {
                $arrDepth--;
                continue;
            }
            if ($tok === '(') {
                $parenDepth++;
                continue;
            }
            if ($tok === ')') {
                if ($parenDepth > 0) {
                    $parenDepth--;
                }
                continue;
            }
            if ($tok === ',' && $arrDepth === 1 && $parenDepth === 0) {
                $expectRoot = true;
            }
        }

        $out = [];
        foreach ($dupes as $name => $hit) {
            $out[] = ['name' => $name, 'line' => $hit['line'], 'count' => $hit['count']];
        }

        return $out;
    }
}
