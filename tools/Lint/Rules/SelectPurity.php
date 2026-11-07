<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\ClassTable;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\SourceQuery;

/**
 * §16.6.3 #6：`select` 纯度（G2）。
 *
 * 判据：`select` 闭包的返回类型在 `===` 门控下要有稳定相等语义 ——
 * 标量 / enum / `array` / nullable / `readonly class` / `Equatable` 实现者；
 * 出现 `new` 非 readonly 对象 ⇒ 报错。
 *
 * 理由：`select` 的返回值决定"要不要重建"（§11.3.3 / §24.3 例外登记）。
 * 每次返回**新对象**且没有值相等契约 ⇒ `===` 永不相等 ⇒ 每帧重建。
 *
 * ★ 2026-11-07：`isClosure()` 原先只认 `fn` / `function`，全仓 24 处
 *   `static fn` 形态的 `select` 从来没进过判据 —— 夹具全绿是假绿。
 * ★ 诚实边界：闭包**没声明返回类型** / 声明 `mixed` 时静态无从判断 ⇒ 本规则不报
 *   （只治"声明了但类型不对"与"体内 new"两种可见形态）。目标类型在本仓库之外时同样不判。
 */
final class SelectPurity implements Rule
{
    /** 允许的标量返回类型 */
    private const SCALARS = ['int', 'float', 'string', 'bool'];

    /**
     * `array` 的 `===` 是「同键序 + 同类型 + 同值」，稳定值语义 ⇒ 放行。
     * ★ `mixed` / `null` / `true` / `false` 不在这里：它们本就查不到类表，
     *   走下面「仓库外类型不判」的既有诚实边界，重复登记只会让判据失去牙。
     * ★ 「array 不该响」由 `SelectFixture::staticShape()` 的 Good 形态钉住。
     */
    private const VALUE_SEMANTICS = ['array'];

    /** 一律不允许的返回类型关键词 */
    private const BANNED_KEYWORDS = [
        'object', 'callable', 'iterable', 'void', 'never',
        'self', 'static', 'parent',
    ];

    /**
     * §24.3 例外登记：框架保证「不可变 + 同一值复用同一实例」的值对象。
     * 它们的 `===` 天然稳定，不该因为不是 `readonly class` 被误报。
     */
    private const IDENTITY_STABLE = ['Drift\Async\AsyncValue'];

    public function name(): string
    {
        return 'select-purity';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return 'select 闭包返回类型必须是标量 / enum / readonly class，不许 new 非 readonly 对象';
    }

    public function check(Project $project): array
    {
        // ★ 全仓类表走 `ClassTable`（一个口径一个落点）：本规则只要 FQN ⇒ 类信息
        $all = ClassTable::fromProject($project)->all();

        $found = [];
        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue; // 工具跑在 php.exe 里、不进 AOT 产物 ⇒ 产物纪律不适用（同 nano-hygiene）
            }
            $tokens = $file->tokens();

            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                $tok = $tokens[$i];
                if (!is_array($tok) || $tok[0] !== T_STRING || strcasecmp($tok[1], 'select') !== 0) {
                    continue;
                }
                // 声明（`public function select(...)`）不是调用
                $prev = SourceQuery::prevIndex($tokens, $i - 1);
                if ($prev !== null && $tokens[$prev][0] === T_FUNCTION) {
                    continue;
                }
                $openI = SourceQuery::nextIndex($tokens, $i + 1);
                if ($openI === null || $tokens[$openI] !== '(') {
                    continue;
                }
                $argI = SourceQuery::nextIndex($tokens, $openI + 1);
                if ($argI === null) {
                    continue;
                }
                if (!$this->isClosure($tokens, $argI)) {
                    continue; // `[$this, 'm']` / `'strlen'` 等形态不判
                }

                $type = $this->closureReturnType($tokens, $argI);
                if ($type !== null) {
                    $problem = $this->typeProblem($type, $file, $all);
                    if ($problem !== null) {
                        $found[] = new Diagnostic(
                            $this->name(),
                            Diagnostic::ERROR,
                            $file->path(),
                            $tok[2],
                            sprintf("select 返回类型 `%s` %s（§11.3.3：只许标量 / enum / readonly class）", $type, $problem)
                        );
                    }
                }

