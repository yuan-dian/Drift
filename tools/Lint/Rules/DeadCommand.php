<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * §16.6.3 #5：命令双向可达（G2）。
 *
 * ① 全树 `command('x')` 发出的名字 ∈ 注册集；
 * ② 注册集里的名字**至少被一处发出**（死命令 + 死注册）。
 *
 * ★ 注册集的三个来源（静态可判的）：
 *   - `->on('x', $handler)` —— 字面量名 + 至少两个参数（排除其它用途的 `on($x)`）
 *   - `commands()` 方法体里作数组键的字面量（`Application::attach()` 的注册形态）
 *   - `->on((string) $name, …)` 这种**按变量**注册的形态静态不可判 ⇒ 既不进注册集，
 *     因此不会把"其实注册了"的命令误报成死命令（宁漏不误响）。
 * ★ 发出侧同样只收字面量：`->command('x')` / `->dispatch('x')`。
 */
final class DeadCommand implements Rule
{
    /** 发出命令的方法名 */
    private const EMITTERS = ['command', 'dispatch'];

    public function name(): string
    {
        return 'dead-command';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return "命令双向可达：command('x') 必须有人 on('x')，on('x') 也必须被发出";
    }

    public function check(Project $project): array
    {
        $emitted = [];     // name => list<array{file: string, line: int, via: string}>
        $registered = [];  // name => list<array{file: string, line: int, via: string}>

        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue; // 工具跑在 php.exe 里、不进 AOT 产物 ⇒ 产物纪律不适用（同 nano-hygiene）
            }
            $tokens = $file->tokens();

            foreach ($this->literalCalls($tokens, self::EMITTERS) as $name => $hits) {
                foreach ($hits as $hit) {
                    $emitted[$name][] = ['file' => $file->path(), 'line' => $hit[1], 'via' => $hit[0]];
                }
            }

            foreach ($this->literalCalls($tokens, ['on'], requireComma: true) as $name => $hits) {
                foreach ($hits as $hit) {
                    $registered[$name][] = ['file' => $file->path(), 'line' => $hit[1], 'via' => 'on()'];
                }
            }

            foreach (SourceQuery::classes($file) as $class) {
                $range = $class['methods']['commands'] ?? null;
                if ($range === null) {
                    continue;
                }
                foreach ($this->arrayKeys($tokens, $range['start'], $range['end']) as $key => $line) {
                    $registered[$key][] = ['file' => $file->path(), 'line' => $line, 'via' => 'commands()'];
                }
            }
        }

        $found = [];

        foreach ($emitted as $name => $hits) {
            if (isset($registered[$name])) {
                continue;
            }
            foreach ($hits as $hit) {
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $hit['file'],
                    $hit['line'],
                    sprintf("死命令 '%s'：全树没有 on('%s') 注册 ⇒ 点了没反应", $name, $name)
                );
            }
        }

        foreach ($registered as $name => $hits) {
            if (isset($emitted[$name])) {
                continue;
            }
            foreach ($hits as $hit) {
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $hit['file'],
                    $hit['line'],
                    sprintf("死注册 '%s'：%s 注册了它，但全树没有任何 command('%s') / dispatch('%s') 发出", $name, $hit['via'], $name, $name)
                );
            }
        }

        return $found;
    }

    /**
     * 收 `->method('字面量'` 的调用点。
     *
     * @param list<mixed> $tokens
     * @param list<string> $methods
     * @return array<string, list<array{0: string, 1: int}>> 名字 => [方法名, 行号]
     */
    private function literalCalls(array $tokens, array $methods, bool $requireComma = false): array
    {
        $wanted = array_map('strtolower', $methods);
        $out = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_STRING) {
                continue;
            }
            $lower = strtolower($tok[1]);
            if (!in_array($lower, $wanted, true)) {
                continue;
            }

            // 方法声明（`public function command(...)`）不是调用
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
            $name = self::singleQuoted($tokens[$argI]);
            if ($name === null) {
                continue;
            }
            if ($requireComma) {
                $after = SourceQuery::nextIndex($tokens, $argI + 1);
                if ($after === null || $tokens[$after] !== ',') {
                    continue; // 单参 `on($x)` 不是"名字 + handler"的注册形态
                }
            }

            $out[$name][] = [$tok[1], $tok[2]];
        }

        return $out;
    }

    /**
     * 方法体里作数组键的单引号字面量（`'ui.save' => …`）。
     *
     * @param list<mixed> $tokens
     * @return array<string, int> 名字 => 行号
     */
    private function arrayKeys(array $tokens, int $from, int $to): array
    {
        $out = [];
        for ($i = $from; $i < $to; $i++) {
            $name = self::singleQuoted($tokens[$i]);
            if ($name === null) {
                continue;
            }
            $next = SourceQuery::nextIndex($tokens, $i + 1);
            if ($next !== null && $next <= $to && $tokens[$next] === '=>' ) {
                $out[$name] = $tokens[$i][2];
            }
        }

        return $out;
    }

    /** @param mixed $tok */
    private static function singleQuoted($tok): ?string
    {
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $text = $tok[1];
        if (!str_starts_with($text, "'") || !str_ends_with($text, "'")) {
            return null; // 双引号可能带插值，不是可靠的名字
        }

        return substr($text, 1, -1);
    }
}
