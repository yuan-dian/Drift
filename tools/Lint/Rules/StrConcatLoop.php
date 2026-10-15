<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\SourceQuery;

/**
 * §16.6.3 #13 / §2.4 纪律 2：循环内文本拼接（G2）。
 *
 * 判据：循环体里出现
 *   ① `T_CONCAT_EQUAL`（`.=`）
 *   ② `$x = $x . …`（等号右边以**同一个变量**开头再接 `.`）
 * ⇒ error。理由不是"慢"：**增长累加器在多字节下会堆损坏，且崩在无关代码**（§2.4）。
 *    正确写法是数组push + `implode`。
 *
 * ★ 诚实的边界（两条，静态无从判断，别假装能判）：
 *   · **字符串是不是"多字节"是运行期性质** ⇒ 本规则对**一切**循环内拼接都响
 *     （保守方向：宁可多响，不可漏响 —— 漏一次就是一次堆损坏）。
 *   · 只认**花括号体**（`for () {}` / `foreach () {}` / `while () {}`）；
 *     无括号替代语法（`while (…) $x .= …;`）与 `endforeach` 形态不判。
 * ★ 豁免走既有机制：文件头写 `@internal-use-allowed: 理由` ⇒ 降为 warn，不另发明。
 */
final class StrConcatLoop implements Rule
{
    /** 循环关键字：括号之后必跟 `{` */
    private const LOOPS = [T_FOR, T_FOREACH, T_WHILE];

    public function name(): string
    {
        return 'str-concat-loop';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return '循环体内不许 .= / $x = $x . … 累加拼接（多字节下堆损坏，崩在无关代码）—— 一律 implode';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue; // 工具跑在 php.exe 里、不进 AOT 产物 ⇒ 产物纪律不适用（同 nano-hygiene）
            }

            $loops = $this->loopBodies($file->tokens());
            if ($loops === []) {
                continue;
            }

            foreach ($this->concats($file, $loops) as $hit) {
                $found[] = new Diagnostic(
                    $this->name(),
                    $file->hasInternalUseExemption() ? Diagnostic::WARN : Diagnostic::ERROR,
                    $file->path(),
                    $hit['line'],
                    sprintf(
                        '%s 在循环体内累加拼接 ⇒ 多字节下堆损坏，且崩在无关代码（§2.4 纪律 2）—— 改用数组 + implode',
                        $hit['form']
                    )
                );
            }
        }

        return $found;
    }

    /**
     * 全部循环体的 token 区间（**含嵌套**：外层体区间天然覆盖内层，重复无妨）。
     *
     * @param list<mixed> $tokens
     * @return list<array{start: int, end: int}>
     */
    private function loopBodies(array $tokens): array
    {
        $out = [];

        foreach ($tokens as $i => $tok) {
            if (!is_array($tok) || !in_array($tok[0], self::LOOPS, true)) {
                continue;
            }
            $openI = SourceQuery::nextIndex($tokens, $i + 1);
            if ($openI === null || $tokens[$openI] !== '(') {
                continue;
            }
            $afterParen = SourceQuery::matchBracket($tokens, $openI);
            if ($afterParen === null) {
                continue;
            }
            $braceI = SourceQuery::nextIndex($tokens, $afterParen + 1);
            if ($braceI === null || $tokens[$braceI] !== '{') {
                continue; // 替代语法（`…: … endfor;`）不判 —— 诚实边界
            }
            $closeI = SourceQuery::matchBracket($tokens, $braceI);
            if ($closeI !== null) {
                $out[] = ['start' => $braceI, 'end' => $closeI];
            }
        }

        return $out;
    }

    /**
     * 落在任一循环体内的拼接点。
     *
     * @param list<array{start: int, end: int}> $loops
     * @return list<array{form: string, line: int}>
     */
    private function concats(SourceFile $file, array $loops): array
    {
        $tokens = $file->tokens();
        $hits = [];

        foreach ($tokens as $i => $tok) {
            // ★ 快速路径过滤器：候选只有三种 —— T_CONCAT_EQUAL（数组）、`.`、裸 `=`。
            //   漏放 `=` 就会把"$x = $x . …"整条判据静默干掉（本仓踩过）。
            if (!is_array($tok) && $tok !== '.' && $tok !== '=') {
                continue;
            }

            $inside = false;
            foreach ($loops as $loop) {
                if ($i > $loop['start'] && $i < $loop['end']) {
                    $inside = true;
                    break;
                }
            }
            if (!$inside) {
                continue;
            }

            // ① `$x .= …`（T_CONCAT_EQUAL 是单 token，不是 `.` `=` 两个）
            if (is_array($tok) && $tok[0] === T_CONCAT_EQUAL) {
                $varI = SourceQuery::prevIndex($tokens, $i - 1);
                if ($varI !== null && $tokens[$varI][0] === T_VARIABLE) {
                    $hits[] = ['form' => $tokens[$varI][1] . ' .= …', 'line' => $tokens[$i][2]];
                }
                continue;
            }

            // ② `$x = $x . …`：等号（裸 CHAR，`==`/`===`/`=>`/`+=` 都是别的 token）
            if ($tok === '=') {
                $varI = SourceQuery::prevIndex($tokens, $i - 1);
                if ($varI === null || $tokens[$varI][0] !== T_VARIABLE) {
                    continue;
                }
                $rhsI = SourceQuery::nextIndex($tokens, $i + 1);
                if ($rhsI === null || $tokens[$rhsI][0] !== T_VARIABLE
                    || $tokens[$rhsI][1] !== $tokens[$varI][1]) {
                    continue;
                }
                $dotI = SourceQuery::nextIndex($tokens, $rhsI + 1);
                if ($dotI !== null && $tokens[$dotI] === '.') {
                    // ★ 行号取**左边变量**的（`=` 是单字符 token，没有 [2] —— 踩过）
                    $hits[] = ['form' => $tokens[$varI][1] . ' = ' . $tokens[$varI][1] . ' . …', 'line' => $tokens[$varI][2]];
                }
            }
        }

        return $hits;
    }
}
