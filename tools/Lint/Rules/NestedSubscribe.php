<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\SourceQuery;

/**
 * §16.6.3 #9 / §11.3.2：item builder 里订阅 ⇒ 每行一个订阅者，notify 跑 N 次回调。
 * 粒度问题而非正确性问题（短列表合理），故只 warn。逐行 `Bind` 不在此列（A18）。
 *
 * ★ 只查第二参是闭包字面量的 `items(array, callable)`：菜单单参、getter 零参。
 *   不按类名闸，LazyColumn 等虚拟化列表自动进射程。
 * ★ builder 存进变量（`LazyGrid::build()` 那样）时看不见 ⇒ 漏报。
 */
final class NestedSubscribe implements Rule
{
    /** `select()` 随 SubjectBuilder 退役后只剩手写这一种 */
    private const ACTIONS = ['watch'];

    /** item builder 的入口方法名 */
    private const BUILDER = 'items';

    public function name(): string
    {
        return 'nested-subscribe';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return '->items() 的 builder 里不许订阅（每列表项一个订阅者，notify 一次跑 N 遍回调）';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue; // 工具跑在 php.exe 里、不进 AOT 产物 ⇒ 产物纪律不适用（同 nano-hygiene）
            }
            foreach ($this->builders($file) as $range) {
                foreach (SourceQuery::methodCalls($file, $range['start'], $range['end'], self::ACTIONS) as $hit) {
                    $found[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::WARN,
                        $file->path(),
                        $hit['line'],
                        sprintf(
                            '%s() 的 item builder 里调了 ->%s() ⇒ 每个列表项一个订阅者；notify 一次跑 N 遍回调（§11.3.2）',
                            self::BUILDER,
                            $hit['name']
                        )
                    );
                }
            }
        }

        return $found;
    }

    /**
     * 文件里每个"第二参是闭包字面量"的 `->items(` 调用的参数区间。
     *
     * @return list<array{start: int, end: int}>
     */
    private function builders(SourceFile $file): array
    {
        $tokens = $file->tokens();
        $out = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_STRING || $tok[1] !== self::BUILDER) {
                continue;
            }
            $arrowI = SourceQuery::prevIndex($tokens, $i - 1);
            if ($arrowI === null || !is_array($tokens[$arrowI]) || $tokens[$arrowI][0] !== T_OBJECT_OPERATOR) {
                continue; // `items(...)` 自由函数调用不算
            }
            $openI = SourceQuery::nextIndex($tokens, $i + 1);
            if ($openI === null || $tokens[$openI] !== '(') {
                continue;
            }
            $closeI = SourceQuery::matchBracket($tokens, $openI);
            if ($closeI !== null && $this->hasClosureBuilder($tokens, $openI, $closeI)) {
                $out[] = ['start' => $openI, 'end' => $closeI];
            }
        }

        return $out;
    }

    /**
     * 只数顶层逗号 —— 数组里的逗号属于第一参。
     *
     * @param list<mixed> $tokens
     */
    private function hasClosureBuilder(array $tokens, int $open, int $close): bool
    {
        $depth = 0;
        for ($i = $open + 1; $i < $close; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok)) {
                continue;
            }
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
                continue;
            }
            if ($tok === ')' || $tok === ']' || $tok === '}') {
                $depth--;
                continue;
            }
            if ($tok !== ',' || $depth !== 0) {
                continue;
            }

            $first = SourceQuery::nextIndex($tokens, $i + 1);
            if ($first !== null && $first < $close && is_array($tokens[$first]) && $tokens[$first][0] === T_STATIC) {
                $first = SourceQuery::nextIndex($tokens, $first + 1);
            }

            return $first !== null
                && $first < $close
                && is_array($tokens[$first])
                && in_array($tokens[$first][0], [T_FN, T_FUNCTION], true);
        }

        return false; // 单参（菜单 / 数据 getter）
    }
}
