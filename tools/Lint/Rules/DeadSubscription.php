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
 * 判据：`build()` 体内出现对**可订阅类型**实例的方法调用
 * （`$this->user->value()`，而 `user` 声明为 `Subject`/`Cell`）
 * ⇒ 该 `build()` 所属类必须 `implements SubscribingWidget`。
 * 否则这个订阅会在重建时被静默丢弃（G2）。
 *
 * ★ 诚实的边界（静态不可判的那一半）：§16.6.3 还允许"位于某 `SubscribingWidget`
 *   子树内"——**子树不是 token 可见的事实**，所以本规则只做"本类必须实现"这一层。
 *   子树内正当使用的情形走既有豁免机制（文件里写 `@internal-use-allowed: 理由`）降为 warn，
 *   与 `internal-leak` 同一个口径，不另发明机制。
 * ★ `State` 子类豁免：cell 本来就归 `State` 拥有（`State::cell()`），
 *   在 `State::build()` 里读自己的 cell 是天经地义，不算死订阅。
 */
final class DeadSubscription implements Rule
{
    private const SUBSCRIBABLE = [
        'Drift\State\Subject',
        'Drift\State\Cell',
    ];

    private const SUBSCRIBING_WIDGET = 'Drift\State\SubscribingWidget';

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
        return 'build() 里读 Subject/Cell 的类必须 implements SubscribingWidget';
    }

    public function check(Project $project): array
    {
        $table = ClassTable::fromProject($project);
        $subscribing = $table->implementorsOf(self::SUBSCRIBING_WIDGET);
        $subscribable = $table->subtypesOf(self::SUBSCRIBABLE);

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
                if (isset($subscribing[$info['fqn']])) {
                    continue;
                }
                if ($table->descendsFrom($info['fqn'], self::STATE)) {
                    continue; // State 拥有自己的 cell，不算死订阅
                }

                foreach ($this->subjectCalls($file, $build, $info['properties'], $subscribable) as $hit) {
                    $found[] = new Diagnostic(
                        $this->name(),
                        $severity,
                        $file->path(),
                        $hit['line'],
                        sprintf(
                            "死订阅：build() 里读了 \$this->%s（%s 的方法），但 %s 没 implements SubscribingWidget ⇒ 重建会静默丢订阅",
                            $hit['property'],
                            $hit['type'],
                            $info['name']
                        )
                    );
                }
            }
        }

        return $found;
    }

    /**
     * 某类的 `build()` 体里，对"声明为可订阅类型"的属性发起的调用。
     *
     * @param array{start: int, end: int, line: int} $build
     * @param array<string, string> $properties
     * @param array<string, true> $subscribable
     * @return list<array{property: string, type: string, line: int}>
     */
    private function subjectCalls(SourceFile $file, array $build, array $properties, array $subscribable): array
    {
        $tokens = $file->tokens();
        $hits = [];

        for ($i = $build['start']; $i < $build['end']; $i++) {
            $tok = $tokens[$i];
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
            if ($type === null || !isset($subscribable[$type])) {
                continue;
            }
            $hits[] = ['property' => $prop, 'type' => $type, 'line' => $tokens[$thisI][2]];
        }

        return $hits;
    }

}