                $closeI = SourceQuery::matchBracket($tokens, $openI);
                if ($closeI !== null) {
                    foreach ($this->newTargets($tokens, $argI, $closeI) as $hit) {
                        $fqn = $file->resolveName(ltrim($hit['name'], '\\'));
                        $info = $all[$fqn] ?? null;
                        if ($info === null) {
                            continue; // 仓库外类型：不判 readonly（诚实边界）
                        }
                        if (($info['kind'] ?? '') === 'class' && !($info['readonly'] ?? false)) {
                            $found[] = new Diagnostic(
                                $this->name(),
                                Diagnostic::ERROR,
                                $file->path(),
                                $hit['line'],
                                sprintf(
                                    'select 闭包里 new %s（%s 非 readonly）—— 每次返回新对象 ⇒ === 永不相等 ⇒ 每帧重建',
                                    $hit['name'],
                                    $fqn
                                )
                            );
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * 闭包的声明返回类型原文；没声明返回 null。
     *
     * @param list<mixed> $tokens
     */
    private function closureReturnType(array $tokens, int $closure): ?string
    {
        $depth = 0;
        $collecting = false;
        $text = '';

        for ($i = $closure, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok)) {
                if ($collecting) {
                    if ($tok[0] === T_DOUBLE_ARROW || $tok[0] === T_FN) {
                        break;
                    }
                    if (in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR, T_ARRAY, T_STATIC, T_CALLABLE], true)) {
                        $text .= $tok[1];
                        continue;
                    }
                    if ($tok[0] === T_WHITESPACE) {
                        continue;
                    }
                    break;
                }
                continue;
            }

            if ($tok === '(') {
                $depth++;
                continue;
            }
            if ($tok === ')') {
                $depth--;
                if ($depth < 0) {
                    break;
                }
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($tok === ':') {
                $collecting = true;
                continue;
            }
            if ($tok === '?' || $tok === '|' || $tok === '&') {
                if ($collecting) {
                    $text .= $tok;
                }
                continue;
            }
            if ($tok === '{' || $tok === ';') {
                break;
            }
        }

        $text = str_replace(' ', '', $text);

        return $text === '' ? null : $text;
    }

    /**
     * 闭包里 `new` 的目标类原文。
     *
     * @param list<mixed> $tokens
     * @return list<array{name: string, line: int}>
     */
    private function newTargets(array $tokens, int $from, int $to): array
    {
        $out = [];
        for ($i = $from; $i < $to; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_NEW) {
                continue;
            }
            $nameI = SourceQuery::nextIndex($tokens, $i + 1);
            if ($nameI === null || $nameI >= $to) {
                continue;
            }
            $nameTok = $tokens[$nameI];
            if (!in_array($nameTok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue; // `new (expr)` / 匿名类不在本规则范围
            }
            $out[] = ['name' => $nameTok[1], 'line' => $tok[2]];
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $all
     */
    private function typeProblem(string $type, SourceFile $file, array $all): ?string
    {
        if (str_contains($type, '|') || str_contains($type, '&')) {
            return '是联合/交叉类型，没有稳定的相等语义';
        }
        // nullable 不改变 `===` 的值语义（null 与任何值都严格可比）⇒ 只判底层类型
        $bare = ltrim(ltrim($type, '?'), '\\');
        $lower = strtolower($bare);
        if (in_array($lower, self::SCALARS, true) || in_array($lower, self::VALUE_SEMANTICS, true)) {
            return null;
        }
        if (in_array($lower, self::BANNED_KEYWORDS, true)) {
            return '不是标量 / enum / readonly class';
        }

        $fqn = $file->resolveName($bare);
        $info = $all[$fqn] ?? null;
        if ($info === null) {
            return null; // 仓库外类型：不判（诚实边界）
        }
        if (($info['kind'] ?? '') === 'enum') {
            return null;
        }
        if (($info['kind'] ?? '') === 'class') {
            if (($info['readonly'] ?? false) || in_array($fqn, self::IDENTITY_STABLE, true)) {
                return null;
            }
            // 值对象显式声明了值相等契约 ⇒ `SubjectBuilder` 会按值门控
            foreach ($info['implements'] ?? [] as $impl) {
                $short = ltrim((string) $impl, '\\');
                if ($short === 'Equatable' || str_ends_with($short, '\\Equatable')) {
                    return null;
                }
            }
        }

        return sprintf('指向 %s，它不是 enum / readonly class / Equatable', $fqn);
    }

    /**
     * @param list<mixed> $tokens
     */
    private function isClosure(array $tokens, int $i): bool
    {
        $tok = $tokens[$i];
        // `static fn` / `static function`：本仓 AOT 纪律鼓励 static，漏认会让规则假绿
        if (is_array($tok) && $tok[0] === T_STATIC) {
            $next = SourceQuery::nextIndex($tokens, $i + 1);
            if ($next === null) {
                return false;
            }
            $i = $next;
            $tok = $tokens[$i];
        }
        if (!is_array($tok)) {
            return false;
        }
        if ($tok[0] === T_FN) {
            return true;
        }
        if ($tok[0] === T_FUNCTION) {
            $next = SourceQuery::nextIndex($tokens, $i + 1);

            return $next !== null && $tokens[$next] === '(';
        }

        return false;
    }
}
