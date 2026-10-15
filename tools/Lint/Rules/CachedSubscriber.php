<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * §16.6.3 #10 → §21 A4：订阅器被缓存（A4）。
 *
 * 判据：`SubjectBuilder` 实例被**赋给字段**（并因此跨 `build()` 复用）⇒ 报错。
 * `SubjectBuilder` 是"一次性的订阅表达式"：它在 `build()` 里当场求值、当场交给
 * `SubscribingWidget` 记账。存进字段 ⇒ 同一个实例被反复挂载 ⇒ 订阅泄漏 / 重复触发。
 *
 * ★ 诚实边界：静态只能看见两种形态 —— ① 属性**声明类型**是 `SubjectBuilder`；
 *   ② `$this->x = SubjectBuilder::make(…)` 的赋值。**无类型属性**被赋 builder
 *   看不见（PHP 之限，非本规则之懈）。
 */
final class CachedSubscriber implements Rule
{
    private const BUILDER = 'Drift\State\SubjectBuilder';

    public function name(): string
    {
        return 'cached-subscriber';
    }

    public function serves(): string
    {
        return 'A4';
    }

    public function summary(): string
    {
        return 'SubjectBuilder 实例不许赋给字段跨 build() 复用';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue; // 工具跑在 php.exe 里、不进 AOT 产物 ⇒ 产物纪律不适用（同 nano-hygiene）
            }
            $tokens = $file->tokens();

            foreach (SourceQuery::classes($file) as $class) {
                foreach ($class['properties'] as $prop => $type) {
                    if ($type !== self::BUILDER) {
                        continue;
                    }
                    $found[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $file->path(),
                        $this->propertyLine($file, $class, $prop),
                        sprintf(
                            '%s::$%s 声明为 SubjectBuilder —— 订阅表达式必须活在 build() 内，不许跨 build() 复用（A4）',
                            $class['name'],
                            $prop
                        )
                    );
                }

                foreach ($class['methods'] as $range) {
                    foreach ($this->builderAssignments($file, $range) as $prop => $line) {
                        $found[] = new Diagnostic(
                            $this->name(),
                            Diagnostic::ERROR,
                            $file->path(),
                            $line,
                            sprintf(
                                '$this->%s = SubjectBuilder… —— 订阅表达式必须活在 build() 内，不许存进字段（A4）',
                                $prop
                            )
                        );
                    }
                }
            }
        }

        return $found;
    }

    /**
     * 方法体里 `$this->x = SubjectBuilder::make(…)` / `new SubjectBuilder` 的赋值。
     *
     * @param array{start: int, end: int, line: int} $range
     * @return array<string, int> 属性名 => 行号
     */
    private function builderAssignments(\Drift\Dev\Lint\SourceFile $file, array $range): array
    {
        $tokens = $file->tokens();
        $out = [];

        for ($i = $range['start']; $i < $range['end']; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $prev = SourceQuery::prevIndex($tokens, $i - 1);
            if ($prev === null || $tokens[$prev][0] !== T_VARIABLE || $tokens[$prev][1] !== '$this') {
                continue;
            }
            // ★ `->` 之后的属性名是 **T_STRING**（不是 T_VARIABLE —— 只有 `$foo` 才是）
            $propI = SourceQuery::nextIndex($tokens, $i + 1);
            if ($propI === null || $tokens[$propI][0] !== T_STRING) {
                continue;
            }
            $eqI = SourceQuery::nextIndex($tokens, $propI + 1);
            if ($eqI === null || $eqI >= $range['end'] || $tokens[$eqI] !== '=') {
                continue;
            }
            $rhs = SourceQuery::nextIndex($tokens, $eqI + 1);
            if ($rhs === null || $rhs >= $range['end']) {
                continue;
            }

            $isBuilder = false;
            $rhsTok = $tokens[$rhs];
            if (is_array($rhsTok) && $rhsTok[0] === T_STRING && strcasecmp($rhsTok[1], 'SubjectBuilder') === 0) {
                $after = SourceQuery::nextIndex($tokens, $rhs + 1);
                $isBuilder = $after !== null && $tokens[$after][0] === T_DOUBLE_COLON;
            } elseif (is_array($rhsTok) && in_array($rhsTok[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $isBuilder = $file->resolveName($rhsTok[1]) === self::BUILDER;
            }

            if ($isBuilder) {
                // ★ 行号取自属性名 token：`=` 是单字符 token，取不到 [2]
                $out[strtolower($tokens[$propI][1])] = $tokens[$propI][2];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $class */
    private function propertyLine(\Drift\Dev\Lint\SourceFile $file, array $class, string $prop): int
    {
        $tokens = $file->tokens();
        for ($i = $class['bodyStart']; $i < $class['bodyEnd']; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok) && $tok[0] === T_VARIABLE
                && strcasecmp(ltrim($tok[1], '$'), $prop) === 0) {
                return $tok[2];
            }
        }

        return $class['line'];
    }
}
