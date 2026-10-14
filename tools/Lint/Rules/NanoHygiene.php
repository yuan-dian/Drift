<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\Tokens;

/**
 * §2.4 纪律 6 / §16.7：`--nano` 的禁用清单，作为源码纪律。
 *
 * ★ 诚实边界（§16.6.3）：这条规则只保证**源码纪律**，查不到 tpc 自己注入的
 *   include 或链接期问题。加了它 ≠ 能切 nano —— 最终判据只能是真机 `--nano` 构建。
 *   它的定位是"零成本保留将来可切的可选性"，不是"nano 就绪"的证明。
 */
final class NanoHygiene implements Rule
{
    /** 禁用函数：名字 => 展示名 */
    private const BANNED_FUNCTIONS = [
        'getenv' => 'getenv',
        'putenv' => 'putenv',
    ];

    /** 禁用类型（C++17 无栈切换，无法承载） */
    private const BANNED_TYPES = ['Generator', 'Fiber'];

    public function name(): string
    {
        return 'nano-hygiene';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return '禁用匿名类 / yield / Generator / Fiber / getenv / putenv / eval / include·require';
    }

    public function check(Project $project): array
    {
        $found = [];
        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue; // 工具跑在 php.exe 里，永不进 AOT 产物 ⇒ 不适用产物纪律
            }
            foreach ($this->scan($file) as $hit) {
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $hit['line'],
                    sprintf('禁用的构造：%s（§16.7）', $hit['what'])
                );
            }
        }
        return $found;
    }

    /** @return list<array{what: string, line: int}> */
    private function scan(SourceFile $file): array
    {
        $tokens = $file->tokens();
        $count = count($tokens);
        $hits = [];

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];

            if (!is_array($tok)) {
                if ($tok === '`') {
                    $hits[] = ['what' => '反引号 shell', 'line' => $this->lineAt($tokens, $i)];
                }
                continue;
            }

            [$id, $text, $line] = $tok;

            switch ($id) {
                case T_YIELD:
                case T_YIELD_FROM:
                    $hits[] = ['what' => 'yield', 'line' => $line];
                    break;
                case T_EVAL:
                    $hits[] = ['what' => 'eval', 'line' => $line];
                    break;
                case T_INCLUDE:
                case T_INCLUDE_ONCE:
                case T_REQUIRE:
                case T_REQUIRE_ONCE:
                    $hits[] = ['what' => strtolower($text), 'line' => $line];
                    break;
                case T_NEW:
                    $next = Tokens::next($tokens, $i + 1);
                    if ($next !== null && $next[0] === T_CLASS) {
                        $hits[] = ['what' => '匿名类', 'line' => $line];
                    }
                    break;
                case T_STRING:
                    $hit = $this->classifyString($tokens, $i, $text, $line);
                    if ($hit !== null) {
                        $hits[] = $hit;
                    }
                    break;
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                    $short = substr($text, (int) strrpos($text, '\\') + 1);
                    foreach (self::BANNED_TYPES as $type) {
                        if (strcasecmp($short, $type) === 0) {
                            $hits[] = ['what' => $type, 'line' => $line];
                            break;
                        }
                    }
                    break;
                default:
                    break;
            }
        }

        return $hits;
    }

    /** @return null|array{what: string, line: int} */
    private function classifyString(array $tokens, int $i, string $text, int $line): ?array
    {
        $lower = strtolower($text);
        $prev = Tokens::prev($tokens, $i - 1);
        $next = Tokens::next($tokens, $i + 1);

        if (isset(self::BANNED_FUNCTIONS[$lower])) {
            $isCall = $next !== null && $next[0] === '(';
            $isMember = $prev !== null && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
            if ($isCall && !$isMember) {
                return ['what' => self::BANNED_FUNCTIONS[$lower] . '()', 'line' => $line];
            }
            return null;
        }

        foreach (self::BANNED_TYPES as $type) {
            if (strcasecmp($text, $type) !== 0) {
                continue;
            }
            $asType = $prev !== null && in_array(
                $prev[0],
                [T_NEW, T_NS_SEPARATOR, T_IMPLEMENTS, T_EXTENDS, T_INSTANCEOF, T_FUNCTION],
                true
            );
            $asStatic = $next !== null && $next[0] === T_DOUBLE_COLON;
            if ($asType || $asStatic) {
                return ['what' => $type, 'line' => $line];
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private function lineAt(array $tokens, int $i): int
    {
        for ($n = $i; $n >= 0; $n--) {
            if (is_array($tokens[$n])) {
                return $tokens[$n][2];
            }
        }
        return 1;
    }
}
