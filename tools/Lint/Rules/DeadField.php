<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * 表格字段名拼写（G2）：`field('nmae')` 不该等到渲染期才发现。
 *
 * `TableColumn::textOf()` 在渲染到有数据的行时会对缺字段报 Fatal（带"现有字段"清单），
 * 但那已经晚了一整轮：表格恰好 0 行时连 Fatal 都没有，表现就是整列空白且无人报警。
 * 本规则把这条契约提前到静态期 —— **不替代**运行期 Fatal：运行期守的是"真实行数据
 * 里有没有这个键"，静态期守的是"这个名字在源码里有没有任何出处"。
 *
 * ★ 判据走独立路径，不读生成层、不猜数据流：
 *   证据集 = 同文件里作为「行字段」出现的单引号字面量，共四种可判形状：
 *     ① `TableColumn::make('key', …)` 的列身份；
 *     ② `'key' => …` 关联数组键（行数据本身）；
 *     ③ `$row['key']` 取用（排序 / keyOf 闭包里的读点）；
 *     ④ `const FIELDS = ['a','b']` 显式契约 —— 行数据来自外部、静态证据不足时的逃生口。
 *   `->field('x')` 的字面量必须 ∈ 证据集；否则它在这个文件里就是个"孤立名字"，
 *   拼错的概率远高于合法字段名。
 *
 * ★ 诚实边界（宁漏不误响）：
 *   - 只判单引号字面量单参调用。`field('c' . $i)` / `field(self::F_NAME)` 静态不可判 ⇒ 跳过；
 *     双引号可能有插值 ⇒ 跳过（与 `DeadCommand::singleQuoted()` 同口径）。
 *   - 同语句里必须出现 `TableColumn`，排除 `FormPage::field()` / `TextFieldState::field()`
 *     这些同名但无关的私有方法。
 *   - 证据集为空（文件里没有任何行字段出处）⇒ 整份不判，不拿"什么都不知道"去报红。
 *   - 若 `make()` 的 key 与 `field()` 一起拼错，① 会把它算成证据 ⇒ 漏报；
 *     这条路仍由运行期 Fatal 兜住，静态不假装能判。
 *   - 跳过 `tests/`：`tests/Support/fatal_cases.php` 故意写着 `field('nmae')` 作为 Fatal 用例，
 *     测试夹具的行数据也常只造部分字段；跳过 `tools/`（工具链不进 AOT 产物）。
 */
final class DeadField implements Rule
{
    public function name(): string
    {
        return 'dead-field';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return "TableColumn::field('x') 的字面量必须在同文件的行字段证据里，否则疑似拼错";
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if ($project->isToolchain($file) || str_starts_with($file->path(), 'tests/')) {
                continue;
            }

            $tokens = $file->tokens();
            $evidence = $this->fieldEvidence($tokens);
            if ($evidence === []) {
                continue;
            }

            foreach ($this->fieldCalls($tokens) as $hit) {
                if (isset($evidence[$hit['name']])) {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $hit['line'],
                    sprintf(
                        "死字段：field('%s') 的字段名在同文件没有任何行字段证据（可见字段：%s）"
                            . " ⇒ 拼错只有渲染到有数据的行才会在 TableColumn::textOf() 里报 Fatal，0 行时是静默空白",
                        $hit['name'],
                        implode(', ', array_keys($evidence)) ?: '（无）'
                    )
                );
            }
        }

