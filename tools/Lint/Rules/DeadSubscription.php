<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\ClassTable;
use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\SourceQuery;

/**
 * §16.6.3 #4 → §21 A16：死订阅（A16）。
 *
 * 判据：`build()` 体内调用**不经过采集**的可观察值方法
 * （`$this->session->isOpen()`，而 `session` 声明为 `Subject` 子类型且不是 `Readable`）
 * ⇒ 读了一次却没订上：数据变了不标脏，界面静默停在旧值。
 *
 * ★ **`Cell` / `AsyncCommand` 不在射程内**：它们的 `get()` 内部
 *   `Tracker::record()`，读点由最近的 `StatefulElement` 采集帧建边 ——
 *   在 `StatelessWidget::build()` 里读也照样生效（代价是重建面变宽，不是陈旧）。
 *   本规则只管"读了但没人记账"的那一半。2026-11-24 收窄：此前把 `Cell` 一起判，
 *   报错文案「重建会静默丢订阅」与事实相反，而当时的"豁免"是 `implements
 *   SubscribingWidget` —— 一个 `StatelessWidget` 根本没有订阅袋，那句承诺做不到。
 * ★ 诚实的边界（静态不可判的那一半）：读点真的落在某 `State` 的 `watch()` 回调里
 *   是正当的，但"这行在回调里"不是 token 可见的事实。这类形态走既有豁免机制
 *   （文件里写 `@internal-use-allowed: 理由`）降为 warn，与 `internal-leak` 同一个口径。
 * ★ 闭包体不判：`onPressed(fn() => $this->form->reset())` 是**延迟回调**（事件发生才读），
 *   `builder(fn() => …)` 是**立即回调**，两者 token 同形 ⇒ 静态不可判，一律不判。
 *   代价是真在 builder 回调里读会漏报 —— 那种写法该改用 `Bind` 的入参。
 * ★ `State` 子类豁免：手写订阅的正路就是 `State::watch()`，
 *   在 `State::build()` 里读自己的源天经地义，不算死订阅。
 */
final class DeadSubscription implements Rule
{
    /** 可观察值的根：家族里**没实现 `Readable`** 的那些才是本规则的目标 */
    private const SUBJECT = 'Drift\State\Subject';

    /** 读它会 `Tracker::record` ⇒ 自动成边，不在射程内 */
    private const READABLE = 'Drift\Core\Readable';

    private const STATE = 'Drift\Widget\State';

    public function name(): string
    {
        return 'dead-subscription';
    }

    public function serves(): string
    {
        return 'A16';
    }

    public function summary(): string
    {
        return 'build() 里读不经过采集的 Subject（非 Readable）⇒ 变了不标脏，改用 Cell 或 Bind';
    }

    public function check(Project $project): array
    {
        $table = ClassTable::fromProject($project);
        $silent = $this->silentSubjects($table);

        $found = [];
        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue; // 工具跑在 php.exe 里、不进 AOT 产物 ⇒ 产物纪律不适用（同 nano-hygiene）
            }
            $severity = $file->hasInternalUseExemption() ? Diagnostic::WARN : Diagnostic::ERROR;

