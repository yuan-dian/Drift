<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * tpc 的**转换方法必须无参**（AOT 翻译层守卫）。
 *
 * ★ 真事故（2026-11-24）：`NetworkBodyRecord::toArray($part, $maxBytes)` 在解释器
 *   与 PHPUnit 下全绿，`php -l` 也过；但 tpc 翻译时直接 Fatal ——
 *   `Conversion method ... must not accept arguments`，整个 codegen 门红、
 *   637 个文件一个都没翻译。带参查询投影应另起名字（`select()`）。
 *
 * ★ 放在 lint 而不是只靠 `drift-codegen`：后者虽快（~30s）但仍是独立门，
 *   而转换方法是**语法形状**，词法层几行就能判，应该在内环立刻红。
 *
 * 覆盖 tpc 认的转换方法：`__toString` / `toArray` / `jsonSerialize`。
 */
final class ConversionMethod implements Rule
{
    /** @var list<string> */
    /** PHP 方法名大小写不敏感，判据统一按小写比对。 */
    private const array NAMES = ['__tostring', 'toarray', 'jsonserialize'];

    public function name(): string
    {
        return 'conversion-method-arity';
    }

    public function serves(): string
    {
        return 'AOT';
    }

    public function summary(): string
    {
        return '转换方法（`__toString` / `toArray` / `jsonSerialize`）不许带形参 —— tpc 翻译直接 Fatal，解释器全绿也救不了';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            $tokens = $file->tokens();
            $count = \count($tokens);
            for ($i = 0; $i < $count; ++$i) {
                $token = $tokens[$i];
                if (!\is_array($token) || $token[0] !== T_STRING
                    || !\in_array(\strtolower($token[1]), self::NAMES, true)) {
                    continue;
                }
                // 必须是 `function toArray` 这个位置，不是调用点 `$x->toArray()`。
                $previous = self::previousIndex($tokens, $i);
                if ($previous === null || $tokens[$previous][0] !== T_FUNCTION) {
                    continue;
                }
                $open = self::nextIndex($tokens, $i + 1);
                if ($open === null || $tokens[$open][0] !== '(') {
                    continue;
                }
                $after = self::nextIndex($tokens, $open + 1);
                // 单字符 token（`(`, `)`, `,`…）在 token_get_all 里是**字符串**不是数组。
                if ($after === null || $tokens[$after] === ')') {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $token[2],
                    \sprintf(
                        '转换方法 %s() 带形参 —— tpc 翻译时 Fatal「Conversion method must not accept arguments」；带预算的投影请另起方法名（如 select()）',
                        $token[1],
                    ),
                );
            }
        }

        return $found;
    }

    /**
     * @param array<int, array|string> $tokens
     */
    private static function nextIndex(array $tokens, int $from): ?int
    {
        $count = \count($tokens);
        for ($i = $from; $i < $count; ++$i) {
            if (self::meaningful($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<int, array|string> $tokens
     */
    private static function previousIndex(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; --$i) {
            if (self::meaningful($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /** @param array|string $token */
    private static function meaningful(array|string $token): bool
    {
        if (\is_string($token)) {
            return true; // `(`, `)`, `,` 等单字符 token
        }

        return $token[0] !== T_WHITESPACE
            && $token[0] !== T_COMMENT
            && $token[0] !== T_DOC_COMMENT;
    }
}