        return $found;
    }

    /**
     * 同文件里「作为行字段出现的」字面量集合。
     *
     * @param list<mixed> $tokens
     * @return array<string, true>
     */
    private function fieldEvidence(array $tokens): array
    {
        $contracts = $this->fieldContracts($tokens);
        $out = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $name = self::singleQuoted($tokens[$i]);
            if ($name === null) {
                continue;
            }

            $prev = SourceQuery::prevIndex($tokens, $i - 1);
            $next = SourceQuery::nextIndex($tokens, $i + 1);

            // ② `'key' => …`
            if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_ARROW) {
                $out[$name] = true;
                continue;
            }
            // ③ `$row['key']`
            if ($prev !== null && $tokens[$prev] === '[' && $next !== null && $tokens[$next] === ']') {
                $out[$name] = true;
                continue;
            }
            // ① `TableColumn::make('key', …)`
            if ($this->isColumnKey($tokens, $i)) {
                $out[$name] = true;
                continue;
            }
            // ④ `const FIELDS = ['a','b']`
            foreach ($contracts as [$open, $close]) {
                if ($i > $open && $i < $close) {
                    $out[$name] = true;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * 字面量是不是 `TableColumn::make()` 的第一个参数。
     *
     * @param list<mixed> $tokens
     */
    private function isColumnKey(array $tokens, int $at): bool
    {
        $open = SourceQuery::prevIndex($tokens, $at - 1);
        if ($open === null || $tokens[$open] !== '(') {
            return false;
        }
        $method = SourceQuery::prevIndex($tokens, $open - 1);
        if ($method === null || !is_array($tokens[$method])
            || $tokens[$method][0] !== T_STRING || strcasecmp($tokens[$method][1], 'make') !== 0) {
            return false;
        }
        $colon = SourceQuery::prevIndex($tokens, $method - 1);
        if ($colon === null || !is_array($tokens[$colon]) || $tokens[$colon][0] !== T_DOUBLE_COLON) {
            return false;
        }
        $class = SourceQuery::prevIndex($tokens, $colon - 1);

        return $this->isColumnClass($tokens[$class] ?? null);
    }

    /** @param list<mixed> $tokens @return list<array{0: int, 1: int}> 常量数组的 [开, 闭] 区间 */
    private function fieldContracts(array $tokens): array
    {
        $out = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_CONST) {
                continue;
            }
            $nameI = SourceQuery::nextIndex($tokens, $i + 1);
            if ($nameI === null || !is_array($tokens[$nameI])
                || $tokens[$nameI][0] !== T_STRING || strcasecmp($tokens[$nameI][1], 'FIELDS') !== 0) {
                continue;
            }
            $eq = SourceQuery::nextIndex($tokens, $nameI + 1);
            if ($eq === null || $tokens[$eq] !== '=') {
                continue;
            }
            $open = SourceQuery::nextIndex($tokens, $eq + 1);
            if ($open === null || $tokens[$open] !== '[') {
                continue;
            }
            $close = SourceQuery::matchBracket($tokens, $open);
            if ($close !== null) {
                $out[] = [$open, $close];
            }
        }

        return $out;
    }

    /**
     * 收 `…->field('字面量')` 的调用点，且同语句里必须出现 `TableColumn`。
     *
     * @param list<mixed> $tokens
     * @return list<array{name: string, line: int}>
     */
    private function fieldCalls(array $tokens): array
    {
        $out = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $nameI = SourceQuery::nextIndex($tokens, $i + 1);
            if ($nameI === null || !is_array($tokens[$nameI])
                || $tokens[$nameI][0] !== T_STRING || strcasecmp($tokens[$nameI][1], 'field') !== 0) {
                continue;
            }
            $openI = SourceQuery::nextIndex($tokens, $nameI + 1);
            if ($openI === null || $tokens[$openI] !== '(') {
                continue;
            }
            $argI = SourceQuery::nextIndex($tokens, $openI + 1);
            $name = $argI === null ? null : self::singleQuoted($tokens[$argI]);
            if ($name === null) {
                continue; // 动态字段名：静态不可判
            }
            $closeI = SourceQuery::nextIndex($tokens, $argI + 1);
            if ($closeI === null || $tokens[$closeI] !== ')') {
                continue; // 多参数 ⇒ 不是 TableColumn::field()
            }
            if (!$this->statementHasColumn($tokens, $i)) {
                continue;
            }

            $out[] = ['name' => $name, 'line' => $tokens[$nameI][2]];
        }

        return $out;
    }

    /**
     * 从调用点回溯到本语句起点（`;` / `{` / `}`），中途见过 `TableColumn` 才算数。
     *
     * ★ 不以 `,` 为边界：列定义常写成 `return [ A()->field(…), B()->field(…) ];`，
     *   整条数组还在一个语句里。
     *
     * @param list<mixed> $tokens
     */
    private function statementHasColumn(array $tokens, int $at): bool
    {
        for ($k = $at - 1; $k >= 0; $k--) {
            $tok = $tokens[$k];
            if (!is_array($tok) && ($tok === ';' || $tok === '{' || $tok === '}')) {
                return false;
            }
            if ($this->isColumnClass($tok)) {
                return true;
            }
        }

        return false;
    }

    /** 短名 / 限定名 / 全限定名都以 `TableColumn` 结尾即可 @param mixed $tok */
    private function isColumnClass($tok): bool
    {
        if (!is_array($tok)) {
            return false;
        }
        if (!in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        return str_ends_with(strtolower($tok[1]), 'tablecolumn');
    }

    /** @param mixed $tok 单引号字面量（双引号可能有插值，与 DeadCommand 同口径） */
    private static function singleQuoted($tok): ?string
    {
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $text = $tok[1];
        if (!str_starts_with($text, "'") || !str_ends_with($text, "'")) {
            return null;
        }

        return substr($text, 1, -1);
    }
}
