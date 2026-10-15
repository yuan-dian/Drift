<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\SourceQuery;

/**
 * §16.6.3 #9 / §2.4 纪律 10 / §11.3.2 纪律 2：订阅粒度过细（G2，**warn**）。
 *
 * 判据：`->items(…)` 的**参数区间**里出现 `->select(` / `->watch(`
 * ⇒ 每个列表项一个订阅者。1000 行列表 = 1000 个订阅者，
 *    任一 `notify` 跑 1000 次回调（§11.3.2）。
 *
 * ★ 为什么是 warn 不是 error：这是**粒度**问题，不是正确性问题 ——
 *   列表短的时候它完全合理。留痕、不挡路（两级严重度的本意）。
 * ★ 诚实的边界（三条，都是静态判不出来的，别假装能判）：
 *   · 匹配的是"文件里出现过 `Each` + 有 `->items(` 调用"，**不验证 receiver 真的
 *     是 `Each` 实例**（那要类型推断）⇒ 同名方法会误报，故降为 warn。
 *   · 只看**花括号**括号匹配；`items(…)` 的实参里嵌套闭包照样能穿透（括号配对
 *     与语义无关），但若 builder 是**命名函数**（`[$this, 'row']` / `'row'`），
 *     订阅藏在那个函数体里 ⇒ 本规则看不见。
 *   · `Each` 自身源码（`src/Widget/Each.php`）里不含 `->items(` 调用，不会自触发。
 */
final class NestedSubscribe implements Rule
{
    /** 订阅动作的方法名（小写）—— 与 `subscriber-mark` 同一份口径 */
    private const ACTIONS = ['select', 'watch'];

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
     * 文件里每个 `->items(` 调用的参数区间。
     *
     * ★ 前置条件：本文件出现过 `Each`（T_STRING）⇒ 挡掉绝大多数同名方法的误报。
     *   仍然不验证 receiver 类型 —— 那是 warn 而非 error 的理由之一。
     *
     * @return list<array{start: int, end: int}>
     */
    private function builders(SourceFile $file): array
    {
        $tokens = $file->tokens();

        $mentionsEach = false;
        foreach ($tokens as $tok) {
            if (is_array($tok) && $tok[0] === T_STRING && $tok[1] === 'Each') {
                $mentionsEach = true;
                break;
            }
        }
        if (!$mentionsEach) {
            return [];
        }

        $out = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_STRING || $tok[1] !== self::BUILDER) {
                continue;
            }
            $arrowI = SourceQuery::prevIndex($tokens, $i - 1);
            if ($arrowI === null || $tokens[$arrowI][0] !== T_OBJECT_OPERATOR) {
                continue; // `items(...)` 自由函数调用不算
            }
            $openI = SourceQuery::nextIndex($tokens, $i + 1);
            if ($openI === null || $tokens[$openI] !== '(') {
                continue;
            }
            $closeI = SourceQuery::matchBracket($tokens, $openI);
            if ($closeI !== null) {
                $out[] = ['start' => $openI, 'end' => $closeI];
            }
        }

        return $out;
    }
}