            foreach ($table->byFile()[$file->path()] ?? [] as $info) {
                $build = $info['methods']['build'] ?? null;
                if ($build === null) {
                    continue;
                }
                if ($table->descendsFrom($info['fqn'], self::STATE)) {
                    continue; // State 拥有自己的源，且 watch() 就是在这层用的
                }

                foreach ($this->subjectCalls($file, $build, $info['properties'], $silent) as $hit) {
                    $found[] = new Diagnostic(
                        $this->name(),
                        $severity,
                        $file->path(),
                        $hit['line'],
                        sprintf(
                            '死订阅：build() 里读了 $this->%s->%s()（%s 是 Subject 但不是 Readable）'
                            . ' ⇒ 读点不经过 Tracker，数据变了不标脏；改用 Cell（读即订阅），或把 readonly 参数交给 Bind',
                            $hit['property'],
                            $hit['method'],
                            $hit['type']
                        )
                    );
                }
            }
        }

        return $found;
    }

    /**
     * `Subject` 家族里**不会自动记账**的那些类。
     *
     * ★ 挡掉 `Readable` 是本规则正确的关键：`Cell::get()` / `AsyncCommand::get()`
     *   自己 `Tracker::record()`，读点由框架建边；`Subject` 的其余快照方法
     *   （`isOpen()` / `query()` 之类）不经过 `Tracker`。
     *
     * @return array<string, true>
     */
    private function silentSubjects(ClassTable $table): array
    {
        $out = [];
        foreach ($table->subtypesOf([self::SUBJECT]) as $fqn => $_) {
            if (!$table->descendsFrom($fqn, self::READABLE, viaImplements: true)) {
                $out[$fqn] = true;
            }
        }

        return $out;
    }

    /**
     * 某类的 `build()` 体里，对"声明为不自动记账的可观察值"的属性发起的调用。
     *
     * @param array{start: int, end: int, line: int} $build
     * @param array<string, string> $properties
     * @param array<string, true> $silent
     * @return list<array{property: string, type: string, method: string, line: int}>
     */
    private function subjectCalls(SourceFile $file, array $build, array $properties, array $silent): array
    {
        $tokens = $file->tokens();
        $hits = [];

        for ($i = $build['start']; $i < $build['end']; $i++) {
            $tok = $tokens[$i];

            // 闭包体不判：可能是 `onPressed` 这类**延迟回调**（事件发生时才读），
            // 也可能被 `builder()` 立即回调 —— 静态不可判。判了就是误报。
            if (is_array($tok) && ($tok[0] === T_FN || $tok[0] === T_FUNCTION)) {
                $end = $this->closureEnd($tokens, $i, $build['end']);
                if ($end !== null) {
                    $i = $end;
                }
                continue;
            }

            if (!is_array($tok) || $tok[0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            // `$this` `->` `$prop` `->` `method` `(`
            $thisI = SourceQuery::prevIndex($tokens, $i - 1);
            if ($thisI === null || $tokens[$thisI][0] !== T_VARIABLE || $tokens[$thisI][1] !== '$this') {
                continue;
            }
            // ★ `->` 之后的属性名是 **T_STRING**（不是 T_VARIABLE —— 只有 `$foo` 才是）
            $propI = SourceQuery::nextIndex($tokens, $i + 1);
            if ($propI === null || $tokens[$propI][0] !== T_STRING) {
                continue;
            }
            $prop = strtolower($tokens[$propI][1]);
            $arrowI = SourceQuery::nextIndex($tokens, $propI + 1);
            if ($arrowI === null || $tokens[$arrowI][0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $methodI = SourceQuery::nextIndex($tokens, $arrowI + 1);
            if ($methodI === null || $tokens[$methodI][0] !== T_STRING) {
                continue;
            }
            $callI = SourceQuery::nextIndex($tokens, $methodI + 1);
            if ($callI === null || $tokens[$callI] !== '(') {
                continue;
            }

            $type = $properties[$prop] ?? null;
            if ($type === null || !isset($silent[$type])) {
                continue;
            }
            $hits[] = [
                'property' => $prop,
                'type' => $type,
                'method' => strtolower($tokens[$methodI][1]),
                'line' => $tokens[$thisI][2],
            ];
        }

        return $hits;
    }

    /**
     * 闭包体的结束下标（`fn` 箭头 / 匿名 `function`）。找不到 ⇒ null。
     *
     * @param list<mixed> $tokens
     */
    private function closureEnd(array $tokens, int $at, int $limit): ?int
    {
        if ($tokens[$at][0] === T_FUNCTION) {
            for ($k = $at + 1; $k < $limit; $k++) {
                $tok = $tokens[$k];
                if (is_array($tok)) {
                    continue;
                }
                if ($tok === '{') {
                    return SourceQuery::matchBracket($tokens, $k);
                }
                if ($tok === ';') {
                    return $k;
                }
            }
            return null;
        }

        // `fn` 的参数表先整体跳过：里面的 `,` / `)` 不是体的边界
        $paren = null;
        for ($k = $at + 1; $k < $limit; $k++) {
            $tok = $tokens[$k];
            if (!is_array($tok) && $tok === '(') {
                $paren = $k;
                break;
            }
            if (!is_array($tok) && ($tok === ',' || $tok === ';')) {
                return $k; // 不是完整闭包，就地收手
            }
        }
        if ($paren === null) {
            return null;
        }
        $afterParen = SourceQuery::matchBracket($tokens, $paren);
        if ($afterParen === null) {
            return null;
        }

        // 体从 `=>` 之后开始
        $arrow = null;
        for ($k = $afterParen + 1; $k < $limit; $k++) {
            $tok = $tokens[$k];
            if (is_array($tok)) {
                if ($tok[0] === T_DOUBLE_ARROW) {
                    $arrow = $k;
                    break;
                }
                continue;
            }
            if ($tok === ',' || $tok === ')' || $tok === ';') {
                return $k;
            }
        }
        if ($arrow === null) {
            return null;
        }

        $depth = 0;
        for ($k = $arrow + 1; $k < $limit; $k++) {
            $tok = $tokens[$k];
            if (is_array($tok)) {
                continue;
            }
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
                continue;
            }
            if ($tok === ')' || $tok === ']' || $tok === '}') {
                if ($depth === 0) {
                    return $k;
                }
                $depth--;
                continue;
            }
            if ($tok === ',' && $depth === 0) {
                return $k;
            }
        }

        return null;
    }

}
